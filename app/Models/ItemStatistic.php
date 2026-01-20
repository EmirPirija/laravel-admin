<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ItemStatistic extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id', 'date',
        // Pregledi
        'views', 'unique_views', 'returning_views', 'avg_time_on_page', 'bounce_count',
        // Favoriti
        'favorites_added', 'favorites_removed', 'favorites_net',
        // Kontakt
        'messages_started', 'messages_total', 'phone_reveals', 'phone_clicks',
        'whatsapp_clicks', 'viber_clicks', 'email_clicks', 'offers_received', 'offers_avg_amount',
        // Dijeljenje
        'shares_total', 'share_facebook', 'share_messenger', 'share_instagram',
        'share_viber', 'share_whatsapp', 'share_twitter', 'share_linkedin',
        'share_telegram', 'share_email', 'share_sms', 'share_copy_link', 'share_qr_code', 'share_print',
        // Engagement
        'gallery_opens', 'image_views', 'image_zooms', 'image_downloads',
        'video_plays', 'video_completions', 'video_25_percent', 'video_50_percent', 'video_75_percent',
        'description_expands', 'specifications_views', 'location_views', 'map_opens', 'map_directions',
        'seller_profile_clicks', 'seller_other_items_clicks', 'similar_items_clicks', 'price_history_views',
        // Pretraga
        'search_impressions', 'search_clicks', 'search_ctr', 'search_position_avg', 'search_position_best',
        'category_impressions', 'category_clicks', 'homepage_impressions', 'homepage_clicks',
        // Promocija
        'was_featured', 'featured_position', 'featured_impressions', 'featured_clicks', 'featured_ctr',
        // Izvori
        'source_direct', 'source_internal_search', 'source_category_browse', 'source_featured_section',
        'source_similar_items', 'source_seller_profile', 'source_favorites', 'source_notifications',
        'source_chat', 'source_email_campaign', 'source_push_notification',
        'source_google_organic', 'source_google_ads', 'source_facebook', 'source_instagram',
        'source_viber', 'source_whatsapp', 'source_twitter', 'source_tiktok', 'source_youtube',
        'source_linkedin', 'source_other_external',
        // Uređaji
        'device_mobile', 'device_desktop', 'device_tablet', 'device_app_ios', 'device_app_android',
        // Geo
        'geo_countries', 'geo_cities', 'hourly_views',
        // Konverzija
        'was_sold', 'sale_price',
        // Cijena
        'price_at_date', 'price_changed', 'price_change_amount',
        // Konkurencija
        'category_total_items', 'category_rank', 'category_percentile',
    ];

    protected $casts = [
        'date' => 'date',
        'was_featured' => 'boolean',
        'was_sold' => 'boolean',
        'price_changed' => 'boolean',
        'geo_countries' => 'array',
        'geo_cities' => 'array',
        'hourly_views' => 'array',
        'offers_avg_amount' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'price_at_date' => 'decimal:2',
        'price_change_amount' => 'decimal:2',
        'search_ctr' => 'decimal:2',
        'search_position_avg' => 'decimal:2',
        'featured_ctr' => 'decimal:2',
        'category_percentile' => 'decimal:2',
    ];

    // ═══════════════════════════════════════════
    // RELACIJE
    // ═══════════════════════════════════════════
    
    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    // ═══════════════════════════════════════════
    // HELPER METODE
    // ═══════════════════════════════════════════

    /**
     * Dohvati ili kreiraj statistiku za danas
     */
    public static function getOrCreateForToday(int $itemId): self
    {
        $today = Carbon::today()->toDateString();
        
        $item = Item::find($itemId);
        $isFeatured = $item?->featured_items()->count() > 0;
        
        return self::firstOrCreate(
            ['item_id' => $itemId, 'date' => $today],
            [
                'was_featured' => $isFeatured,
                'price_at_date' => $item?->price,
            ]
        );
    }

    /**
     * Inkrementiraj statistiku
     */
    public static function incrementStat(int $itemId, string $stat, int $amount = 1): void
    {
        $record = self::getOrCreateForToday($itemId);
        $record->increment($stat, $amount);
    }

    /**
     * Inkrementiraj više statistika odjednom
     */
    public static function incrementMultiple(int $itemId, array $stats): void
    {
        $record = self::getOrCreateForToday($itemId);
        foreach ($stats as $stat => $amount) {
            $record->increment($stat, $amount);
        }
    }

    /**
     * Ažuriraj prosječnu vrijednost
     */
    public static function updateAverage(int $itemId, string $stat, float $newValue): void
    {
        $record = self::getOrCreateForToday($itemId);
        $currentAvg = $record->$stat ?? 0;
        $count = $record->views ?: 1;
        
        // Incrementalni prosjek
        $newAvg = $currentAvg + (($newValue - $currentAvg) / $count);
        $record->update([$stat => $newAvg]);
    }

    /**
     * Dodaj u JSON polje (geo, hourly, etc)
     */
    public static function incrementJsonField(int $itemId, string $field, string $key, int $amount = 1): void
    {
        $record = self::getOrCreateForToday($itemId);
        $data = $record->$field ?? [];
        $data[$key] = ($data[$key] ?? 0) + $amount;
        $record->update([$field => $data]);
    }

    // ═══════════════════════════════════════════
    // STATISTIKA ZA PERIOD
    // ═══════════════════════════════════════════

    /**
     * Dohvati dnevnu statistiku za period
     */
    public static function getStatsForPeriod(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);
        $endDate = Carbon::today();

        $stats = self::where('item_id', $itemId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'asc')
            ->get()
            ->keyBy(fn($s) => $s->date->toDateString());

        $result = [];
        $currentDate = $startDate->copy();
        
        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->toDateString();
            $dayStat = $stats->get($dateStr);
            
            $result[] = [
                'date' => $dateStr,
                'formatted_date' => $currentDate->format('d.m.'),
                'day_name' => self::getBosnianDayName($currentDate->dayOfWeek),
                'views' => $dayStat->views ?? 0,
                'unique_views' => $dayStat->unique_views ?? 0,
                'favorites_added' => $dayStat->favorites_added ?? 0,
                'messages_started' => $dayStat->messages_started ?? 0,
                'phone_clicks' => $dayStat->phone_clicks ?? 0,
                'shares_total' => $dayStat->shares_total ?? 0,
                'was_featured' => $dayStat->was_featured ?? false,
                'gallery_opens' => $dayStat->gallery_opens ?? 0,
                'video_plays' => $dayStat->video_plays ?? 0,
                'search_impressions' => $dayStat->search_impressions ?? 0,
                'search_clicks' => $dayStat->search_clicks ?? 0,
            ];
            
            $currentDate->addDay();
        }

        return $result;
    }

    /**
     * Dohvati sumarnu statistiku
     */
    public static function getSummaryStats(int $itemId, int $days = 30): array
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $startDate = $today->copy()->subDays($days - 1);
        $weekAgo = $today->copy()->subDays(6);

        // Danas
        $todayStats = self::where('item_id', $itemId)->where('date', $today)->first();
        
        // Jučer
        $yesterdayStats = self::where('item_id', $itemId)->where('date', $yesterday)->first();

        // Zadnjih 7 dana
        $last7Days = self::where('item_id', $itemId)
            ->where('date', '>=', $weekAgo)
            ->selectRaw('
                SUM(views) as views,
                SUM(unique_views) as unique_views,
                SUM(favorites_added) as favorites,
                SUM(messages_started) as messages,
                SUM(phone_clicks) as phone_clicks,
                SUM(whatsapp_clicks) as whatsapp_clicks,
                SUM(shares_total) as shares,
                SUM(gallery_opens) as gallery,
                SUM(video_plays) as video,
                SUM(search_impressions) as search_imp,
                SUM(search_clicks) as search_clicks
            ')
            ->first();

        // Cijeli period
        $periodStats = self::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->selectRaw('
                SUM(views) as views,
                SUM(unique_views) as unique_views,
                SUM(favorites_added) as favorites,
                SUM(favorites_removed) as favorites_removed,
                SUM(messages_started) as messages,
                SUM(messages_total) as messages_total,
                SUM(phone_clicks) as phone_clicks,
                SUM(phone_reveals) as phone_reveals,
                SUM(whatsapp_clicks) as whatsapp_clicks,
                SUM(viber_clicks) as viber_clicks,
                SUM(shares_total) as shares,
                SUM(gallery_opens) as gallery,
                SUM(image_views) as images,
                SUM(video_plays) as video,
                SUM(video_completions) as video_complete,
                SUM(map_opens) as map,
                SUM(seller_profile_clicks) as seller_clicks,
                SUM(search_impressions) as search_imp,
                SUM(search_clicks) as search_clicks,
                SUM(offers_received) as offers,
                AVG(avg_time_on_page) as avg_time,
                COUNT(*) as days_with_data
            ')
            ->first();

        // Promocija vs Normalno
        $featuredStats = self::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->where('was_featured', true)
            ->selectRaw('SUM(views) as views, COUNT(*) as days')
            ->first();

        $nonFeaturedStats = self::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->where('was_featured', false)
            ->selectRaw('SUM(views) as views, COUNT(*) as days')
            ->first();

        // Izračuni
        $totalViews = (int)($periodStats->views ?? 0);
        $avgViewsPerDay = $days > 0 ? round($totalViews / $days, 1) : 0;
        
        $avgFeatured = ($featuredStats->days ?? 0) > 0 
            ? round(($featuredStats->views ?? 0) / $featuredStats->days, 1) : 0;
        $avgNonFeatured = ($nonFeaturedStats->days ?? 0) > 0 
            ? round(($nonFeaturedStats->views ?? 0) / $nonFeaturedStats->days, 1) : 0;
        
        $featuredImprovement = $avgNonFeatured > 0 
            ? round((($avgFeatured - $avgNonFeatured) / $avgNonFeatured) * 100) : 0;

        // CTR
        $searchCTR = ($periodStats->search_imp ?? 0) > 0 
            ? round((($periodStats->search_clicks ?? 0) / $periodStats->search_imp) * 100, 2) : 0;

        // Trend (danas vs jučer)
        $todayViews = $todayStats->views ?? 0;
        $yesterdayViews = $yesterdayStats->views ?? 0;
        $viewsTrend = $yesterdayViews > 0 
            ? round((($todayViews - $yesterdayViews) / $yesterdayViews) * 100) : 0;

        return [
            'today' => [
                'views' => $todayStats->views ?? 0,
                'unique_views' => $todayStats->unique_views ?? 0,
                'messages' => $todayStats->messages_started ?? 0,
                'favorites' => $todayStats->favorites_added ?? 0,
                'phone_clicks' => $todayStats->phone_clicks ?? 0,
                'shares' => $todayStats->shares_total ?? 0,
            ],
            'yesterday' => [
                'views' => $yesterdayStats->views ?? 0,
                'unique_views' => $yesterdayStats->unique_views ?? 0,
                'messages' => $yesterdayStats->messages_started ?? 0,
                'favorites' => $yesterdayStats->favorites_added ?? 0,
            ],
            'last_7_days' => [
                'views' => (int)($last7Days->views ?? 0),
                'unique_views' => (int)($last7Days->unique_views ?? 0),
                'messages' => (int)($last7Days->messages ?? 0),
                'favorites' => (int)($last7Days->favorites ?? 0),
                'phone_clicks' => (int)($last7Days->phone_clicks ?? 0),
                'shares' => (int)($last7Days->shares ?? 0),
            ],
            'period' => [
                'days' => $days,
                'views' => $totalViews,
                'unique_views' => (int)($periodStats->unique_views ?? 0),
                'messages' => (int)($periodStats->messages ?? 0),
                'messages_total' => (int)($periodStats->messages_total ?? 0),
                'favorites' => (int)($periodStats->favorites ?? 0),
                'favorites_removed' => (int)($periodStats->favorites_removed ?? 0),
                'phone_clicks' => (int)($periodStats->phone_clicks ?? 0),
                'phone_reveals' => (int)($periodStats->phone_reveals ?? 0),
                'whatsapp_clicks' => (int)($periodStats->whatsapp_clicks ?? 0),
                'viber_clicks' => (int)($periodStats->viber_clicks ?? 0),
                'shares' => (int)($periodStats->shares ?? 0),
                'gallery_opens' => (int)($periodStats->gallery ?? 0),
                'image_views' => (int)($periodStats->images ?? 0),
                'video_plays' => (int)($periodStats->video ?? 0),
                'video_completions' => (int)($periodStats->video_complete ?? 0),
                'map_opens' => (int)($periodStats->map ?? 0),
                'seller_profile_clicks' => (int)($periodStats->seller_clicks ?? 0),
                'search_impressions' => (int)($periodStats->search_imp ?? 0),
                'search_clicks' => (int)($periodStats->search_clicks ?? 0),
                'search_ctr' => $searchCTR,
                'offers' => (int)($periodStats->offers ?? 0),
                'avg_views_per_day' => $avgViewsPerDay,
                'avg_time_on_page' => (int)($periodStats->avg_time ?? 0),
            ],
            'featured' => [
                'days' => (int)($featuredStats->days ?? 0),
                'views' => (int)($featuredStats->views ?? 0),
                'avg_views_per_day' => $avgFeatured,
            ],
            'non_featured' => [
                'days' => (int)($nonFeaturedStats->days ?? 0),
                'views' => (int)($nonFeaturedStats->views ?? 0),
                'avg_views_per_day' => $avgNonFeatured,
            ],
            'featured_improvement_percent' => $featuredImprovement,
            'trends' => [
                'views_vs_yesterday' => $viewsTrend,
            ],
        ];
    }

    /**
     * Dohvati statistiku izvora prometa
     */
    public static function getSourceStats(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        $stats = self::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->selectRaw('
                SUM(source_direct) as direct,
                SUM(source_internal_search) as internal_search,
                SUM(source_category_browse) as category,
                SUM(source_featured_section) as featured,
                SUM(source_similar_items) as similar,
                SUM(source_seller_profile) as seller,
                SUM(source_favorites) as favorites,
                SUM(source_notifications) as notifications,
                SUM(source_google_organic) as google,
                SUM(source_facebook) as facebook,
                SUM(source_instagram) as instagram,
                SUM(source_viber) as viber,
                SUM(source_whatsapp) as whatsapp,
                SUM(source_other_external) as other
            ')
            ->first();

        $total = array_sum([
            (int)($stats->direct ?? 0),
            (int)($stats->internal_search ?? 0),
            (int)($stats->category ?? 0),
            (int)($stats->featured ?? 0),
            (int)($stats->similar ?? 0),
            (int)($stats->seller ?? 0),
            (int)($stats->favorites ?? 0),
            (int)($stats->notifications ?? 0),
            (int)($stats->google ?? 0),
            (int)($stats->facebook ?? 0),
            (int)($stats->instagram ?? 0),
            (int)($stats->viber ?? 0),
            (int)($stats->whatsapp ?? 0),
            (int)($stats->other ?? 0),
        ]);

        $calcPercent = fn($val) => $total > 0 ? round(($val / $total) * 100, 1) : 0;

        return [
            'internal' => [
                ['name' => 'Direktni pristup', 'value' => (int)($stats->direct ?? 0), 'percent' => $calcPercent($stats->direct ?? 0)],
                ['name' => 'Pretraga', 'value' => (int)($stats->internal_search ?? 0), 'percent' => $calcPercent($stats->internal_search ?? 0)],
                ['name' => 'Kategorija', 'value' => (int)($stats->category ?? 0), 'percent' => $calcPercent($stats->category ?? 0)],
                ['name' => 'Istaknuto', 'value' => (int)($stats->featured ?? 0), 'percent' => $calcPercent($stats->featured ?? 0)],
                ['name' => 'Slični oglasi', 'value' => (int)($stats->similar ?? 0), 'percent' => $calcPercent($stats->similar ?? 0)],
                ['name' => 'Profil prodavača', 'value' => (int)($stats->seller ?? 0), 'percent' => $calcPercent($stats->seller ?? 0)],
                ['name' => 'Favoriti', 'value' => (int)($stats->favorites ?? 0), 'percent' => $calcPercent($stats->favorites ?? 0)],
                ['name' => 'Notifikacije', 'value' => (int)($stats->notifications ?? 0), 'percent' => $calcPercent($stats->notifications ?? 0)],
            ],
            'external' => [
                ['name' => 'Google', 'value' => (int)($stats->google ?? 0), 'percent' => $calcPercent($stats->google ?? 0)],
                ['name' => 'Facebook', 'value' => (int)($stats->facebook ?? 0), 'percent' => $calcPercent($stats->facebook ?? 0)],
                ['name' => 'Instagram', 'value' => (int)($stats->instagram ?? 0), 'percent' => $calcPercent($stats->instagram ?? 0)],
                ['name' => 'Viber', 'value' => (int)($stats->viber ?? 0), 'percent' => $calcPercent($stats->viber ?? 0)],
                ['name' => 'WhatsApp', 'value' => (int)($stats->whatsapp ?? 0), 'percent' => $calcPercent($stats->whatsapp ?? 0)],
                ['name' => 'Ostalo', 'value' => (int)($stats->other ?? 0), 'percent' => $calcPercent($stats->other ?? 0)],
            ],
            'total' => $total,
        ];
    }

    /**
     * Dohvati statistiku uređaja
     */
    public static function getDeviceStats(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        $stats = self::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->selectRaw('
                SUM(device_mobile) as mobile,
                SUM(device_desktop) as desktop,
                SUM(device_tablet) as tablet,
                SUM(device_app_ios) as ios,
                SUM(device_app_android) as android
            ')
            ->first();

        $total = (int)($stats->mobile ?? 0) + (int)($stats->desktop ?? 0) + 
                 (int)($stats->tablet ?? 0) + (int)($stats->ios ?? 0) + (int)($stats->android ?? 0);

        $calcPercent = fn($val) => $total > 0 ? round(($val / $total) * 100, 1) : 0;

        return [
            'mobile' => ['value' => (int)($stats->mobile ?? 0), 'percent' => $calcPercent($stats->mobile ?? 0)],
            'desktop' => ['value' => (int)($stats->desktop ?? 0), 'percent' => $calcPercent($stats->desktop ?? 0)],
            'tablet' => ['value' => (int)($stats->tablet ?? 0), 'percent' => $calcPercent($stats->tablet ?? 0)],
            'app_ios' => ['value' => (int)($stats->ios ?? 0), 'percent' => $calcPercent($stats->ios ?? 0)],
            'app_android' => ['value' => (int)($stats->android ?? 0), 'percent' => $calcPercent($stats->android ?? 0)],
            'total' => $total,
        ];
    }

    /**
     * Helper za bosanske dane
     */
    private static function getBosnianDayName(int $dayOfWeek): string
    {
        $days = ['ned', 'pon', 'uto', 'sri', 'čet', 'pet', 'sub'];
        return $days[$dayOfWeek] ?? '';
    }
}
