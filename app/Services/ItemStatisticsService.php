<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemStatistic;
use App\Models\ItemVisitorSession;
use App\Models\ItemSearchImpression;
use App\Models\ItemShare;
use App\Models\ItemContactEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ItemStatisticsService
{
    // ═══════════════════════════════════════════
    // BILJEŽENJE PREGLEDA
    // ═══════════════════════════════════════════

    /**
     * Zabilježi pregled oglasa (glavna metoda)
     */
    public function recordView(int $itemId, array $data = []): void
    {
        $visitorId = $data['visitor_id'] ?? $this->generateVisitorId();
        $isUnique = $this->isUniqueView($itemId, $visitorId);
        $isReturning = !$isUnique && $this->hasVisitedBefore($itemId, $visitorId);

        // Inkrementiraj clicks na items tabeli (postojeće ponašanje)
        Item::where('id', $itemId)->increment('clicks');

        // Dnevna statistika
        $stats = [
            'views' => 1,
            'unique_views' => $isUnique ? 1 : 0,
            'returning_views' => $isReturning ? 1 : 0,
        ];

        // Izvor prometa
        $source = $data['source'] ?? ItemVisitorSession::detectSource($data);
        $sourceColumn = $this->getSourceColumn($source);
        if ($sourceColumn) {
            $stats[$sourceColumn] = 1;
        }

        // Uređaj
        $deviceType = $data['device_type'] ?? ItemVisitorSession::detectDeviceType($data['user_agent'] ?? null);
        $deviceColumn = $this->getDeviceColumn($deviceType, $data['is_app'] ?? false, $data['app_platform'] ?? null);
        if ($deviceColumn) {
            $stats[$deviceColumn] = 1;
        }

        ItemStatistic::incrementMultiple($itemId, $stats);

        // Geografija
        if (!empty($data['country_code'])) {
            ItemStatistic::incrementJsonField($itemId, 'geo_countries', $data['country_code']);
        }
        if (!empty($data['city'])) {
            ItemStatistic::incrementJsonField($itemId, 'geo_cities', $data['city']);
        }

        // Sat pregleda
        $hour = Carbon::now()->hour;
        ItemStatistic::incrementJsonField($itemId, 'hourly_views', (string)$hour);

        // Kreiraj/nastavi visitor session
        ItemVisitorSession::startOrContinue($itemId, array_merge($data, [
            'visitor_id' => $visitorId,
            'source' => $source,
        ]));

        // Ažuriraj unique visitors na items tabeli
        if ($isUnique) {
            Item::where('id', $itemId)->increment('total_unique_visitors');
        }
    }

    /**
     * Zabilježi vrijeme provedeno na stranici
     */
    public function recordTimeOnPage(int $itemId, int $seconds, string $visitorId): void
    {
        // Ažuriraj sesiju
        $session = ItemVisitorSession::where('item_id', $itemId)
            ->where('visitor_id', $visitorId)
            ->latest('started_at')
            ->first();

        if ($session) {
            $session->update([
                'duration_seconds' => $seconds,
                'ended_at' => Carbon::now(),
            ]);
        }

        // Ažuriraj prosjek
        ItemStatistic::updateAverage($itemId, 'avg_time_on_page', $seconds);

        // Ažuriraj items tabelu
        $item = Item::find($itemId);
        if ($item) {
            $currentAvg = $item->avg_time_on_page ?? 0;
            $totalViews = $item->clicks ?: 1;
            $newAvg = $currentAvg + (($seconds - $currentAvg) / $totalViews);
            $item->update(['avg_time_on_page' => (int)$newAvg]);
        }

        // Bounce detection (ako je manje od 10 sekundi)
        if ($seconds < 10) {
            ItemStatistic::incrementStat($itemId, 'bounce_count');
        }
    }

    // ═══════════════════════════════════════════
    // BILJEŽENJE ENGAGEMENTA
    // ═══════════════════════════════════════════

    /**
     * Zabilježi otvaranje galerije
     */
    public function recordGalleryOpen(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'gallery_opens');
        Item::where('id', $itemId)->increment('total_gallery_views');
        
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'gallery_open');
    }

    /**
     * Zabilježi pregled slike
     */
    public function recordImageView(int $itemId, int $imageIndex = 0, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'image_views');
        
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'image_view', ['index' => $imageIndex]);
    }

    /**
     * Zabilježi zumiranje slike
     */
    public function recordImageZoom(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'image_zooms');
    }

    /**
     * Zabilježi pokretanje videa
     */
    public function recordVideoPlay(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'video_plays');
        Item::where('id', $itemId)->increment('total_video_plays');
        
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'video_play');
    }

    /**
     * Zabilježi napredak videa
     */
    public function recordVideoProgress(int $itemId, int $percent, array $data = []): void
    {
        $column = match(true) {
            $percent >= 100 => 'video_completions',
            $percent >= 75 => 'video_75_percent',
            $percent >= 50 => 'video_50_percent',
            $percent >= 25 => 'video_25_percent',
            default => null,
        };

        if ($column) {
            ItemStatistic::incrementStat($itemId, $column);
        }
    }

    /**
     * Zabilježi proširenje opisa
     */
    public function recordDescriptionExpand(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'description_expands');
    }

    /**
     * Zabilježi pregled lokacije/mape
     */
    public function recordMapView(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'map_opens');
        
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'map_open');
    }

    /**
     * Zabilježi klik na upute do lokacije
     */
    public function recordMapDirections(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'map_directions');
    }

    /**
     * Zabilježi klik na profil prodavača
     */
    public function recordSellerProfileClick(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'seller_profile_clicks');
        
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'seller_click');
    }

    /**
     * Zabilježi klik na druge oglase prodavača
     */
    public function recordSellerOtherItemsClick(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'seller_other_items_clicks');
    }

    /**
     * Zabilježi klik na slične oglase
     */
    public function recordSimilarItemClick(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'similar_items_clicks');
    }

    // ═══════════════════════════════════════════
    // BILJEŽENJE KONTAKTA
    // ═══════════════════════════════════════════

    /**
     * Zabilježi otkrivanje telefona
     */
    public function recordPhoneReveal(int $itemId, array $data = []): void
    {
        ItemContactEvent::recordPhoneReveal($itemId, $data);
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'phone_reveal');
    }

    /**
     * Zabilježi klik na poziv
     */
    public function recordPhoneClick(int $itemId, array $data = []): void
    {
        ItemContactEvent::recordPhoneClick($itemId, $data);
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'phone_click');
    }

    /**
     * Zabilježi WhatsApp klik
     */
    public function recordWhatsAppClick(int $itemId, array $data = []): void
    {
        ItemContactEvent::recordWhatsApp($itemId, $data);
        ItemStatistic::incrementStat($itemId, 'whatsapp_clicks');
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'whatsapp_click');
    }

    /**
     * Zabilježi Viber klik
     */
    public function recordViberClick(int $itemId, array $data = []): void
    {
        ItemContactEvent::recordViber($itemId, $data);
        ItemStatistic::incrementStat($itemId, 'viber_clicks');
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'viber_click');
    }

    /**
     * Zabilježi slanje poruke
     */
    public function recordMessageSent(int $itemId, array $data = []): void
    {
        ItemContactEvent::recordMessage($itemId, $data);
        ItemStatistic::incrementStat($itemId, 'messages_total');
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'message_send');
    }

    /**
     * Zabilježi ponudu
     */
    public function recordOfferMade(int $itemId, ?float $amount = null, array $data = []): void
    {
        ItemContactEvent::recordOffer($itemId, $data);
        
        if ($amount !== null) {
            // Ažuriraj prosječnu ponudu
            $todayStats = ItemStatistic::getOrCreateForToday($itemId);
            $currentAvg = $todayStats->offers_avg_amount ?? 0;
            $currentCount = $todayStats->offers_received ?? 0;
            
            if ($currentCount > 0) {
                $newAvg = (($currentAvg * $currentCount) + $amount) / ($currentCount + 1);
            } else {
                $newAvg = $amount;
            }
            
            $todayStats->update(['offers_avg_amount' => $newAvg]);
        }
        
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'offer_make', ['amount' => $amount]);
    }

    // ═══════════════════════════════════════════
    // BILJEŽENJE DIJELJENJA
    // ═══════════════════════════════════════════

    /**
     * Zabilježi dijeljenje
     */
    public function recordShare(int $itemId, string $platform, array $data = []): ItemShare
    {
        $share = ItemShare::recordShare($itemId, $platform, $data);
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'share', ['platform' => $platform]);
        
        return $share;
    }

    /**
     * Zabilježi klik sa dijeljenog linka
     */
    public function recordShareClick(string $shareToken): void
    {
        $share = ItemShare::findByToken($shareToken);
        if ($share) {
            $share->recordClick();
        }
    }

    // ═══════════════════════════════════════════
    // BILJEŽENJE FAVORITA
    // ═══════════════════════════════════════════

    /**
     * Zabilježi dodavanje u favorite
     */
    public function recordFavoriteAdd(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementMultiple($itemId, [
            'favorites_added' => 1,
            'favorites_net' => 1,
        ]);
        
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'favorite_add');
    }

    /**
     * Zabilježi uklanjanje iz favorita
     */
    public function recordFavoriteRemove(int $itemId, array $data = []): void
    {
        $todayStats = ItemStatistic::getOrCreateForToday($itemId);
        $todayStats->increment('favorites_removed');
        $todayStats->decrement('favorites_net');
    }

    // ═══════════════════════════════════════════
    // BILJEŽENJE PRETRAGE
    // ═══════════════════════════════════════════

    /**
     * Zabilježi pojavljivanje u pretrazi (batch)
     */
    public function recordSearchImpressions(array $items, array $searchData): void
    {
        ItemSearchImpression::recordBatch($items, $searchData);
    }

    /**
     * Zabilježi klik iz pretrage
     */
    public function recordSearchClick(int $impressionId): void
    {
        $impression = ItemSearchImpression::find($impressionId);
        if ($impression) {
            $impression->recordClick();
        }
    }

    // ═══════════════════════════════════════════
    // DOHVATANJE STATISTIKE
    // ═══════════════════════════════════════════

    /**
     * Dohvati kompletnu statistiku za oglas
     */
    public function getFullStatistics(int $itemId, int $days = 30): array
    {
        $item = Item::with(['category', 'user', 'favourites'])->find($itemId);
        
        if (!$item) {
            return ['error' => 'Item not found'];
        }

        return [
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'price' => $item->price,
                'created_at' => $item->created_at->toISOString(),
                'days_active' => $item->created_at->diffInDays(Carbon::now()),
                'status' => $item->status,
                'is_featured' => $item->featured_items()->exists(),
                'category' => $item->category?->name,
                'total_clicks' => $item->clicks,
                'total_favorites' => $item->favourites->count(),
            ],
            'summary' => ItemStatistic::getSummaryStats($itemId, $days),
            'daily' => ItemStatistic::getStatsForPeriod($itemId, $days),
            'sources' => ItemStatistic::getSourceStats($itemId, $days),
            'devices' => ItemStatistic::getDeviceStats($itemId, $days),
            'engagement' => ItemVisitorSession::getEngagementStats($itemId, $days),
            'shares' => ItemShare::getShareStats($itemId, $days),
            'virality' => ItemShare::getViralityScore($itemId, $days),
            'contacts' => ItemContactEvent::getContactStats($itemId, $days),
            'funnel' => ItemContactEvent::getFunnelStats($itemId, $days),
            'search' => ItemSearchImpression::getSearchStats($itemId, $days),
            'competitive' => ItemSearchImpression::getCompetitiveStats($itemId, $days),
            'category_comparison' => $this->getCategoryComparison($item, $days),
        ];
    }

    /**
     * Dohvati uporedbu sa kategorijom
     */
    public function getCategoryComparison(Item $item, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        // Moja statistika
        $myStats = ItemStatistic::where('item_id', $item->id)
            ->where('date', '>=', $startDate)
            ->selectRaw('SUM(views) as views, SUM(messages_started) as messages')
            ->first();

        $myViews = (int)($myStats->views ?? 0);

        // Statistika kategorije - pojednostavljena verzija
        $categoryItemIds = Item::where('category_id', $item->category_id)
            ->where('id', '!=', $item->id)
            ->pluck('id');

        if ($categoryItemIds->isEmpty()) {
            return [
                'my_views' => $myViews,
                'category_avg_views' => 0,
                'percent_difference' => 0,
                'better_than_percent' => 100,
                'items_in_category' => 0,
                'performance_label' => 'Jedini u kategoriji',
            ];
        }

        // Prosječni pregledi u kategoriji
        $categoryStats = ItemStatistic::whereIn('item_id', $categoryItemIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('item_id, SUM(views) as total_views')
            ->groupBy('item_id')
            ->get();

        $itemCount = $categoryStats->count();
        
        if ($itemCount === 0) {
            return [
                'my_views' => $myViews,
                'category_avg_views' => 0,
                'percent_difference' => 0,
                'better_than_percent' => 100,
                'items_in_category' => 0,
                'performance_label' => 'Nema podataka',
            ];
        }

        $avgViews = $categoryStats->avg('total_views') ?? 0;
        $betterThanCount = $categoryStats->where('total_views', '<', $myViews)->count();

        $percentile = $itemCount > 0 ? round(($betterThanCount / $itemCount) * 100) : 100;
        $percentDiff = $avgViews > 0 ? round((($myViews - $avgViews) / $avgViews) * 100) : 0;

        return [
            'my_views' => $myViews,
            'category_avg_views' => round($avgViews),
            'percent_difference' => $percentDiff,
            'better_than_percent' => $percentile,
            'items_in_category' => $itemCount,
            'performance_label' => $this->getPerformanceLabel($percentDiff),
        ];
    }

    // ═══════════════════════════════════════════
    // HELPER METODE
    // ═══════════════════════════════════════════

    private function generateVisitorId(): string
    {
        $ip = request()->ip();
        $userAgent = request()->userAgent();
        return md5($ip . '_' . $userAgent);
    }

    private function isUniqueView(int $itemId, string $visitorId): bool
    {
        // Provjeri da li je ovaj visitor već gledao ovaj oglas danas
        return !ItemVisitorSession::where('item_id', $itemId)
            ->where('visitor_id', $visitorId)
            ->whereDate('started_at', Carbon::today())
            ->exists();
    }

    private function hasVisitedBefore(int $itemId, string $visitorId): bool
    {
        return ItemVisitorSession::where('item_id', $itemId)
            ->where('visitor_id', $visitorId)
            ->whereDate('started_at', '<', Carbon::today())
            ->exists();
    }

    private function getSourceColumn(string $source): ?string
    {
        return match($source) {
            'direct' => 'source_direct',
            'internal_search' => 'source_internal_search',
            'category_browse' => 'source_category_browse',
            'featured_section' => 'source_featured_section',
            'similar_items' => 'source_similar_items',
            'seller_profile' => 'source_seller_profile',
            'favorites' => 'source_favorites',
            'notifications' => 'source_notifications',
            'chat' => 'source_chat',
            'email_campaign' => 'source_email_campaign',
            'push_notification' => 'source_push_notification',
            'google_organic' => 'source_google_organic',
            'google_ads' => 'source_google_ads',
            'facebook' => 'source_facebook',
            'instagram' => 'source_instagram',
            'viber' => 'source_viber',
            'whatsapp' => 'source_whatsapp',
            'twitter' => 'source_twitter',
            'tiktok' => 'source_tiktok',
            'youtube' => 'source_youtube',
            'linkedin' => 'source_linkedin',
            default => 'source_other_external',
        };
    }

    private function getDeviceColumn(string $deviceType, bool $isApp = false, ?string $appPlatform = null): ?string
    {
        if ($isApp) {
            return $appPlatform === 'ios' ? 'device_app_ios' : 'device_app_android';
        }

        return match($deviceType) {
            'mobile' => 'device_mobile',
            'desktop' => 'device_desktop',
            'tablet' => 'device_tablet',
            default => null,
        };
    }

    private function logSessionAction(int $itemId, ?string $visitorId, string $action, array $extra = []): void
    {
        if (!$visitorId) {
            $visitorId = $this->generateVisitorId();
        }

        $session = ItemVisitorSession::where('item_id', $itemId)
            ->where('visitor_id', $visitorId)
            ->where('started_at', '>=', Carbon::now()->subMinutes(30))
            ->latest('started_at')
            ->first();

        if ($session) {
            $session->logAction($action, $extra);
        }
    }

    private function getPerformanceLabel(int $percentDiff): string
    {
        return match(true) {
            $percentDiff >= 200 => 'Izvanredan 🚀',
            $percentDiff >= 100 => 'Odličan 🔥',
            $percentDiff >= 50 => 'Vrlo dobar',
            $percentDiff >= 0 => 'Iznad prosjeka',
            $percentDiff >= -25 => 'Prosječan',
            $percentDiff >= -50 => 'Ispod prosjeka',
            default => 'Potrebno poboljšanje',
        };
    }

    // ═══════════════════════════════════════════
    // METODE KOJE KORISTI CONTROLLER
    // ═══════════════════════════════════════════

    /**
     * Quick stats za prikaz na kartici
     */
    public function getQuickStats(int $itemId): array
    {
        $item = Item::with('favourites')->find($itemId);
        
        if (!$item) {
            return [];
        }

        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $weekAgo = Carbon::today()->subDays(6);

        $todayStats = ItemStatistic::where('item_id', $itemId)->where('date', $today)->first();
        $yesterdayStats = ItemStatistic::where('item_id', $itemId)->where('date', $yesterday)->first();
        
        $weekStats = ItemStatistic::where('item_id', $itemId)
            ->where('date', '>=', $weekAgo)
            ->selectRaw('SUM(views) as views, SUM(phone_clicks) as phone_clicks, SUM(messages_started) as messages')
            ->first();

        return [
            'total_views' => $item->clicks ?? 0,
            'total_favorites' => $item->favourites->count(),
            'total_messages' => $item->total_messages ?? 0,
            'total_phone_clicks' => $item->total_phone_clicks ?? 0,
            'today' => [
                'views' => $todayStats->views ?? 0,
                'phone_clicks' => $todayStats->phone_clicks ?? 0,
                'messages' => $todayStats->messages_started ?? 0,
            ],
            'yesterday' => [
                'views' => $yesterdayStats->views ?? 0,
            ],
            'last_7_days' => [
                'views' => (int)($weekStats->views ?? 0),
                'phone_clicks' => (int)($weekStats->phone_clicks ?? 0),
                'messages' => (int)($weekStats->messages ?? 0),
            ],
            'trend' => $this->calculateTrend($todayStats->views ?? 0, $yesterdayStats->views ?? 0),
        ];
    }

    /**
     * Zabilježi kontakt (wrapper za različite tipove)
     */
    public function recordContact(int $itemId, string $contactType, array $data = []): void
    {
        match($contactType) {
            'phone_reveal' => $this->recordPhoneReveal($itemId, $data),
            'phone_click', 'phone_call' => $this->recordPhoneClick($itemId, $data),
            'whatsapp' => $this->recordWhatsAppClick($itemId, $data),
            'viber' => $this->recordViberClick($itemId, $data),
            'telegram' => $this->recordTelegramClick($itemId, $data),
            'email' => $this->recordEmailClick($itemId, $data),
            'message' => $this->recordMessageSent($itemId, $data),
            'offer' => $this->recordOfferMade($itemId, $data['amount'] ?? null, $data),
            default => null,
        };
    }

    /**
     * Zabilježi Telegram klik
     */
    public function recordTelegramClick(int $itemId, array $data = []): void
    {
        ItemContactEvent::record($itemId, 'telegram', $data);
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'telegram_click');
    }

    /**
     * Zabilježi Email klik
     */
    public function recordEmailClick(int $itemId, array $data = []): void
    {
        ItemContactEvent::record($itemId, 'email', $data);
        ItemStatistic::incrementStat($itemId, 'email_clicks');
        $this->logSessionAction($itemId, $data['visitor_id'] ?? null, 'email_click');
    }

    /**
     * Zabilježi engagement (wrapper)
     */
    public function recordEngagement(int $itemId, string $engagementType, array $extraData = []): void
    {
        match($engagementType) {
            'gallery_open' => $this->recordGalleryOpen($itemId, $extraData),
            'image_view' => $this->recordImageView($itemId, $extraData['image_index'] ?? 0, $extraData),
            'image_zoom' => $this->recordImageZoom($itemId, $extraData),
            'image_download' => $this->recordImageDownload($itemId, $extraData),
            'video_play' => $this->recordVideoPlay($itemId, $extraData),
            'video_25' => $this->recordVideoProgress($itemId, 25, $extraData),
            'video_50' => $this->recordVideoProgress($itemId, 50, $extraData),
            'video_75' => $this->recordVideoProgress($itemId, 75, $extraData),
            'video_complete' => $this->recordVideoProgress($itemId, 100, $extraData),
            'description_expand' => $this->recordDescriptionExpand($itemId, $extraData),
            'specifications_view' => $this->recordSpecificationsView($itemId, $extraData),
            'location_view' => $this->recordLocationView($itemId, $extraData),
            'map_open' => $this->recordMapView($itemId, $extraData),
            'map_directions' => $this->recordMapDirections($itemId, $extraData),
            'seller_profile_click' => $this->recordSellerProfileClick($itemId, $extraData),
            'seller_other_items_click' => $this->recordSellerOtherItemsClick($itemId, $extraData),
            'similar_items_click' => $this->recordSimilarItemClick($itemId, $extraData),
            'price_history_view' => $this->recordPriceHistoryView($itemId, $extraData),
            default => null,
        };
    }

    /**
     * Zabilježi preuzimanje slike
     */
    public function recordImageDownload(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'image_downloads');
    }

    /**
     * Zabilježi pregled specifikacija
     */
    public function recordSpecificationsView(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'specifications_views');
    }

    /**
     * Zabilježi pregled lokacije
     */
    public function recordLocationView(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'location_views');
    }

    /**
     * Zabilježi pregled historije cijena
     */
    public function recordPriceHistoryView(int $itemId, array $data = []): void
    {
        ItemStatistic::incrementStat($itemId, 'price_history_views');
    }

    /**
     * Ažuriraj vrijeme na stranici
     */
    public function updateTimeOnPage(int $itemId, int $duration, array $data = []): void
    {
        $visitorId = $data['visitor_id'] ?? $this->generateVisitorId();
        $this->recordTimeOnPage($itemId, $duration, $visitorId);
    }

    /**
     * Zabilježi search impression
     */
    public function recordSearchImpression(int $itemId, array $data): void
    {
        ItemSearchImpression::record($itemId, [
            'visitor_id' => $data['visitor_id'] ?? null,
            'user_id' => $data['user_id'] ?? auth()->id(),
            'search_query' => $data['search_query'] ?? null,
            'search_type' => $data['search_type'] ?? 'general',
            'filters_applied' => $data['filters'] ?? null,
            'position' => $data['position'],
            'page' => $data['page'] ?? 1,
            'results_total' => $data['results_total'] ?? null,
            'was_featured' => $data['was_featured'] ?? false,
            'device_type' => $data['device_type'] ?? null,
        ]);
    }

    /**
     * Zabilježi batch search impressions
     */
    public function recordBatchSearchImpressions(array $itemIds, array $commonData): void
    {
        $items = [];
        foreach ($itemIds as $index => $itemId) {
            $item = Item::find($itemId);
            if ($item) {
                $items[] = [
                    'id' => $itemId,
                    'is_featured' => $item->featured_items()->exists(),
                ];
            }
        }

        if (!empty($items)) {
            ItemSearchImpression::recordBatch($items, [
                'visitor_id' => $commonData['visitor_id'] ?? null,
                'user_id' => $commonData['user_id'] ?? auth()->id(),
                'search_query' => $commonData['search_query'] ?? null,
                'search_type' => $commonData['search_type'] ?? 'general',
                'filters_applied' => $commonData['filters'] ?? null,
                'page' => $commonData['page'] ?? 1,
                'per_page' => $commonData['per_page'] ?? 20,
                'results_total' => $commonData['results_total'] ?? null,
                'device_type' => $commonData['device_type'] ?? null,
            ]);
        }
    }

    /**
     * Zabilježi favorit (add/remove)
     */
    public function recordFavorite(int $itemId, bool $added, array $data = []): void
    {
        if ($added) {
            $this->recordFavoriteAdd($itemId, $data);
        } else {
            $this->recordFavoriteRemove($itemId, $data);
        }
    }

    /**
     * Izračunaj trend
     */
    private function calculateTrend(int $current, int $previous): array
    {
        if ($previous === 0) {
            return [
                'direction' => $current > 0 ? 'up' : 'neutral',
                'percent' => $current > 0 ? 100 : 0,
            ];
        }

        $change = (($current - $previous) / $previous) * 100;
        
        return [
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral'),
            'percent' => abs(round($change)),
        ];
    }
}