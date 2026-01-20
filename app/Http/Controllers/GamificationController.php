<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\UserBadge;
use App\Models\UserPoints;
use App\Models\PointsHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class GamificationController extends Controller
{
    // Dohvati bedževe korisnika
    public function getUserBadges(Request $request)
    {
        $userId = $request->user_id ?? auth()->id();
        
        if (!$userId) {
            return response()->json([
                'error' => false,
                'data' => ['badges' => []],
                'message' => 'No user specified'
            ]);
        }
        
        $badges = UserBadge::where('user_id', $userId)
            ->with('badge')
            ->get()
            ->map(function ($userBadge) {
                return [
                    'id' => $userBadge->badge->id,
                    'name' => $userBadge->badge->name,
                    'description' => $userBadge->badge->description,
                    'icon' => $userBadge->badge->icon,
                    'earned_at' => $userBadge->earned_at,
                    'unlocked' => true,
                ];
            });
        
        return response()->json([
            'error' => false,
            'data' => ['badges' => $badges],
            'message' => 'Success'
        ]);
    }


    // Dohvati points korisnika
    public function getUserPoints(Request $request)
    {
        $userId = $request->user_id ?? auth()->id();
        
        $userPoints = UserPoints::firstOrCreate(
            ['user_id' => $userId],
            [
                'total_points' => 0,
                'level' => 1,
                'level_name' => 'Beginner',
                'points_to_next_level' => 100,
                'current_level_points' => 0,
            ]
        );

        return response()->json([
            'error' => false,
            'message' => 'User points fetched successfully',
            'data' => $userPoints
        ]);
    }

    // Leaderboard
    public function getLeaderboard(Request $request)
    {
        $period = $request->period ?? 'weekly'; // weekly, monthly, all-time
        $perPage = $request->per_page ?? 20;

        $query = UserPoints::with('user:id,name,email,profile')
            ->join('users', 'user_points.user_id', '=', 'users.id');

        // Filter po periodu
        if ($period === 'weekly') {
            $query->where('user_points.updated_at', '>=', Carbon::now()->subWeek());
        } elseif ($period === 'monthly') {
            $query->where('user_points.updated_at', '>=', Carbon::now()->subMonth());
        }

        $leaderboard = $query->orderBy('user_points.total_points', 'desc')
            ->select('user_points.*')
            ->paginate($perPage);

        $leaderboard->getCollection()->transform(function($item) {
            $badgeCount = UserBadge::where('user_id', $item->user_id)->count();
            
            return [
                'id' => $item->user->id,
                'name' => $item->user->name,
                'profile' => $item->user->profile,
                'total_points' => $item->total_points,
                'level' => $item->level,
                'level_name' => $item->level_name,
                'badge_count' => $badgeCount,
            ];
        });

        return response()->json([
            'error' => false,
            'message' => 'Leaderboard fetched successfully',
            'data' => $leaderboard
        ]);
    }

    // Sve dostupne bedževe
    public function getAllBadges()
    {
        $badges = Badge::where('is_active', true)
            ->orderBy('order')
            ->get();

        return response()->json([
            'error' => false,
            'message' => 'All badges fetched successfully',
            'data' => $badges
        ]);
    }

    // Points history
    public function getPointsHistory(Request $request)
    {
        $userId = auth()->id();
        $perPage = $request->per_page ?? 20;

        $history = PointsHistory::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'error' => false,
            'message' => 'Points history fetched successfully',
            'data' => $history
        ]);
    }
}
