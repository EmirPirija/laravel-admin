<?php
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
 
class ItemSearchImpression extends Model
{
/**
 * Dohvati statistiku pretrage za oglas
 */
public static function getSearchStats(int $itemId, int $days = 30): array
{
    $startDate = Carbon::today()->subDays($days - 1);
 
    $stats = self::where('item_id', $itemId)
        ->where('created_at', '>=', $startDate)
        ->selectRaw('
            COUNT(*) as impressions,
            SUM(CASE WHEN was_clicked THEN 1 ELSE 0 END) as clicks,
            AVG(position) as avg_position,
            MIN(position) as best_position
        ')
        ->first();
 
    $impressions = (int)($stats->impressions ?? 0);
    $clicks = (int)($stats->clicks ?? 0);
    $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;
 
    // Top search queries
    $topQueries = self::where('item_id', $itemId)
        ->where('created_at', '>=', $startDate)
        ->whereNotNull('search_query')
        ->where('search_query', '!=', '')
        ->selectRaw('search_query, COUNT(*) as count, SUM(CASE WHEN was_clicked THEN 1 ELSE 0 END) as clicks')
        ->groupBy('search_query')
        ->orderByDesc('count')
        ->limit(10)
        ->get();
 
    return [
        'impressions' => $impressions,
        'clicks' => $clicks,
        'ctr' => $ctr,
        'avg_position' => round($stats->avg_position ?? 0, 1),
        'best_position' => (int)($stats->best_position ?? 0),
        'top_queries' => $topQueries->map(fn($q) => [
            'query' => $q->search_query,
            'impressions' => (int)$q->count,
            'clicks' => (int)$q->clicks,
            'ctr' => $q->count > 0 ? round(($q->clicks / $q->count) * 100, 2) : 0,
        ])->toArray(),
    ];
}
 
/**
 * Dohvati konkurentsku statistiku
 */
public static function getCompetitiveStats(int $itemId, int $days = 30): array
{
    $startDate = Carbon::today()->subDays($days - 1);
 
    // Moje statistike
    $myStats = self::where('item_id', $itemId)
        ->where('created_at', '>=', $startDate)
        ->selectRaw('AVG(position) as avg_position, COUNT(*) as impressions')
        ->first();
 
    $myAvgPosition = round($myStats->avg_position ?? 0, 1);
    $myImpressions = (int)($myStats->impressions ?? 0);
 
    // Nađi oglase koji se pojavljuju u istim pretragama
    $myQueries = self::where('item_id', $itemId)
        ->where('created_at', '>=', $startDate)
        ->whereNotNull('search_query')
        ->pluck('search_query')
        ->unique();
 
    $competitorCount = 0;
    $avgCompetitorPosition = 0;
 
    if ($myQueries->isNotEmpty()) {
        $competitorStats = self::where('item_id', '!=', $itemId)
            ->where('created_at', '>=', $startDate)
            ->whereIn('search_query', $myQueries)
            ->selectRaw('COUNT(DISTINCT item_id) as competitors, AVG(position) as avg_position')
            ->first();
 
        $competitorCount = (int)($competitorStats->competitors ?? 0);
        $avgCompetitorPosition = round($competitorStats->avg_position ?? 0, 1);
    }
 
    return [
        'my_avg_position' => $myAvgPosition,
        'my_impressions' => $myImpressions,
        'competitor_count' => $competitorCount,
        'competitor_avg_position' => $avgCompetitorPosition,
        'position_advantage' => $avgCompetitorPosition > 0 
            ? round($avgCompetitorPosition - $myAvgPosition, 1) 
            : 0,
        'performance_label' => match(true) {
            $myAvgPosition === 0 => 'Nema podataka',
            $myAvgPosition <= 3 => 'Odlična pozicija 🏆',
            $myAvgPosition <= 10 => 'Dobra pozicija',
            $myAvgPosition <= 20 => 'Prosječna pozicija',
            default => 'Potrebno poboljšanje',
        },
    ];
}
}