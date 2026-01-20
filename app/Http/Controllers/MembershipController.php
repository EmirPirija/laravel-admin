<?php

namespace App\Http\Controllers;

use App\Models\MembershipTier;
use App\Models\UserMembership;
use App\Models\MembershipTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class MembershipController extends Controller
{
    // Dohvati membership status korisnika
    public function getUserMembership(Request $request)
    {
        $userId = $request->user_id ?? auth()->id();
        
        $membership = UserMembership::with('membershipTier')
            ->where('user_id', $userId)
            ->first();

        if (!$membership) {
            // Free tier default
            return response()->json([
                'error' => false,
                'message' => 'User has free membership',
                'data' => [
                    'tier' => 'free',
                    'started_at' => null,
                    'expires_at' => null,
                    'status' => 'active',
                ]
            ]);
        }

        return response()->json([
            'error' => false,
            'message' => 'User membership fetched successfully',
            'data' => [
                'tier' => $membership->tier,
                'tier_name' => $membership->membershipTier->name ?? 'Free',
                'started_at' => $membership->started_at,
                'expires_at' => $membership->expires_at,
                'status' => $membership->status,
                'is_active' => $membership->isActive(),
            ]
        ]);
    }

    // Dohvati sve dostupne tier-ove
    public function getMembershipTiers()
    {
        $tiers = MembershipTier::where('is_active', true)
            ->orderBy('order')
            ->get();

        return response()->json([
            'error' => false,
            'message' => 'Membership tiers fetched successfully',
            'data' => $tiers
        ]);
    }

    // Upgrade membership
    public function upgradeMembership(Request $request)
    {
        $userId = auth()->id();
        $tierId = $request->tier_id;
        $paymentMethod = $request->payment_method ?? 'stripe';

        $tier = MembershipTier::find($tierId);
        if (!$tier) {
            return response()->json(['error' => true, 'message' => 'Tier not found']);
        }

        // Kreiraj transakciju
        $transaction = MembershipTransaction::create([
            'user_id' => $userId,
            'tier_id' => $tierId,
            'amount' => $tier->price,
            'payment_method' => $paymentMethod,
            'payment_status' => 'pending',
        ]);

        // TODO: Integrisati sa Stripe/Razorpay payment gateway ovdje
        // Za sada ćemo simulirati uspješnu uplatu

        $transaction->update([
            'payment_status' => 'completed',
            'paid_at' => now(),
            'transaction_id' => 'TRANS_' . time(),
        ]);

        // Upgrade user membership
        $membership = UserMembership::updateOrCreate(
            ['user_id' => $userId],
            [
                'tier_id' => $tierId,
                'tier' => $tier->slug,
                'started_at' => now(),
                'expires_at' => now()->addDays($tier->duration_days),
                'status' => 'active',
            ]
        );

        return response()->json([
            'error' => false,
            'message' => 'Membership upgraded successfully',
            'data' => [
                'membership' => $membership,
                'transaction' => $transaction,
            ]
        ]);
    }

    // Cancel membership
    public function cancelMembership()
    {
        $userId = auth()->id();
        
        $membership = UserMembership::where('user_id', $userId)->first();
        if (!$membership) {
            return response()->json(['error' => true, 'message' => 'No active membership found']);
        }

        $membership->update(['status' => 'cancelled']);

        return response()->json([
            'error' => false,
            'message' => 'Membership cancelled successfully'
        ]);
    }
}
