<?php
 
namespace App\Http\Controllers\Api;
 
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemStatistic;
use App\Models\ItemSearchImpression;
use App\Models\ItemContactEvent;
use App\Models\ItemShare;
use App\Models\ItemVisitorSession;
use App\Models\UserMembership;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Throwable;
 
class ItemStatisticsController extends Controller
{
    // ═══════════════════════════════════════════
    // GLAVNA METODA - DOHVATI STATISTIKU
    // ═══════════════════════════════════════════
 
    /**
     * Dohvati kompletnu statistiku za oglas
     * GET /api/item-statistics/{itemId}?period=30
     */
    public function getStatistics(Request $request, int $itemId)
    {
        try {
            $validator = Validator::make(['item_id' => $itemId, ...$request->all()], [
                'item_id' => 'required|integer|exists:items,id',
                'period' => 'nullable|integer|min:1|max:365',
            ]);
 
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
 
            // Provjeri da li je korisnik vlasnik oglasa
            $item = Item::find($itemId);
            if (!$item || $item->user_id !== Auth::id()) {
                return ResponseService::errorResponse('Nemate pristup statistici ovog oglasa', null, 403);
            }
 
            $period = (int) ($request->period ?? 30);
            $userId = Auth::id();
 
            // Dohvati membership tier
            $membershipTier = $this->getUserMembershipTier($userId);
            $isPro = in_array($membershipTier, ['pro', 'shop']);
            $isShop = $membershipTier === 'shop';
 
            // ═══════════════════════════════════════════
            // BASIC STATISTIKA (svi korisnici)
            // ═══════════════════════════════════════════
            $summary = ItemStatistic::getSummaryStats($itemId, $period);
            $daily = ItemStatistic::getStatsForPeriod($itemId, $period);
            $sources = ItemStatistic::getSourceStats($itemId, $period);
            $devices = ItemStatistic::getDeviceStats($itemId, $period);
 
            // Funnel konverzije (basic verzija)
            $funnel = $this->getConversionFunnel($itemId, $period);
 
            $response = [
                'item_id' => $itemId,
                'period' => $period,
                'membership_tier' => $membershipTier,
                'summary' => $summary,
                'daily' => $daily,
                'sources' => $sources,
                'devices' => $devices,
                'funnel' => $funnel,
            ];
 
            // ═══════════════════════════════════════════
            // PRO STATISTIKA (Pro & Shop)
            // ═══════════════════════════════════════════
            if ($isPro) {
                // Pojmovi pretrage koji dovode do oglasa
                $response['search_terms'] = $this->getSearchTerms($itemId, $period);
                
                // Pozicija na stranicama pretrage
                $response['search_positions'] = $this->getSearchPositions($itemId, $period);
                
                // Detaljni kontakti po tipu
                $response['contact_breakdown'] = $this->getContactBreakdown($itemId, $period);
                
                // Dijeljenja po platformama
                $response['share_breakdown'] = $this->getShareBreakdown($itemId, $period);
            }
 
            // ═══════════════════════════════════════════
            // SHOP STATISTIKA (samo Shop)
            // ═══════════════════════════════════════════
            if ($isShop) {
                // Pregledi po satima
                $response['hourly'] = $this->getHourlyStats($itemId, $period);
                
                // Geografska distribucija
                $response['geo'] = $this->getGeoStats($itemId, $period);
                
                // Konkurentska analiza
                $response['competition'] = $this->getCompetitionStats($item);
                
                // Detaljna konverzija
                $response['conversion_detailed'] = $this->getDetailedConversion($itemId, $period);
            }
 
            return ResponseService::successResponse('Statistika uspješno dohvaćena', $response);
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> getStatistics');
            return ResponseService::errorResponse('Greška pri dohvatanju statistike');
        }
    }
 
    /**
     * Dohvati brzu statistiku za prikaz u listi oglasa
     * GET /api/item-statistics/{itemId}/quick
     */
    public function getQuickStats(Request $request, int $itemId)
    {
        try {
            $item = Item::find($itemId);
            if (!$item || $item->user_id !== Auth::id()) {
                return ResponseService::errorResponse('Nemate pristup', null, 403);
            }
 
            $today = Carbon::today();
            $todayStats = ItemStatistic::where('item_id', $itemId)
                ->where('date', $today)
                ->first();
 
            // Ukupna statistika (svih vremena)
            $totalStats = ItemStatistic::where('item_id', $itemId)
                ->selectRaw('
                    SUM(views) as total_views,
                    SUM(favorites_added) as total_favorites,
                    SUM(phone_clicks) as total_phone_clicks,
                    SUM(messages_started) as total_messages,
                    SUM(shares_total) as total_shares
                ')
                ->first();
 
            // Trend (zadnjih 7 dana vs prethodnih 7)
            $last7 = ItemStatistic::where('item_id', $itemId)
                ->where('date', '>=', Carbon::today()->subDays(6))
                ->sum('views');
            
            $prev7 = ItemStatistic::where('item_id', $itemId)
                ->whereBetween('date', [Carbon::today()->subDays(13), Carbon::today()->subDays(7)])
                ->sum('views');
 
            $viewsTrend = $prev7 > 0 ? round((($last7 - $prev7) / $prev7) * 100) : 0;
 
            return ResponseService::successResponse('Quick stats', [
                'today_views' => $todayStats->views ?? 0,
                'total_views' => (int) ($totalStats->total_views ?? 0),
                'total_favorites' => (int) ($totalStats->total_favorites ?? 0),
                'total_phone_clicks' => (int) ($totalStats->total_phone_clicks ?? 0),
                'total_messages' => (int) ($totalStats->total_messages ?? 0),
                'total_shares' => (int) ($totalStats->total_shares ?? 0),
                'views_trend' => $viewsTrend,
            ]);
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> getQuickStats');
            return ResponseService::errorResponse('Greška');
        }
    }
 
    // ═══════════════════════════════════════════
    // TRACKING METODE
    // ═══════════════════════════════════════════
 
    /**
     * Track pregled oglasa
     */
    public function trackView(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'source' => 'nullable|string|max:50',
                'source_detail' => 'nullable|string|max:100',
                'referrer_url' => 'nullable|string|max:500',
                'visitor_id' => 'nullable|string|max:100',
                'device_type' => 'nullable|in:mobile,desktop,tablet,app_ios,app_android',
            ]);
 
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
 
            $itemId = $request->item_id;
            $visitorId = $request->visitor_id ?? $request->ip();
            $deviceType = $request->device_type ?? $this->detectDeviceType($request);
            $source = $request->source ?? 'direct';
 
            // Provjeri da li je unique view (isti visitor u zadnjih 24h)
            $isUnique = !ItemVisitorSession::where('item_id', $itemId)
                ->where('visitor_id', $visitorId)
                ->where('created_at', '>=', Carbon::now()->subHours(24))
                ->exists();
 
            // Kreiraj sesiju
            ItemVisitorSession::create([
                'item_id' => $itemId,
                'visitor_id' => $visitorId,
                'user_id' => Auth::id(),
                'device_type' => $deviceType,
                'source' => $source,
                'source_detail' => $request->source_detail,
                'referrer_url' => $request->referrer_url,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
 
            // Inkrementiraj statistiku
            $stats = ['views' => 1];
            
            if ($isUnique) {
                $stats['unique_views'] = 1;
            } else {
                $stats['returning_views'] = 1;
            }
 
            // Uređaj
            $deviceField = 'device_' . $deviceType;
            if (in_array($deviceField, ['device_mobile', 'device_desktop', 'device_tablet', 'device_app_ios', 'device_app_android'])) {
                $stats[$deviceField] = 1;
            }
 
            // Izvor
            $sourceField = $this->mapSourceToField($source);
            if ($sourceField) {
                $stats[$sourceField] = 1;
            }
 
            ItemStatistic::incrementMultiple($itemId, $stats);
 
            return ResponseService::successResponse('View tracked');
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackView');
            return ResponseService::errorResponse('Greška');
        }
    }
 
    /**
     * Track kontakt (poziv, whatsapp, viber, email)
     */
    public function trackContact(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'contact_type' => 'required|in:phone_click,phone_reveal,whatsapp,viber,email,message',
            ]);
 
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
 
            $itemId = $request->item_id;
            $contactType = $request->contact_type;
 
            // Sačuvaj event
            ItemContactEvent::create([
                'item_id' => $itemId,
                'user_id' => Auth::id(),
                'contact_type' => $contactType,
                'ip_address' => $request->ip(),
            ]);
 
            // Map contact type to stat field
            $fieldMap = [
                'phone_click' => 'phone_clicks',
                'phone_reveal' => 'phone_reveals',
                'whatsapp' => 'whatsapp_clicks',
                'viber' => 'viber_clicks',
                'email' => 'email_clicks',
                'message' => 'messages_started',
            ];
 
            $field = $fieldMap[$contactType] ?? null;
            if ($field) {
                ItemStatistic::incrementStat($itemId, $field);
            }
 
            return ResponseService::successResponse('Contact tracked');
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackContact');
            return ResponseService::errorResponse('Greška');
        }
    }
 
    /**
     * Track dijeljenje
     */
    public function trackShare(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'platform' => 'required|string|max:30',
            ]);
 
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
 
            $itemId = $request->item_id;
            $platform = strtolower($request->platform);
 
            // Sačuvaj share
            ItemShare::create([
                'item_id' => $itemId,
                'user_id' => Auth::id(),
                'platform' => $platform,
                'ip_address' => $request->ip(),
            ]);
 
            // Inkrementiraj ukupne i specifične
            $stats = ['shares_total' => 1];
            
            $platformField = 'share_' . $platform;
            $allowedPlatforms = ['facebook', 'messenger', 'instagram', 'viber', 'whatsapp', 
                                 'twitter', 'linkedin', 'telegram', 'email', 'sms', 'copy_link', 'qr_code', 'print'];
            
            if (in_array($platform, $allowedPlatforms)) {
                $stats[$platformField] = 1;
            }
 
            ItemStatistic::incrementMultiple($itemId, $stats);
 
            return ResponseService::successResponse('Share tracked');
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackShare');
            return ResponseService::errorResponse('Greška');
        }
    }
 
    /**
     * Track engagement (galerija, video, mapa, itd)
     */
    public function trackEngagement(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'engagement_type' => 'required|string|max:50',
            ]);
 
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
 
            $itemId = $request->item_id;
            $engagementType = $request->engagement_type;
 
            $fieldMap = [
                'gallery_open' => 'gallery_opens',
                'image_view' => 'image_views',
                'image_zoom' => 'image_zooms',
                'image_download' => 'image_downloads',
                'video_play' => 'video_plays',
                'video_complete' => 'video_completions',
                'video_25' => 'video_25_percent',
                'video_50' => 'video_50_percent',
                'video_75' => 'video_75_percent',
                'description_expand' => 'description_expands',
                'specs_view' => 'specifications_views',
                'location_view' => 'location_views',
                'map_open' => 'map_opens',
                'map_directions' => 'map_directions',
                'seller_profile' => 'seller_profile_clicks',
                'seller_other_items' => 'seller_other_items_clicks',
                'similar_items' => 'similar_items_clicks',
                'price_history' => 'price_history_views',
            ];
 
            $field = $fieldMap[$engagementType] ?? null;
            if ($field) {
                ItemStatistic::incrementStat($itemId, $field);
            }
 
            return ResponseService::successResponse('Engagement tracked');
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackEngagement');
            return ResponseService::errorResponse('Greška');
        }
    }
 
    /**
     * Track vrijeme na stranici
     */
    public function trackTimeOnPage(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'duration' => 'required|integer|min:1|max:3600',
            ]);
 
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
 
            ItemStatistic::updateAverage($request->item_id, 'avg_time_on_page', $request->duration);
 
            return ResponseService::successResponse('Time tracked');
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackTimeOnPage');
            return ResponseService::errorResponse('Greška');
        }
    }
 
    /**
     * Track favorit
     */
    public function trackFavorite(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'added' => 'required|boolean',
            ]);
 
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
 
            $field = $request->added ? 'favorites_added' : 'favorites_removed';
            ItemStatistic::incrementStat($request->item_id, $field);
 
            // Update net
            $record = ItemStatistic::getOrCreateForToday($request->item_id);
            $record->favorites_net = ($record->favorites_added ?? 0) - ($record->favorites_removed ?? 0);
            $record->save();
 
            return ResponseService::successResponse('Favorite tracked');
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackFavorite');
            return ResponseService::errorResponse('Greška');
        }
    }
 
    /**
     * Track search impressions (batch)
     */
    public function trackBatchSearchImpressions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_ids' => 'required',
                'search_query' => 'nullable|string|max:200',
                'page' => 'nullable|integer|min:1',
            ]);
 
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
 
            $itemIds = is_array($request->item_ids) 
                ? $request->item_ids 
                : json_decode($request->item_ids, true);
 
            if (!is_array($itemIds)) {
                return ResponseService::errorResponse('Invalid item_ids format');
            }
 
            $searchQuery = $request->search_query;
            $page = $request->page ?? 1;
 
            foreach ($itemIds as $index => $itemId) {
                $position = (($page - 1) * 20) + $index + 1; // Pretpostavljamo 20 rezultata po stranici
 
                // Sačuvaj impression
                ItemSearchImpression::create([
                    'item_id' => $itemId,
                    'search_query' => $searchQuery,
                    'page' => $page,
                    'position' => $position,
                    'visitor_id' => $request->visitor_id ?? $request->ip(),
                    'clicked' => false,
                ]);
 
                // Inkrementiraj statistiku
                ItemStatistic::incrementStat($itemId, 'search_impressions');
                
                // Update average position
                ItemStatistic::updateAverage($itemId, 'search_position_avg', $position);
            }
 
            return ResponseService::successResponse('Impressions tracked');
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackBatchSearchImpressions');
            return ResponseService::errorResponse('Greška');
        }
    }
 
    /**
     * Track search click
     */
    public function trackSearchClick(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'impression_id' => 'required|integer|exists:item_search_impressions,id',
            ]);
 
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
 
            $impression = ItemSearchImpression::find($request->impression_id);
            if ($impression && !$impression->clicked) {
                $impression->update(['clicked' => true, 'clicked_at' => now()]);
                ItemStatistic::incrementStat($impression->item_id, 'search_clicks');
            }
 
            return ResponseService::successResponse('Click tracked');
 
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackSearchClick');
            return ResponseService::errorResponse('Greška');
        }
    }
 
    // ═══════════════════════════════════════════
    // HELPER METODE - PRO/SHOP FEATURES
    // ═══════════════════════════════════════════
 
    /**
     * Dohvati membership tier korisnika
     */
    private function getUserMembershipTier(int $userId): string
    {
        $membership = UserMembership::where('user_id', $userId)
            ->where('status', 'active')
            ->first();
 
        if (!$membership) {
            return 'free';
        }
 
        $tier = strtolower($membership->tier ?? $membership->tier_name ?? '');
        
        if (strpos($tier, 'shop') !== false || strpos($tier, 'business') !== false) {
            return 'shop';
        }
        if (strpos($tier, 'pro') !== false || strpos($tier, 'premium') !== false) {
            return 'pro';
        }
 
        // Fallback na tier_id
        $tierId = (int) ($membership->tier_id ?? 0);
        if ($tierId === 3) return 'shop';
        if ($tierId === 2) return 'pro';
 
        return 'free';
    }
 
    /**
     * Dohvati pojmove pretrage (PRO)
     */
    private function getSearchTerms(int $itemId, int $days): array
    {
        return ItemSearchImpression::where('item_id', $itemId)
            ->where('created_at', '>=', Carbon::today()->subDays($days))
            ->whereNotNull('search_query')
            ->where('search_query', '!=', '')
            ->select('search_query')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(CASE WHEN clicked = 1 THEN 1 ELSE 0 END) as clicks')
            ->groupBy('search_query')
            ->orderByDesc('count')
            ->limit(20)
            ->get()
            ->map(fn($row) => [
                'term' => $row->search_query,
                'count' => (int) $row->count,
                'clicks' => (int) $row->clicks,
                'ctr' => $row->count > 0 ? round(($row->clicks / $row->count) * 100, 1) : 0,
            ])
            ->toArray();
    }
 
    /**
     * Dohvati pozicije na pretrazi (PRO)
     */
    private function getSearchPositions(int $itemId, int $days): array
    {
        return ItemSearchImpression::where('item_id', $itemId)
            ->where('created_at', '>=', Carbon::today()->subDays($days))
            ->select('page')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(CASE WHEN clicked = 1 THEN 1 ELSE 0 END) as clicks')
            ->groupBy('page')
            ->orderBy('page')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'page' => (int) $row->page,
                'views' => (int) $row->count,
                'clicks' => (int) $row->clicks,
            ])
            ->toArray();
    }
 
    /**
     * Dohvati breakdown kontakata (PRO)
     */
    private function getContactBreakdown(int $itemId, int $days): array
    {
        $startDate = Carbon::today()->subDays($days);
 
        return ItemContactEvent::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->select('contact_type')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COUNT(DISTINCT user_id) as unique_users')
            ->groupBy('contact_type')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->contact_type => [
                    'total' => (int) $row->count,
                    'unique' => (int) $row->unique_users,
                ]
            ])
            ->toArray();
    }
 
    /**
     * Dohvati breakdown dijeljenja (PRO)
     */
    private function getShareBreakdown(int $itemId, int $days): array
    {
        $startDate = Carbon::today()->subDays($days);
 
        return ItemShare::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->select('platform')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('platform')
            ->orderByDesc('count')
            ->get()
            ->map(fn($row) => [
                'platform' => $row->platform,
                'count' => (int) $row->count,
            ])
            ->toArray();
    }
 
    /**
     * Dohvati statistiku po satima (SHOP)
     */
    private function getHourlyStats(int $itemId, int $days): array
    {
        $startDate = Carbon::today()->subDays($days);
 
        // Agregiraj hourly_views iz svih dana
        $stats = ItemStatistic::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->whereNotNull('hourly_views')
            ->pluck('hourly_views');
 
        $hourlyTotals = [];
        for ($i = 0; $i < 24; $i++) {
            $hourlyTotals[$i] = 0;
        }
 
        foreach ($stats as $dailyHourly) {
            if (is_array($dailyHourly)) {
                foreach ($dailyHourly as $hour => $count) {
                    $hourlyTotals[(int)$hour] += (int)$count;
                }
            }
        }
 
        // Grupiraj u 4-satne blokove
        $grouped = [
            ['hour' => '00-04', 'views' => $hourlyTotals[0] + $hourlyTotals[1] + $hourlyTotals[2] + $hourlyTotals[3]],
            ['hour' => '04-08', 'views' => $hourlyTotals[4] + $hourlyTotals[5] + $hourlyTotals[6] + $hourlyTotals[7]],
            ['hour' => '08-12', 'views' => $hourlyTotals[8] + $hourlyTotals[9] + $hourlyTotals[10] + $hourlyTotals[11]],
            ['hour' => '12-16', 'views' => $hourlyTotals[12] + $hourlyTotals[13] + $hourlyTotals[14] + $hourlyTotals[15]],
            ['hour' => '16-20', 'views' => $hourlyTotals[16] + $hourlyTotals[17] + $hourlyTotals[18] + $hourlyTotals[19]],
            ['hour' => '20-24', 'views' => $hourlyTotals[20] + $hourlyTotals[21] + $hourlyTotals[22] + $hourlyTotals[23]],
        ];
 
        return $grouped;
    }
 
    /**
     * Dohvati geografsku statistiku (SHOP)
     */
    private function getGeoStats(int $itemId, int $days): array
    {
        $startDate = Carbon::today()->subDays($days);
 
        // Agregiraj geo_cities iz svih dana
        $stats = ItemStatistic::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->whereNotNull('geo_cities')
            ->pluck('geo_cities');
 
        $cityTotals = [];
        foreach ($stats as $dailyCities) {
            if (is_array($dailyCities)) {
                foreach ($dailyCities as $city => $count) {
                    $cityTotals[$city] = ($cityTotals[$city] ?? 0) + (int)$count;
                }
            }
        }
 
        // Sortiraj i vrati top 10
        arsort($cityTotals);
        $topCities = array_slice($cityTotals, 0, 10, true);
 
        return array_map(fn($city, $count) => [
            'city' => $city,
            'views' => $count,
        ], array_keys($topCities), array_values($topCities));
    }
 
    /**
     * Dohvati konkurentsku analizu (SHOP)
     */
    private function getCompetitionStats(Item $item): array
    {
        $categoryId = $item->category_id;
        
        // Broj oglasa u kategoriji
        $totalInCategory = Item::where('category_id', $categoryId)
            ->where('status', 'approved')
            ->count();
 
        // Rank po pregledima (u zadnjih 30 dana)
        $startDate = Carbon::today()->subDays(29);
        
        $itemViews = ItemStatistic::where('item_id', $item->id)
            ->where('date', '>=', $startDate)
            ->sum('views');
 
        $betterItems = DB::table('item_statistics')
            ->join('items', 'items.id', '=', 'item_statistics.item_id')
            ->where('items.category_id', $categoryId)
            ->where('items.status', 'approved')
            ->where('item_statistics.date', '>=', $startDate)
            ->groupBy('item_statistics.item_id')
            ->havingRaw('SUM(item_statistics.views) > ?', [$itemViews])
            ->count();
 
        $rank = $betterItems + 1;
        $percentile = $totalInCategory > 0 
            ? round((1 - ($rank / $totalInCategory)) * 100, 1) 
            : 0;
 
        return [
            'category_total_items' => $totalInCategory,
            'your_rank' => $rank,
            'percentile' => $percentile,
            'your_views_30d' => (int) $itemViews,
            'avg_views_in_category' => $this->getAvgViewsInCategory($categoryId, $startDate),
        ];
    }
 
    /**
     * Prosječni pregledi u kategoriji
     */
    private function getAvgViewsInCategory(int $categoryId, Carbon $startDate): int
    {
        $result = DB::table('item_statistics')
            ->join('items', 'items.id', '=', 'item_statistics.item_id')
            ->where('items.category_id', $categoryId)
            ->where('items.status', 'approved')
            ->where('item_statistics.date', '>=', $startDate)
            ->selectRaw('AVG(views_sum) as avg_views')
            ->fromSub(function ($query) use ($categoryId, $startDate) {
                $query->from('item_statistics')
                    ->join('items', 'items.id', '=', 'item_statistics.item_id')
                    ->where('items.category_id', $categoryId)
                    ->where('items.status', 'approved')
                    ->where('item_statistics.date', '>=', $startDate)
                    ->selectRaw('item_statistics.item_id, SUM(item_statistics.views) as views_sum')
                    ->groupBy('item_statistics.item_id');
            }, 'subquery')
            ->first();
 
        return (int) ($result->avg_views ?? 0);
    }
 
    /**
     * Konverzijski funnel
     */
    private function getConversionFunnel(int $itemId, int $days): array
    {
        $startDate = Carbon::today()->subDays($days);
 
        $stats = ItemStatistic::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->selectRaw('
                SUM(search_impressions) as impressions,
                SUM(views) as views,
                SUM(phone_clicks + whatsapp_clicks + viber_clicks + messages_started) as contacts
            ')
            ->first();
 
        $impressions = (int) ($stats->impressions ?? 0);
        $views = (int) ($stats->views ?? 0);
        $contacts = (int) ($stats->contacts ?? 0);
 
        // Izračunaj postotke
        $viewsPercent = $impressions > 0 ? round(($views / $impressions) * 100, 1) : 100;
        $contactsPercent = $views > 0 ? round(($contacts / $views) * 100, 1) : 0;
 
        return [
            'funnel' => [
                ['stage' => 'Impresije', 'value' => $impressions, 'percent' => 100],
                ['stage' => 'Pregledi', 'value' => $views, 'percent' => $viewsPercent],
                ['stage' => 'Kontakti', 'value' => $contacts, 'percent' => $contactsPercent],
            ],
            'conversion_rate' => $views > 0 ? round(($contacts / $views) * 100, 2) : 0,
        ];
    }
 
    /**
     * Detaljna konverzija (SHOP)
     */
    private function getDetailedConversion(int $itemId, int $days): array
    {
        $startDate = Carbon::today()->subDays($days);
 
        $stats = ItemStatistic::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->selectRaw('
                SUM(search_impressions) as search_imp,
                SUM(category_impressions) as cat_imp,
                SUM(homepage_impressions) as home_imp,
                SUM(search_clicks) as search_clicks,
                SUM(category_clicks) as cat_clicks,
                SUM(homepage_clicks) as home_clicks,
                SUM(views) as views,
                SUM(gallery_opens) as gallery,
                SUM(phone_reveals) as reveals,
                SUM(phone_clicks + whatsapp_clicks + viber_clicks) as calls,
                SUM(messages_started) as messages,
                SUM(offers_received) as offers
            ')
            ->first();
 
        return [
            'search' => [
                'impressions' => (int) ($stats->search_imp ?? 0),
                'clicks' => (int) ($stats->search_clicks ?? 0),
                'ctr' => ($stats->search_imp ?? 0) > 0 
                    ? round((($stats->search_clicks ?? 0) / $stats->search_imp) * 100, 2) : 0,
            ],
            'category' => [
                'impressions' => (int) ($stats->cat_imp ?? 0),
                'clicks' => (int) ($stats->cat_clicks ?? 0),
                'ctr' => ($stats->cat_imp ?? 0) > 0 
                    ? round((($stats->cat_clicks ?? 0) / $stats->cat_imp) * 100, 2) : 0,
            ],
            'engagement' => [
                'views' => (int) ($stats->views ?? 0),
                'gallery_opens' => (int) ($stats->gallery ?? 0),
                'gallery_rate' => ($stats->views ?? 0) > 0 
                    ? round((($stats->gallery ?? 0) / $stats->views) * 100, 1) : 0,
            ],
            'contacts' => [
                'phone_reveals' => (int) ($stats->reveals ?? 0),
                'calls_total' => (int) ($stats->calls ?? 0),
                'messages' => (int) ($stats->messages ?? 0),
                'offers' => (int) ($stats->offers ?? 0),
            ],
        ];
    }
 
    /**
     * Map source string to database field
     */
    private function mapSourceToField(string $source): ?string
    {
        $map = [
            'direct' => 'source_direct',
            'search' => 'source_internal_search',
            'category' => 'source_category_browse',
            'featured' => 'source_featured_section',
            'similar' => 'source_similar_items',
            'seller' => 'source_seller_profile',
            'favorites' => 'source_favorites',
            'notification' => 'source_notifications',
            'chat' => 'source_chat',
            'google' => 'source_google_organic',
            'google_ads' => 'source_google_ads',
            'facebook' => 'source_facebook',
            'instagram' => 'source_instagram',
            'viber' => 'source_viber',
            'whatsapp' => 'source_whatsapp',
            'twitter' => 'source_twitter',
            'tiktok' => 'source_tiktok',
            'youtube' => 'source_youtube',
            'linkedin' => 'source_linkedin',
        ];
 
        return $map[$source] ?? 'source_other_external';
    }
 
    /**
     * Detektiraj tip uređaja
     */
    private function detectDeviceType(Request $request): string
    {
        $userAgent = strtolower($request->userAgent() ?? '');
 
        if (strpos($userAgent, 'mobile') !== false || strpos($userAgent, 'android') !== false) {
            if (strpos($userAgent, 'tablet') !== false || strpos($userAgent, 'ipad') !== false) {
                return 'tablet';
            }
            return 'mobile';
        }
 
        if (strpos($userAgent, 'tablet') !== false || strpos($userAgent, 'ipad') !== false) {
            return 'tablet';
        }
 
        return 'desktop';
    }
}