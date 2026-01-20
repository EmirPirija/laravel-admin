<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Services\ItemStatisticsService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ItemStatisticsController extends Controller
{
    protected ItemStatisticsService $statisticsService;

    public function __construct(ItemStatisticsService $statisticsService)
    {
        $this->statisticsService = $statisticsService;
    }

    // ═══════════════════════════════════════════
    // DOHVAT STATISTIKE
    // ═══════════════════════════════════════════

    /**
     * Dohvati kompletnu statistiku za oglas
     * GET /api/item-statistics/{itemId}
     */
    public function getStatistics(Request $request, int $itemId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'period' => 'nullable|integer|min:1|max:365',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            // Provjeri vlasništvo
            $item = Item::where('id', $itemId)
                ->where('user_id', Auth::id())
                ->first();

            if (!$item) {
                return ResponseService::errorResponse(__('Item not found or access denied'), null, 403);
            }

            $period = $request->input('period', 30);
            $statistics = $this->statisticsService->getFullStatistics($itemId, $period);

            return ResponseService::successResponse(__('Statistics fetched successfully'), $statistics);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> getStatistics');
            return ResponseService::errorResponse(__('Failed to fetch statistics'));
        }
    }

    /**
     * Dohvati quick stats za oglas
     * GET /api/item-statistics/{itemId}/quick
     */
    public function getQuickStats(int $itemId)
    {
        try {
            // Provjeri vlasništvo
            $item = Item::where('id', $itemId)
                ->where('user_id', Auth::id())
                ->first();

            if (!$item) {
                return ResponseService::errorResponse(__('Item not found or access denied'), null, 403);
            }

            $stats = $this->statisticsService->getQuickStats($itemId);

            return ResponseService::successResponse(__('Quick stats fetched successfully'), $stats);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> getQuickStats');
            return ResponseService::errorResponse(__('Failed to fetch quick stats'));
        }
    }

    // ═══════════════════════════════════════════
    // TRACKING ENDPOINTS
    // ═══════════════════════════════════════════

    /**
     * Zabilježi pregled oglasa
     * POST /api/item-statistics/track-view
     */
    public function trackView(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'referrer_url' => 'nullable|string|max:500',
                'utm_source' => 'nullable|string|max:100',
                'utm_medium' => 'nullable|string|max:100',
                'utm_campaign' => 'nullable|string|max:100',
                'source' => 'nullable|string|max:50',
                'source_detail' => 'nullable|string|max:255',
                'country_code' => 'nullable|string|max:2',
                'city' => 'nullable|string|max:100',
                'is_app' => 'nullable|boolean',
                'app_platform' => 'nullable|string|in:ios,android',
                'app_version' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $this->statisticsService->recordView(
                $request->input('item_id'),
                $request->all()
            );

            return ResponseService::successResponse(__('View recorded successfully'));

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackView');
            return ResponseService::errorResponse(__('Failed to record view'));
        }
    }

    /**
     * Zabilježi kontakt
     * POST /api/item-statistics/track-contact
     */
    public function trackContact(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'contact_type' => 'required|string|in:phone_reveal,phone_click,phone_call,whatsapp,viber,telegram,email,message,offer',
                'source' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $this->statisticsService->recordContact(
                $request->input('item_id'),
                $request->input('contact_type'),
                $request->all()
            );

            return ResponseService::successResponse(__('Contact recorded successfully'));

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackContact');
            return ResponseService::errorResponse(__('Failed to record contact'));
        }
    }

    /**
     * Zabilježi dijeljenje
     * POST /api/item-statistics/track-share
     */
    public function trackShare(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'platform' => 'required|string|in:facebook,messenger,instagram,whatsapp,viber,telegram,twitter,linkedin,email,sms,copy_link,qr_code,print,native',
                'share_url' => 'nullable|string|max:500',
                'country_code' => 'nullable|string|max:2',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $share = $this->statisticsService->recordShare(
                $request->input('item_id'),
                $request->input('platform'),
                $request->all()
            );

            return ResponseService::successResponse(__('Share recorded successfully'), [
                'share_token' => $share->share_token,
            ]);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackShare');
            return ResponseService::errorResponse(__('Failed to record share'));
        }
    }

    /**
     * Zabilježi engagement
     * POST /api/item-statistics/track-engagement
     */
    public function trackEngagement(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'engagement_type' => 'required|string|in:gallery_open,image_view,image_zoom,image_download,video_play,video_25,video_50,video_75,video_complete,description_expand,specifications_view,location_view,map_open,map_directions,seller_profile_click,seller_other_items_click,similar_items_click,price_history_view',
                'extra_data' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $this->statisticsService->recordEngagement(
                $request->input('item_id'),
                $request->input('engagement_type'),
                $request->input('extra_data', [])
            );

            return ResponseService::successResponse(__('Engagement recorded successfully'));

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackEngagement');
            return ResponseService::errorResponse(__('Failed to record engagement'));
        }
    }

    /**
     * Zabilježi vrijeme na stranici
     * POST /api/item-statistics/track-time
     */
    public function trackTimeOnPage(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'duration' => 'required|integer|min:1|max:86400', // max 24 sata
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $this->statisticsService->updateTimeOnPage(
                $request->input('item_id'),
                $request->input('duration'),
                $request->all()
            );

            return ResponseService::successResponse(__('Time recorded successfully'));

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackTimeOnPage');
            return ResponseService::errorResponse(__('Failed to record time'));
        }
    }

    /**
     * Zabilježi search impression
     * POST /api/item-statistics/track-search-impression
     */
    public function trackSearchImpression(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'search_query' => 'nullable|string|max:255',
                'search_type' => 'nullable|string|in:general,category,location',
                'position' => 'required|integer|min:1',
                'page' => 'nullable|integer|min:1',
                'results_total' => 'nullable|integer',
                'was_featured' => 'nullable|boolean',
                'filters' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $this->statisticsService->recordSearchImpression(
                $request->input('item_id'),
                $request->all()
            );

            return ResponseService::successResponse(__('Search impression recorded successfully'));

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackSearchImpression');
            return ResponseService::errorResponse(__('Failed to record search impression'));
        }
    }

    /**
     * Zabilježi klik iz pretrage
     * POST /api/item-statistics/track-search-click
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

            $this->statisticsService->recordSearchClick($request->input('impression_id'));

            return ResponseService::successResponse(__('Search click recorded successfully'));

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackSearchClick');
            return ResponseService::errorResponse(__('Failed to record search click'));
        }
    }

    /**
     * Zabilježi batch search impressions
     * POST /api/item-statistics/track-batch-impressions
     */
    public function trackBatchSearchImpressions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_ids' => 'required|array',
                'item_ids.*' => 'integer|exists:items,id',
                'search_query' => 'nullable|string|max:255',
                'search_type' => 'nullable|string|in:general,category,location',
                'page' => 'nullable|integer|min:1',
                'results_total' => 'nullable|integer',
                'filters' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $this->statisticsService->recordBatchSearchImpressions(
                $request->input('item_ids'),
                $request->except('item_ids')
            );

            return ResponseService::successResponse(__('Batch impressions recorded successfully'));

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackBatchSearchImpressions');
            return ResponseService::errorResponse(__('Failed to record batch impressions'));
        }
    }

    /**
     * Handle share link click (public endpoint)
     * GET /api/share/{token}
     */
    public function handleShareClick(string $token)
    {
        try {
            $share = \App\Models\ItemShare::findByToken($token);

            if (!$share) {
                return ResponseService::errorResponse(__('Invalid share token'), null, 404);
            }

            $share->recordClick();

            // Redirect to item page
            $item = Item::find($share->item_id);
            if ($item) {
                $redirectUrl = config('app.frontend_url') . '/ad-details/' . $item->slug . '?ref=share&token=' . $token;
                return redirect($redirectUrl);
            }

            return ResponseService::successResponse(__('Click recorded'));

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> handleShareClick');
            return ResponseService::errorResponse(__('Failed to process share click'));
        }
    }

    /**
     * Zabilježi favorit
     * POST /api/item-statistics/track-favorite
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

            $this->statisticsService->recordFavorite(
                $request->input('item_id'),
                $request->input('added'),
                $request->all()
            );

            return ResponseService::successResponse(__('Favorite recorded successfully'));

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemStatisticsController -> trackFavorite');
            return ResponseService::errorResponse(__('Failed to record favorite'));
        }
    }
}