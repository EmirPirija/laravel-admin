<?php
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
 
class ItemShare extends Model
{
    /**
 * Dohvati statistiku dijeljenja za oglas
 */
public static function getShareStats(int $itemId, int $days = 30): array
{
    $startDate = Carbon::today()->subDays($days - 1);
 
    $stats = self::where('item_id', $itemId)
        ->where('created_at', '>=', $startDate)
        ->selectRaw('platform, COUNT(*) as count, SUM(clicks_from_share) as clicks')
        ->groupBy('platform')
        ->get();
 
    $total = $stats->sum('count');
    $totalClicks = $stats->sum('clicks');
 
    $byPlatform = [];
    foreach ($stats as $stat) {
        $byPlatform[] = [
            'platform' => $stat->platform,
            'count' => (int)$stat->count,
            'clicks' => (int)$stat->clicks,
            'percent' => $total > 0 ? round(($stat->count / $total) * 100, 1) : 0,
        ];
    }
 
    // Sortiraj po broju
    usort($byPlatform, fn($a, $b) => $b['count'] <=> $a['count']);
 
    return [
        'total_shares' => $total,
        'total_clicks_from_shares' => $totalClicks,
        'click_rate' => $total > 0 ? round(($totalClicks / $total) * 100, 2) : 0,
        'by_platform' => $byPlatform,
    ];
}
 
/**
 * Izračunaj virality score
 */
public static function getViralityScore(int $itemId, int $days = 30): array
{
    $startDate = Carbon::today()->subDays($days - 1);
 
    $shares = self::where('item_id', $itemId)
        ->where('created_at', '>=', $startDate)
        ->get();
 
    $totalShares = $shares->count();
    $totalClicks = $shares->sum('clicks_from_share');
    
    // Virality score: kombinacija broja dijeljenja i klikova
    $score = 0;
    if ($totalShares > 0) {
        $clickRate = $totalClicks / $totalShares;
        $score = min(100, round(($totalShares * 2) + ($clickRate * 10)));
    }
 
    return [
        'score' => $score,
        'total_shares' => $totalShares,
        'total_clicks' => $totalClicks,
        'avg_clicks_per_share' => $totalShares > 0 ? round($totalClicks / $totalShares, 2) : 0,
        'label' => match(true) {
            $score >= 80 => 'Viralan 🔥',
            $score >= 50 => 'Popularan',
            $score >= 20 => 'Umjereno dijeljen',
            $score > 0 => 'Malo dijeljen',
            default => 'Nije dijeljen',
        },
    ];
}
}