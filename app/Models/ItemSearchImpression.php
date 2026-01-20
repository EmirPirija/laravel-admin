<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ItemSearchImpression extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id', 'visitor_id', 'user_id',
        'search_query', 'search_type', 'filters_applied',
        'position', 'page', 'results_total',
        'was_clicked', 'clicked_at', 'was_featured', 'device_type',
    ];

    protected $casts = [
        'filters_applied' => 'array',
        'was_clicked' => 'boolean',
        'clicked_at' => 'datetime',
        'was_featured' => 'boolean',
    ];

    // ═══════════════════════════════════════════
    // TIPOVI PRETRAGE
    // ═══════════════════════════════════════════

    const TYPE_GENERAL = 'general';
    const TYPE_CATEGORY = 'category';
    const TYPE_LOCATION = 'location';
    const TYPE_SELLER = 'seller';
    const TYPE_SIMILAR = 'similar';
    const TYPE_RECOMMENDED = 'recommended';

    // ═══════════════════════════════════════════
    // RELACIJE
    // ═══════════════════════════════════════════

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ═══════════════════════════════════════════
    // BILJEŽENJE IMPRESSIONA
    // ═══════════════════════════════════════════

    /**
     * Zabilježi impression u pretrazi
     */
    public static function record(int $itemId, array $data): self
    {
        $impression = self::create([
            'item_id' => $itemId,
            'visitor_id' => $data['visitor_id'] ?? null,
            'user_id' => $data['user_id'] ?? auth()->id(),
            'search_query' => $data['search_query'] ?? null,
            'search_type' => $data['search_type'] ?? self::TYPE_GENERAL,
            'filters_applied' => $data['filters_applied'] ?? null,
            'position' => $data['position'],
            'page' => $data['page'] ?? 1,
            'results_total' => $data['results_total'] ?? null,
            'was_featured' => $data['was_featured'] ?? false,
            'device_type' => $data['device_type'] ?? null,
        ]);

        // Ažuriraj dnevnu statistiku
        ItemStatistic::incrementStat($itemId, 'search_impressions');
        
        if ($data['was_featured'] ?? false) {
            ItemStatistic::incrementStat($itemId, 'featured_impressions');
        }

        // Ažuriraj items tabelu
        Item::where('id', $itemId)->increment('total_search_impressions');

        return $impression;
    }

    /**
     * Zabilježi klik na rezultat
     */
    public function recordClick(): void
    {
        $this->update([
            'was_clicked' => true,
            'clicked_at' => Carbon::now(),
        ]);

        // Ažuriraj dnevnu statistiku
        ItemStatistic::incrementStat($this->item_id, 'search_clicks');
        
        if ($this->was_featured) {
            ItemStatistic::incrementStat($this->item_id, 'featured_clicks');
        }

        // Ažuriraj prosječnu poziciju
        ItemStatistic::updateAverage($this->item_id, 'search_position_avg', $this->position);
    }

    /**
     * Batch zabilježi impressione za više oglasa
     */
    public static function recordBatch(array $items, array $commonData): void
    {
        $records = [];
        $now = Carbon::now();

        foreach ($items as $index => $item) {
            $records[] = [
                'item_id' => $item['id'],
                'visitor_id' => $commonData['visitor_id'] ?? null,
                'user_id' => $commonData['user_id'] ?? auth()->id(),
                'search_query' => $commonData['search_query'] ?? null,
                'search_type' => $commonData['search_type'] ?? self::TYPE_GENERAL,
                'filters_applied' => json_encode($commonData['filters_applied'] ?? null),
                'position' => $index + 1 + (($commonData['page'] ?? 1) - 1) * ($commonData['per_page'] ?? 20),
                'page' => $commonData['page'] ?? 1,
                'results_total' => $commonData['results_total'] ?? null,
                'was_featured' => $item['is_featured'] ?? false,
                'device_type' => $commonData['device_type'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Inkrementiraj statistike
            ItemStatistic::incrementStat($item['id'], 'search_impressions');
            Item::where('id', $item['id'])->increment('total_search_impressions');
        }

        // Bulk insert
        self::insert($records);
    }

    // ═══════════════════════════════════════════
    // STATISTIKA
    // ═══════════════════════════════════════════

    /**
     * Dohvati statistiku pretrage za oglas
     */
    public static function getSearchStats(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        // Osnovne statistike
        $stats = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as impressions,
                SUM(CASE WHEN was_clicked THEN 1 ELSE 0 END) as clicks,
                AVG(position) as avg_position,
                MIN(position) as best_position,
                COUNT(DISTINCT search_query) as unique_queries
            ')
            ->first();

        $impressions = (int)($stats->impressions ?? 0);
        $clicks = (int)($stats->clicks ?? 0);
        $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;

        // Top upiti koji su doveli do oglasa
        $topQueries = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('search_query')
            ->where('search_query', '!=', '')
            ->selectRaw('search_query, COUNT(*) as impressions, SUM(CASE WHEN was_clicked THEN 1 ELSE 0 END) as clicks')
            ->groupBy('search_query')
            ->orderByDesc('impressions')
            ->limit(10)
            ->get()
            ->map(function ($q) {
                return [
                    'query' => $q->search_query,
                    'impressions' => (int)$q->impressions,
                    'clicks' => (int)$q->clicks,
                    'ctr' => $q->impressions > 0 ? round(($q->clicks / $q->impressions) * 100, 1) : 0,
                ];
            })
            ->toArray();

        // Pozicija po danima
        $positionTrend = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, AVG(position) as avg_position, COUNT(*) as impressions')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($d) => [
                'date' => $d->date,
                'avg_position' => round($d->avg_position, 1),
                'impressions' => (int)$d->impressions,
            ])
            ->toArray();

        // Featured vs Non-featured
        $featuredStats = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                was_featured,
                COUNT(*) as impressions,
                SUM(CASE WHEN was_clicked THEN 1 ELSE 0 END) as clicks,
                AVG(position) as avg_position
            ')
            ->groupBy('was_featured')
            ->get()
            ->keyBy('was_featured');

        $featured = $featuredStats->get(1);
        $nonFeatured = $featuredStats->get(0);

        return [
            'total_impressions' => $impressions,
            'total_clicks' => $clicks,
            'ctr' => $ctr,
            'avg_position' => round($stats->avg_position ?? 0, 1),
            'best_position' => (int)($stats->best_position ?? 0),
            'unique_queries' => (int)($stats->unique_queries ?? 0),
            'top_queries' => $topQueries,
            'position_trend' => $positionTrend,
            'featured_comparison' => [
                'featured' => [
                    'impressions' => (int)($featured->impressions ?? 0),
                    'clicks' => (int)($featured->clicks ?? 0),
                    'ctr' => ($featured->impressions ?? 0) > 0 
                        ? round((($featured->clicks ?? 0) / $featured->impressions) * 100, 1) : 0,
                    'avg_position' => round($featured->avg_position ?? 0, 1),
                ],
                'non_featured' => [
                    'impressions' => (int)($nonFeatured->impressions ?? 0),
                    'clicks' => (int)($nonFeatured->clicks ?? 0),
                    'ctr' => ($nonFeatured->impressions ?? 0) > 0 
                        ? round((($nonFeatured->clicks ?? 0) / $nonFeatured->impressions) * 100, 1) : 0,
                    'avg_position' => round($nonFeatured->avg_position ?? 0, 1),
                ],
            ],
        ];
    }

    /**
     * Dohvati konkurentske statistike (kako se oglas rangira u odnosu na druge)
     */
    public static function getCompetitiveStats(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);
        $item = Item::find($itemId);
        
        if (!$item) {
            return [];
        }

        // Prosječna pozicija ovog oglasa
        $myStats = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('AVG(position) as avg_position, COUNT(*) as impressions')
            ->first();

        // Prosječna pozicija svih oglasa u istoj kategoriji
        $categoryItemIds = Item::where('category_id', $item->category_id)
            ->where('id', '!=', $itemId)
            ->pluck('id');

        $categoryStats = self::whereIn('item_id', $categoryItemIds)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('AVG(position) as avg_position')
            ->first();

        $myAvgPosition = round($myStats->avg_position ?? 0, 1);
        $categoryAvgPosition = round($categoryStats->avg_position ?? 0, 1);

        // Percentil (koliko % oglasa ima lošiju poziciju)
        $betterThanCount = self::whereIn('item_id', $categoryItemIds)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('item_id, AVG(position) as avg_pos')
            ->groupBy('item_id')
            ->havingRaw('AVG(position) > ?', [$myAvgPosition])
            ->count();

        $totalInCategory = $categoryItemIds->count();
        $percentile = $totalInCategory > 0 ? round(($betterThanCount / $totalInCategory) * 100) : 0;

        return [
            'my_avg_position' => $myAvgPosition,
            'category_avg_position' => $categoryAvgPosition,
            'position_difference' => round($categoryAvgPosition - $myAvgPosition, 1),
            'better_than_percent' => $percentile,
            'ranking_label' => self::getRankingLabel($percentile),
        ];
    }

    private static function getRankingLabel(int $percentile): string
    {
        return match(true) {
            $percentile >= 90 => 'Top 10% 🏆',
            $percentile >= 75 => 'Iznad prosjeka',
            $percentile >= 50 => 'Prosječan',
            $percentile >= 25 => 'Ispod prosjeka',
            default => 'Potrebno poboljšanje',
        };
    }
}
