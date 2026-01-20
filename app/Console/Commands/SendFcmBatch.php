<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UserFcmToken;
use App\Services\NotificationService;

class SendFcmBatch extends Command
{
    protected $signature = 'send:fcm-batch {data}';
    protected $description = 'Send FCM notifications in batch from background';

    public function handle()
    {
        $data = json_decode($this->argument('data'), true);

        $title = $data['title'] ?? '';
        $message = $data['message'] ?? '';
        $type = $data['type'] ?? 'notification';
        $customBodyFields = $data['customBodyFields'] ?? [];
        $sendToAll = $data['sendToAll'] ?? false;
        $userIds = $data['userIds'] ?? [];

        $this->info("🔔 Sending FCM notifications...");

        // ✅ If sendToAll = true
        if ($sendToAll) {
            // Fetch tokens with user preference
            $tokens = UserFcmToken::with('user')
                ->whereHas('user', fn($q) => $q->where('notification', 1))
                ->get(['fcm_token', 'platform_type']);

            // Split tokens by platform
            $androidIosTokens = $tokens->whereIn('platform_type', ['Android', 'iOS'])->pluck('fcm_token')->toArray();
            $otherTokens = $tokens->whereNotIn('platform_type', ['Android', 'iOS'])->pluck('fcm_token')->toArray();

            // ✅ Send Android/iOS via Topic
            if (!empty($androidIosTokens)) {
                NotificationService::sendFcmNotification(
                    [], $title, $message, $type, $customBodyFields, true
                );
                $this->info("📱 Topic-based notification sent to Android/iOS users.");
            }

            // ✅ Send Others via Chunk (if any)
            if (!empty($otherTokens)) {
                collect($otherTokens)->chunk(500)->each(function ($chunk) use ($title, $message, $type, $customBodyFields) {
                    NotificationService::sendFcmNotification(
                        $chunk->toArray(), $title, $message, $type, $customBodyFields, false
                    );
                });
                $this->info("💻 Chunk-based notification sent to other platform users.");
            }

        } else {
            // ✅ Send to specific selected users
            UserFcmToken::with('user')
                ->whereIn('user_id', $userIds)
                ->whereHas('user', fn($q) => $q->where('notification', 1))
                ->chunk(500, function ($tokens) use ($title, $message, $type, $customBodyFields) {
                    $fcmTokens = $tokens->pluck('fcm_token')->toArray();
                    NotificationService::sendFcmNotification(
                        $fcmTokens, $title, $message, $type, $customBodyFields, false
                    );
                });

            $this->info("👥 Notifications sent to selected users.");
        }

        $this->info("✅ FCM notifications process completed successfully!");
    }
}
