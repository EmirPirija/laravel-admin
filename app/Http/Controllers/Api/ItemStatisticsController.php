<?php
 
namespace App\Http\Controllers\Api;
 
use App\Http\Controllers\Controller;
use App\Models\FeaturedItems;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

    /**
     * Dohvati agregiranu statistiku za prodavača (svi njegovi oglasi)
     * GET /api/item-statistics/seller/overview?period=30&top=8
     */
    public function getSellerOverview(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'period' => 'nullable|integer|min:1|max:365',
                'top' => 'nullable|integer|min:1|max:20',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $period = (int) ($request->period ?? 30);
            $top = (int) ($request->top ?? 8);
            $userId = Auth::id();

            $items = Item::withTrashed()
                ->select(['id', 'name', 'slug', 'video', 'video_link'])
                ->where('user_id', $userId)
                ->get();

            if ($items->isEmpty()) {
                return ResponseService::successResponse('Seller overview fetched', $this->emptySellerOverview($period));
            }

            $itemIds = $items->pluck('id')->all();
            $itemsById = $items->keyBy('id');

            $today = Carbon::today();
            $startDate = $today->copy()->subDays($period - 1);
            $prevStartDate = $startDate->copy()->subDays($period);
            $prevEndDate = $startDate->copy()->subDay();

            $periodStats = $this->aggregateSellerStats($itemIds, $startDate, $today);
            $prevStats = $this->aggregateSellerStats($itemIds, $prevStartDate, $prevEndDate);

            $contacts = $periodStats['phone_clicks']
                + $periodStats['whatsapp_clicks']
                + $periodStats['viber_clicks']
                + $periodStats['email_clicks'];
            $prevContacts = $prevStats['phone_clicks']
                + $prevStats['whatsapp_clicks']
                + $prevStats['viber_clicks']
                + $prevStats['email_clicks'];

            $searchCtr = $periodStats['search_impressions'] > 0
                ? round(($periodStats['search_clicks'] / $periodStats['search_impressions']) * 100, 2)
                : 0.0;
            $reelCompletionRate = $periodStats['video_plays'] > 0
                ? round(($periodStats['video_completions'] / $periodStats['video_plays']) * 100, 2)
                : 0.0;
            $adsWithVideo = $items->filter(fn(Item $item) => $this->itemHasVideo($item))->count();

            $summary = [
                'views' => $periodStats['views'],
                'contacts' => $contacts,
                'messages' => $periodStats['messages_started'],
                'favorites' => $periodStats['favorites_added'],
                'shares' => $periodStats['shares_total'],
                'video_plays' => $periodStats['video_plays'],
                'reel_completion_rate' => $reelCompletionRate,
                'search_ctr' => $searchCtr,
                'ads_with_video' => $adsWithVideo,
            ];

            $trends = [
                'views_vs_prev_period' => $this->calculateTrend($periodStats['views'], $prevStats['views']),
                'contacts_vs_prev_period' => $this->calculateTrend(
                    $contacts + $periodStats['messages_started'],
                    $prevContacts + $prevStats['messages_started']
                ),
                'reel_plays_vs_prev_period' => $this->calculateTrend($periodStats['video_plays'], $prevStats['video_plays']),
            ];

            $topStats = ItemStatistic::whereIn('item_id', $itemIds)
                ->whereBetween('date', [$startDate, $today])
                ->selectRaw('
                    item_id,
                    SUM(views) as views,
                    SUM(messages_started) as messages_started,
                    SUM(phone_clicks + whatsapp_clicks + viber_clicks + email_clicks) as contacts,
                    SUM(video_plays) as video_plays,
                    SUM(video_completions) as video_completions
                ')
                ->groupBy('item_id')
                ->orderByDesc('views')
                ->limit(max($top * 3, $top))
                ->get();

            $topAds = $topStats
                ->take($top)
                ->map(function ($row) use ($itemsById) {
                    /** @var Item|null $item */
                    $item = $itemsById->get($row->item_id);
                    if (!$item) {
                        return null;
                    }

                    $views = (int) ($row->views ?? 0);
                    $contacts = (int) ($row->contacts ?? 0);
                    $messages = (int) ($row->messages_started ?? 0);
                    $contactRate = $views > 0 ? round((($contacts + $messages) / $views) * 100, 2) : 0.0;

                    return [
                        'item_id' => (int) $item->id,
                        'name' => $item->name,
                        'slug' => $item->slug,
                        'has_video' => $this->itemHasVideo($item),
                        'video_source' => $this->resolveVideoSource($item),
                        'stats' => [
                            'views' => $views,
                            'contacts' => $contacts,
                            'messages' => $messages,
                            'contact_rate' => $contactRate,
                        ],
                    ];
                })
                ->filter()
                ->values()
                ->toArray();

            $reelTop = $topStats
                ->filter(function ($row) use ($itemsById) {
                    $item = $itemsById->get($row->item_id);
                    return $item && $this->itemHasVideo($item) && (int) ($row->video_plays ?? 0) > 0;
                })
                ->sortByDesc('video_plays')
                ->take(5)
                ->map(function ($row) use ($itemsById) {
                    /** @var Item $item */
                    $item = $itemsById->get($row->item_id);
                    $plays = (int) ($row->video_plays ?? 0);
                    $completionRate = $plays > 0 ? round(((int) ($row->video_completions ?? 0) / $plays) * 100, 2) : 0.0;

                    return [
                        'item_id' => (int) $item->id,
                        'name' => $item->name,
                        'slug' => $item->slug,
                        'plays' => $plays,
                        'completion_rate' => $completionRate,
                    ];
                })
                ->values()
                ->toArray();

            $sources = $this->buildSellerSources($periodStats);
            $devices = $this->buildSellerDevices($periodStats);
            $quickActions = $this->buildSellerQuickActions($summary);

            return ResponseService::successResponse('Seller overview fetched', [
                'period' => $period,
                'summary' => $summary,
                'trends' => $trends,
                'quick_actions' => $quickActions,
                'top_ads' => $topAds,
                'reels' => [
                    'total_reels' => $adsWithVideo,
                    'completion_rate' => $reelCompletionRate,
                    'top_reels' => $reelTop,
                ],
                'sources' => $sources,
                'devices' => $devices,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> getSellerOverview');
            return ResponseService::errorResponse('Greška pri dohvatanju seller statistike');
        }
    }

    /**
     * Dohvati SLA metriku prodavača (prosječno vrijeme odgovora)
     * GET /api/item-statistics/seller/sla
     */
    public function getSellerSla(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fast_threshold' => 'nullable|integer|min:1|max:240',
                'reliable_threshold' => 'nullable|integer|min:2|max:1440',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Nemate pristup', null, 401);
            }

            $fastThreshold = (int) ($request->input('fast_threshold', 15));
            $reliableThreshold = (int) ($request->input('reliable_threshold', 60));
            if ($reliableThreshold <= $fastThreshold) {
                $reliableThreshold = $fastThreshold + 1;
            }

            $avgMinutes = $user->response_time_avg !== null ? (int) $user->response_time_avg : null;
            $tier = 'no_data';
            $label = 'Nema dovoljno podataka';
            $tooltip = 'Metrika će biti prikazana nakon što prikupimo dovoljno odgovora na poruke.';

            if ($avgMinutes !== null) {
                if ($avgMinutes < $fastThreshold) {
                    $tier = 'fast';
                    $label = 'Brz prodavač';
                    $tooltip = 'Prosječno odgovorite za manje od ' . $fastThreshold . ' minuta.';
                } elseif ($avgMinutes < $reliableThreshold) {
                    $tier = 'reliable';
                    $label = 'Pouzdan prodavač';
                    $tooltip = 'Odgovarate stabilno unutar očekivanog vremena.';
                } else {
                    $tier = 'slow';
                    $label = 'Sporiji odgovor';
                    $tooltip = 'Vrijeme odgovora je iznad preporučenog praga.';
                }
            }

            return ResponseService::successResponse('SLA metrika uspješno dohvaćena', [
                'avg_response_minutes' => $avgMinutes,
                'formatted' => $avgMinutes !== null
                    ? 'U prosjeku odgovarate za ' . number_format($avgMinutes, 0) . ' min'
                    : 'U prosjeku odgovarate: nema dovoljno podataka',
                'badge' => [
                    'tier' => $tier,
                    'label' => $label,
                    'tooltip' => $tooltip,
                ],
                'thresholds' => [
                    'fast' => $fastThreshold,
                    'reliable' => $reliableThreshold,
                ],
                'has_data' => $avgMinutes !== null,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> getSellerSla');
            return ResponseService::errorResponse('Greška pri dohvatu SLA metrike');
        }
    }

    /**
     * Boost ROI pregled (boost vs organik)
     * GET /api/item-statistics/seller/boost-roi?period=30
     */
    public function getSellerBoostRoi(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'period' => 'nullable|integer|min:7|max:365',
                'category_id' => 'nullable|integer|exists:categories,id',
                'top' => 'nullable|integer|min:1|max:20',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Nemate pristup', null, 401);
            }

            $period = (int) ($request->input('period', 30));
            $top = (int) ($request->input('top', 5));
            $today = Carbon::today();
            $startDate = $today->copy()->subDays($period - 1);

            $itemsQuery = Item::withTrashed()->where('user_id', $user->id);
            if ($request->filled('category_id')) {
                $itemsQuery->where('category_id', (int) $request->input('category_id'));
            }

            $items = $itemsQuery->select(['id', 'name', 'slug'])->get();
            if ($items->isEmpty()) {
                return ResponseService::successResponse('Boost ROI pregled', [
                    'period' => $period,
                    'summary' => [
                        'total_contacts' => 0,
                        'boost_contacts' => 0,
                        'organic_contacts' => 0,
                        'additional_contacts' => 0,
                        'boost_cost' => 0.0,
                        'cost_per_contact' => null,
                    ],
                    'trend' => [],
                    'top_ads' => [],
                ]);
            }

            $itemIds = $items->pluck('id')->all();
            $itemsById = $items->keyBy('id');
            $contactExpr = 'phone_clicks + whatsapp_clicks + viber_clicks + email_clicks + messages_started';

            $dailyRows = ItemStatistic::whereIn('item_id', $itemIds)
                ->whereBetween('date', [$startDate, $today])
                ->selectRaw('
                    date,
                    SUM(' . $contactExpr . ') as total_contacts,
                    SUM(CASE WHEN was_featured = 1 THEN ' . $contactExpr . ' ELSE 0 END) as boost_contacts,
                    SUM(CASE WHEN was_featured = 0 THEN ' . $contactExpr . ' ELSE 0 END) as organic_contacts,
                    SUM(CASE WHEN was_featured = 1 THEN views ELSE 0 END) as boost_views
                ')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $trend = $dailyRows->map(function ($row) {
                return [
                    'date' => Carbon::parse($row->date)->toDateString(),
                    'boost_contacts' => (int) ($row->boost_contacts ?? 0),
                    'organic_contacts' => (int) ($row->organic_contacts ?? 0),
                    'total_contacts' => (int) ($row->total_contacts ?? 0),
                ];
            })->values()->all();

            $boostContacts = (int) $dailyRows->sum('boost_contacts');
            $organicContacts = (int) $dailyRows->sum('organic_contacts');
            $totalContacts = $boostContacts + $organicContacts;

            $featuredDays = (int) $dailyRows->filter(fn($row) => (int) ($row->boost_views ?? 0) > 0)->count();
            $organicDays = max(1, $period - $featuredDays);

            $avgOrganicContactsPerDay = $organicDays > 0 ? ($organicContacts / $organicDays) : 0;
            $expectedOrganicDuringBoost = $avgOrganicContactsPerDay * max(1, $featuredDays);
            $additionalContacts = (int) max(0, round($boostContacts - $expectedOrganicDuringBoost));

            $boostCost = (float) FeaturedItems::query()
                ->join('packages', 'packages.id', '=', 'featured_items.package_id')
                ->whereIn('featured_items.item_id', $itemIds)
                ->whereDate('featured_items.start_date', '<=', $today)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('featured_items.end_date')
                        ->orWhereDate('featured_items.end_date', '>=', $startDate);
                })
                ->sum(DB::raw('COALESCE(packages.final_price, packages.price, 0)'));

            $costPerContact = $additionalContacts > 0
                ? round($boostCost / $additionalContacts, 2)
                : null;

            $topRows = ItemStatistic::whereIn('item_id', $itemIds)
                ->whereBetween('date', [$startDate, $today])
                ->selectRaw('
                    item_id,
                    SUM(views) as views,
                    SUM(CASE WHEN was_featured = 1 THEN ' . $contactExpr . ' ELSE 0 END) as boost_contacts,
                    SUM(CASE WHEN was_featured = 0 THEN ' . $contactExpr . ' ELSE 0 END) as organic_contacts
                ')
                ->groupBy('item_id')
                ->get()
                ->map(function ($row) use ($itemsById) {
                    /** @var Item|null $item */
                    $item = $itemsById->get($row->item_id);
                    if (! $item) {
                        return null;
                    }

                    $boost = (int) ($row->boost_contacts ?? 0);
                    $organic = (int) ($row->organic_contacts ?? 0);
                    $additional = max(0, $boost - $organic);
                    $views = (int) ($row->views ?? 0);

                    return [
                        'item_id' => (int) $item->id,
                        'name' => $item->name,
                        'slug' => $item->slug,
                        'views' => $views,
                        'boost_contacts' => $boost,
                        'organic_contacts' => $organic,
                        'additional_contacts' => $additional,
                        'contact_rate' => $views > 0 ? round((($boost + $organic) / $views) * 100, 2) : 0.0,
                    ];
                })
                ->filter()
                ->sortByDesc('additional_contacts')
                ->take($top)
                ->values()
                ->all();

            return ResponseService::successResponse('Boost ROI pregled uspješno dohvaćen', [
                'period' => $period,
                'summary' => [
                    'total_contacts' => $totalContacts,
                    'boost_contacts' => $boostContacts,
                    'organic_contacts' => $organicContacts,
                    'additional_contacts' => $additionalContacts,
                    'boost_cost' => round($boostCost, 2),
                    'cost_per_contact' => $costPerContact,
                ],
                'trend' => $trend,
                'top_ads' => $topRows,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> getSellerBoostRoi');
            return ResponseService::errorResponse('Greška pri dohvatu Boost ROI pregleda');
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

    private function emptySellerOverview(int $period): array
    {
        return [
            'period' => $period,
            'summary' => [
                'views' => 0,
                'contacts' => 0,
                'messages' => 0,
                'favorites' => 0,
                'shares' => 0,
                'video_plays' => 0,
                'reel_completion_rate' => 0.0,
                'search_ctr' => 0.0,
                'ads_with_video' => 0,
            ],
            'trends' => [
                'views_vs_prev_period' => 0.0,
                'contacts_vs_prev_period' => 0.0,
                'reel_plays_vs_prev_period' => 0.0,
            ],
            'quick_actions' => [],
            'top_ads' => [],
            'reels' => [
                'total_reels' => 0,
                'completion_rate' => 0.0,
                'top_reels' => [],
            ],
            'sources' => [
                'internal' => [],
                'external' => [],
                'total' => 0,
            ],
            'devices' => [
                'mobile' => ['value' => 0, 'percent' => 0.0],
                'desktop' => ['value' => 0, 'percent' => 0.0],
                'tablet' => ['value' => 0, 'percent' => 0.0],
            ],
        ];
    }

    private function aggregateSellerStats(array $itemIds, Carbon $startDate, Carbon $endDate): array
    {
        $defaults = [
            'views' => 0,
            'messages_started' => 0,
            'phone_clicks' => 0,
            'whatsapp_clicks' => 0,
            'viber_clicks' => 0,
            'email_clicks' => 0,
            'favorites_added' => 0,
            'shares_total' => 0,
            'video_plays' => 0,
            'video_completions' => 0,
            'search_impressions' => 0,
            'search_clicks' => 0,
            'source_internal_search' => 0,
            'source_category_browse' => 0,
            'source_featured_section' => 0,
            'source_similar_items' => 0,
            'source_seller_profile' => 0,
            'source_favorites' => 0,
            'source_notifications' => 0,
            'source_chat' => 0,
            'source_direct' => 0,
            'source_google_organic' => 0,
            'source_google_ads' => 0,
            'source_facebook' => 0,
            'source_instagram' => 0,
            'source_viber' => 0,
            'source_whatsapp' => 0,
            'source_twitter' => 0,
            'source_tiktok' => 0,
            'source_youtube' => 0,
            'source_linkedin' => 0,
            'source_other_external' => 0,
            'source_email_campaign' => 0,
            'source_push_notification' => 0,
            'device_mobile' => 0,
            'device_desktop' => 0,
            'device_tablet' => 0,
            'device_app_ios' => 0,
            'device_app_android' => 0,
        ];

        if (empty($itemIds) || $startDate->gt($endDate)) {
            return $defaults;
        }

        $stats = ItemStatistic::whereIn('item_id', $itemIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw('
                COALESCE(SUM(views), 0) as views,
                COALESCE(SUM(messages_started), 0) as messages_started,
                COALESCE(SUM(phone_clicks), 0) as phone_clicks,
                COALESCE(SUM(whatsapp_clicks), 0) as whatsapp_clicks,
                COALESCE(SUM(viber_clicks), 0) as viber_clicks,
                COALESCE(SUM(email_clicks), 0) as email_clicks,
                COALESCE(SUM(favorites_added), 0) as favorites_added,
                COALESCE(SUM(shares_total), 0) as shares_total,
                COALESCE(SUM(video_plays), 0) as video_plays,
                COALESCE(SUM(video_completions), 0) as video_completions,
                COALESCE(SUM(search_impressions), 0) as search_impressions,
                COALESCE(SUM(search_clicks), 0) as search_clicks,
                COALESCE(SUM(source_internal_search), 0) as source_internal_search,
                COALESCE(SUM(source_category_browse), 0) as source_category_browse,
                COALESCE(SUM(source_featured_section), 0) as source_featured_section,
                COALESCE(SUM(source_similar_items), 0) as source_similar_items,
                COALESCE(SUM(source_seller_profile), 0) as source_seller_profile,
                COALESCE(SUM(source_favorites), 0) as source_favorites,
                COALESCE(SUM(source_notifications), 0) as source_notifications,
                COALESCE(SUM(source_chat), 0) as source_chat,
                COALESCE(SUM(source_direct), 0) as source_direct,
                COALESCE(SUM(source_google_organic), 0) as source_google_organic,
                COALESCE(SUM(source_google_ads), 0) as source_google_ads,
                COALESCE(SUM(source_facebook), 0) as source_facebook,
                COALESCE(SUM(source_instagram), 0) as source_instagram,
                COALESCE(SUM(source_viber), 0) as source_viber,
                COALESCE(SUM(source_whatsapp), 0) as source_whatsapp,
                COALESCE(SUM(source_twitter), 0) as source_twitter,
                COALESCE(SUM(source_tiktok), 0) as source_tiktok,
                COALESCE(SUM(source_youtube), 0) as source_youtube,
                COALESCE(SUM(source_linkedin), 0) as source_linkedin,
                COALESCE(SUM(source_other_external), 0) as source_other_external,
                COALESCE(SUM(source_email_campaign), 0) as source_email_campaign,
                COALESCE(SUM(source_push_notification), 0) as source_push_notification,
                COALESCE(SUM(device_mobile), 0) as device_mobile,
                COALESCE(SUM(device_desktop), 0) as device_desktop,
                COALESCE(SUM(device_tablet), 0) as device_tablet,
                COALESCE(SUM(device_app_ios), 0) as device_app_ios,
                COALESCE(SUM(device_app_android), 0) as device_app_android
            ')
            ->first();

        if (!$stats) {
            return $defaults;
        }

        foreach ($defaults as $key => $value) {
            $defaults[$key] = (int) ($stats->{$key} ?? 0);
        }

        return $defaults;
    }

    private function calculateTrend(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function itemHasVideo(Item $item): bool
    {
        return !empty($item->getRawOriginal('video')) || !empty($item->getRawOriginal('video_link'));
    }

    private function resolveVideoSource(Item $item): ?string
    {
        if (!empty($item->getRawOriginal('video'))) {
            return 'upload';
        }

        $videoLink = (string) ($item->getRawOriginal('video_link') ?? '');
        if ($videoLink === '') {
            return null;
        }

        $normalized = strtolower($videoLink);
        if (str_contains($normalized, 'youtube.com') || str_contains($normalized, 'youtu.be')) {
            return 'youtube';
        }

        return 'external';
    }

    private function buildSellerSources(array $stats): array
    {
        $internal = [
            'Pretraga' => (int) ($stats['source_internal_search'] ?? 0),
            'Kategorije' => (int) ($stats['source_category_browse'] ?? 0),
            'Istaknuto' => (int) ($stats['source_featured_section'] ?? 0),
            'Slični oglasi' => (int) ($stats['source_similar_items'] ?? 0),
            'Profil prodavača' => (int) ($stats['source_seller_profile'] ?? 0),
            'Favoriti' => (int) ($stats['source_favorites'] ?? 0),
            'Notifikacije' => (int) ($stats['source_notifications'] ?? 0),
            'Chat' => (int) ($stats['source_chat'] ?? 0),
            'Direktno' => (int) ($stats['source_direct'] ?? 0),
            'Push' => (int) ($stats['source_push_notification'] ?? 0),
            'Email kampanja' => (int) ($stats['source_email_campaign'] ?? 0),
        ];

        $external = [
            'Google organic' => (int) ($stats['source_google_organic'] ?? 0),
            'Google Ads' => (int) ($stats['source_google_ads'] ?? 0),
            'Facebook' => (int) ($stats['source_facebook'] ?? 0),
            'Instagram' => (int) ($stats['source_instagram'] ?? 0),
            'Viber' => (int) ($stats['source_viber'] ?? 0),
            'WhatsApp' => (int) ($stats['source_whatsapp'] ?? 0),
            'Twitter/X' => (int) ($stats['source_twitter'] ?? 0),
            'TikTok' => (int) ($stats['source_tiktok'] ?? 0),
            'YouTube' => (int) ($stats['source_youtube'] ?? 0),
            'LinkedIn' => (int) ($stats['source_linkedin'] ?? 0),
            'Ostalo' => (int) ($stats['source_other_external'] ?? 0),
        ];

        $total = array_sum($internal) + array_sum($external);

        $mapRows = static function (array $rows) use ($total): array {
            $prepared = [];
            foreach ($rows as $name => $value) {
                if ($value <= 0) {
                    continue;
                }

                $prepared[] = [
                    'name' => $name,
                    'value' => (int) $value,
                    'percent' => $total > 0 ? round(($value / $total) * 100, 1) : 0.0,
                ];
            }

            usort($prepared, static fn($a, $b) => $b['value'] <=> $a['value']);
            return $prepared;
        };

        return [
            'internal' => $mapRows($internal),
            'external' => $mapRows($external),
            'total' => $total,
        ];
    }

    private function buildSellerDevices(array $stats): array
    {
        $mobile = (int) ($stats['device_mobile'] ?? 0)
            + (int) ($stats['device_app_ios'] ?? 0)
            + (int) ($stats['device_app_android'] ?? 0);
        $desktop = (int) ($stats['device_desktop'] ?? 0);
        $tablet = (int) ($stats['device_tablet'] ?? 0);

        $total = $mobile + $desktop + $tablet;

        $percent = static fn(int $value) => $total > 0 ? round(($value / $total) * 100, 1) : 0.0;

        return [
            'mobile' => ['value' => $mobile, 'percent' => $percent($mobile)],
            'desktop' => ['value' => $desktop, 'percent' => $percent($desktop)],
            'tablet' => ['value' => $tablet, 'percent' => $percent($tablet)],
        ];
    }

    private function buildSellerQuickActions(array $summary): array
    {
        $actions = [];
        $views = (int) ($summary['views'] ?? 0);
        $contactsAndMessages = (int) ($summary['contacts'] ?? 0) + (int) ($summary['messages'] ?? 0);
        $contactRate = $views > 0 ? ($contactsAndMessages / $views) * 100 : 0.0;
        $searchCtr = (float) ($summary['search_ctr'] ?? 0.0);
        $completion = (float) ($summary['reel_completion_rate'] ?? 0.0);
        $adsWithVideo = (int) ($summary['ads_with_video'] ?? 0);

        if ($views >= 100 && $searchCtr < 2.0) {
            $actions[] = [
                'id' => 'improve-search-ctr',
                'title' => 'Pojačaj CTR iz pretrage',
                'description' => 'Naslov i prva fotografija oglasa vjerovatno ne privlače dovoljno klikova u rezultatima pretrage.',
                'priority' => 'high',
            ];
        }

        if ($views >= 60 && $contactRate < 1.5) {
            $actions[] = [
                'id' => 'increase-contact-rate',
                'title' => 'Povećaj stopu kontakata',
                'description' => 'Dodaj jasniji CTA i kompletiraj ključne detalje oglasa da korisnici lakše pošalju poruku ili poziv.',
                'priority' => 'medium',
            ];
        }

        if ($adsWithVideo === 0) {
            $actions[] = [
                'id' => 'add-video',
                'title' => 'Dodaj video na top oglase',
                'description' => 'Oglasi sa videom obično zadržavaju pažnju duže i povećavaju vjerovatnoću kontakta.',
                'priority' => 'medium',
            ];
        } elseif ($completion > 0 && $completion < 35) {
            $actions[] = [
                'id' => 'shorten-video',
                'title' => 'Optimizuj video trajanje',
                'description' => 'Zadržavanje je nisko, probaj kraći video i pokaži najbitnije informacije u prvim sekundama.',
                'priority' => 'low',
            ];
        }

        if ((int) ($summary['favorites'] ?? 0) === 0 && $views >= 120) {
            $actions[] = [
                'id' => 'boost-favorites',
                'title' => 'Povećaj broj favorita',
                'description' => 'Razmisli o korekciji cijene ili jasnijem opisu vrijednosti oglasa da korisnici češće sačuvaju oglas.',
                'priority' => 'low',
            ];
        }

        if (empty($actions)) {
            $actions[] = [
                'id' => 'keep-momentum',
                'title' => 'Zadrži trenutni momentum',
                'description' => 'Performanse su stabilne. Nastavi osvježavati oglase i prati metrike po periodima.',
                'priority' => 'low',
            ];
        }

        return array_slice($actions, 0, 4);
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
 
    // FIX: Umjesto count() sa JOIN-om, dohvati podatke pa broji na kolekciji
    $categoryItemIds = Item::where('category_id', $categoryId)
        ->where('status', 'approved')
        ->where('id', '!=', $item->id)
        ->pluck('id');
 
    $betterItems = 0;
    if ($categoryItemIds->isNotEmpty()) {
        $categoryStats = ItemStatistic::whereIn('item_id', $categoryItemIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('item_id, SUM(views) as total_views')
            ->groupBy('item_id')
            ->get();
 
        $betterItems = $categoryStats->filter(function ($stat) use ($itemViews) {
            return $stat->total_views > $itemViews;
        })->count();
    }
 
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
    // FIX: Bez JOIN-a - prvo dohvati item_id-ove, pa statistiku
    $categoryItemIds = Item::where('category_id', $categoryId)
        ->where('status', 'approved')
        ->pluck('id');
 
    if ($categoryItemIds->isEmpty()) {
        return 0;
    }
 
    $stats = ItemStatistic::whereIn('item_id', $categoryItemIds)
        ->where('date', '>=', $startDate)
        ->selectRaw('item_id, SUM(views) as total_views')
        ->groupBy('item_id')
        ->get();
 
    if ($stats->isEmpty()) {
        return 0;
    }
 
    return (int) round($stats->avg('total_views'));
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
