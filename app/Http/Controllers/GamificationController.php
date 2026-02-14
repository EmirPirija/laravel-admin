<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\UserBadge;
use App\Models\UserPoints;
use App\Models\PointsHistory;
use App\Models\User;
use App\Models\Item;
use App\Models\SellerRating;
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

    // Gamification overview (score + motivacija)
    public function getOverview(Request $request)
    {
        $userId = $request->user_id ?? auth()->id();
        if (! $userId) {
            return response()->json([
                'error' => false,
                'message' => 'No user specified',
                'data' => [
                    'score' => 0,
                    'rank' => 'Novi profil',
                    'metrics' => [],
                    'missions' => [],
                ],
            ]);
        }

        $user = User::find($userId);
        if (! $user) {
            return response()->json([
                'error' => true,
                'message' => 'User not found',
                'data' => null,
            ], 404);
        }

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

        $totalAds = Item::withTrashed()->where('user_id', $userId)->count();
        $activeAds = Item::where('user_id', $userId)
            ->whereIn('status', ['approved', 'featured', 'reserved'])
            ->count();

        $avgRating = (float) (SellerRating::where('seller_id', $userId)->avg('ratings') ?? 0);
        $positiveRatings = SellerRating::where('seller_id', $userId)
            ->where('ratings', '>=', 4)
            ->count();
        $totalRatings = SellerRating::where('seller_id', $userId)->count();

        $responseAvg = $user->response_time_avg !== null ? (int) $user->response_time_avg : null;
        $responseScore = $responseAvg === null
            ? 0
            : ($responseAvg <= 15 ? 100 : ($responseAvg <= 60 ? 75 : ($responseAvg <= 180 ? 45 : 20)));

        $adsScore = min(100, (int) round(($activeAds / 20) * 100));
        $successScore = min(100, max(0, (int) round($avgRating * 20)));
        $ratingScore = $totalRatings > 0
            ? min(100, (int) round(($positiveRatings / $totalRatings) * 100))
            : 0;

        $score = (int) round(($responseScore + $adsScore + $successScore + $ratingScore) / 4);
        $rank = $score >= 85
            ? 'LMX Elite'
            : ($score >= 65 ? 'LMX Pro rast' : ($score >= 40 ? 'Aktivan prodavač' : 'Novi profil'));

        $missions = [
            [
                'id' => 'response',
                'title' => 'Odgovaraj brže na poruke',
                'status' => $responseAvg !== null && $responseAvg <= 30 ? 'completed' : 'in_progress',
                'hint' => 'Cilj: prosjek odgovora ispod 30 min',
            ],
            [
                'id' => 'ads',
                'title' => 'Aktivni oglasi',
                'status' => $activeAds >= 10 ? 'completed' : 'in_progress',
                'hint' => 'Cilj: najmanje 10 aktivnih oglasa',
            ],
            [
                'id' => 'ratings',
                'title' => 'Pozitivne ocjene',
                'status' => $positiveRatings >= 5 ? 'completed' : 'in_progress',
                'hint' => 'Cilj: najmanje 5 pozitivnih ocjena',
            ],
        ];

        return response()->json([
            'error' => false,
            'message' => 'Gamification overview fetched successfully',
            'data' => [
                'score' => $score,
                'rank' => $rank,
                'points' => [
                    'total_points' => (int) $userPoints->total_points,
                    'level' => (int) $userPoints->level,
                    'level_name' => $userPoints->level_name,
                    'points_to_next_level' => (int) $userPoints->points_to_next_level,
                    'current_level_points' => (int) $userPoints->current_level_points,
                ],
                'metrics' => [
                    [
                        'key' => 'response_speed',
                        'label' => 'Brzina odgovora',
                        'score' => $responseScore,
                        'value' => $responseAvg,
                        'unit' => 'min',
                    ],
                    [
                        'key' => 'seller_success',
                        'label' => 'Uspješnost',
                        'score' => $successScore,
                        'value' => round($avgRating, 2),
                        'unit' => '/5',
                    ],
                    [
                        'key' => 'ads_volume',
                        'label' => 'Broj oglasa',
                        'score' => $adsScore,
                        'value' => $activeAds,
                        'unit' => 'aktivni',
                    ],
                    [
                        'key' => 'positive_reviews',
                        'label' => 'Pozitivne ocjene',
                        'score' => $ratingScore,
                        'value' => $positiveRatings,
                        'unit' => 'pozitivne',
                    ],
                ],
                'stats' => [
                    'total_ads' => $totalAds,
                    'active_ads' => $activeAds,
                    'avg_rating' => round($avgRating, 2),
                    'positive_ratings' => $positiveRatings,
                    'total_ratings' => $totalRatings,
                ],
                'missions' => $missions,
            ],
        ]);
    }

    // Lista dostupnih avatar opcija (LMX avatar picker)
    public function getAvatarOptions()
    {
        return response()->json([
            'error' => false,
            'message' => 'Avatar options fetched successfully',
            'data' => [
                ['id' => 'avatar-neo', 'name' => 'Neo trgovac', 'style' => 'modern', 'rarity' => 'common'],
                ['id' => 'avatar-spark', 'name' => 'Spark seller', 'style' => 'vibrant', 'rarity' => 'common'],
                ['id' => 'avatar-pro', 'name' => 'Pro merchant', 'style' => 'professional', 'rarity' => 'rare'],
                ['id' => 'avatar-elite', 'name' => 'Elite founder', 'style' => 'premium', 'rarity' => 'epic'],
            ],
        ]);
    }
}
