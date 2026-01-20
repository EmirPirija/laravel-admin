<?php

namespace App\Services;

use App\Models\Item;
use App\Models\UserPurchasedPackage;
use App\Models\User;
use App\Models\UserFcmToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ExpiringItemService
{
    /**
     * Send notifications for expiring items and packages.
     */
    public function notifyExpiringItemsAndPackages()
    {
        $this->notifyExpiringItems();
        $this->notifyExpiringPackages();
    }

    /**
     * Send notifications for expiring items.
     */
    public function notifyExpiringItems()
    {
        $twoDaysFromNow = Carbon::now()->addDays(2)->startOfDay();
        $items = Item::whereDate('expiry_date', '=', $twoDaysFromNow)
            ->where('status', 'approved')
            ->get();
        $skippedCount = 0;

        foreach ($items as $item) {
            $user = $item->user;
            if ($user && $user->email) {
                $this->sendNotification($user, $item);
            } else {
                $skippedCount++;
            }
        }

        if ($skippedCount > 0) {
            Log::warning("Skipped {$skippedCount} item notifications due to missing user or email");
        }
    }

    /**
     * Send notifications for expiring packages.
     */
    public function notifyExpiringPackages()
    {
        $twoDaysFromNow = Carbon::now()->addDays(2)->startOfDay();
        $packages = UserPurchasedPackage::whereDate('end_date', '=', $twoDaysFromNow)->get();
        $skippedCount = 0;

        foreach ($packages as $package) {
            $user = $package->user;
            $packageDetails = $package->package;

            if ($user && $user->email && $packageDetails) {
                $this->sendPackageNotification($user, $packageDetails, $package);
            } else {
                $skippedCount++;
            }
        }

        if ($skippedCount > 0) {
            Log::warning("Skipped {$skippedCount} package notifications due to missing user, email, or package details");
        }
    }

    /**
     * Send email + push notification for expiring items.
     */
    protected function sendNotification(User $user, $item)
    {
        try {
            $expiryDate = Carbon::parse($item->expiry_date)->format('d M Y');
            $message = "Your advertisement '{$item->name}' is expiring on {$expiryDate}. Please take action before it expires.";

            // ✅ Send Email
            Mail::raw(
                "Hello {$user->name},\n\n{$message}",
                function ($msg) use ($user) {
                    $msg->to($user->email)
                        ->from('admin@yourdomain.com', 'Admin')
                        ->subject('Advertisement Expiring Soon');
                }
            );

            // ✅ Send Push Notification
            $user_tokens = UserFcmToken::where('user_id', $user->id)->pluck('fcm_token')->toArray();

            if (!empty($user_tokens)) {
                $fcmMsg = [
                    'item_id' => $item->id,
                    'type' => 'item_expiry',
                    'expiry_date' => $item->expiry_date,
                ];

                NotificationService::sendFcmNotification(
                    $user_tokens,
                    'Advertisement Expiring Soon',
                    $message,
                    'item_expiry',
                    $fcmMsg
                );
            }

            Log::info("Expiry notification sent to: {$user->email} for Advertisement: {$item->name}");

        } catch (\Exception $e) {
            Log::error("Failed to send notification for Advertisement {$item->id}: " . $e->getMessage());
        }
    }

    /**
     * Send email + push notification for expiring packages.
     */
    protected function sendPackageNotification(User $user, $package, $userPackage)
    {
        try {
            $expiryDate = Carbon::parse($userPackage->end_date)->format('d M Y');
            $message = "Your subscription package '{$package->name}' is expiring on {$expiryDate}. Please renew or upgrade your subscription.";

            // ✅ Send Email
            Mail::raw(
                "Hello {$user->name},\n\n{$message}",
                function ($msg) use ($user) {
                    $msg->to($user->email)
                        ->from('admin@yourdomain.com', 'Admin')
                        ->subject('Package Expiring Soon');
                }
            );

            // ✅ Send Push Notification
            $user_tokens = UserFcmToken::where('user_id', $user->id)->pluck('fcm_token')->toArray();

            if (!empty($user_tokens)) {
                $fcmMsg = [
                    'package_id' => $package->id,
                    'type' => 'package_expiry',
                    'expiry_date' => $userPackage->end_date,
                ];

                NotificationService::sendFcmNotification(
                    $user_tokens,
                    'Package Expiring Soon',
                    $message,
                    'package_expiry',
                    $fcmMsg
                );
            }

            Log::info("Package expiry notification sent to: {$user->email} for package: {$package->name}");

        } catch (\Exception $e) {
            Log::error("Failed to send notification for Package {$userPackage->id}: " . $e->getMessage());
        }
    }
}
