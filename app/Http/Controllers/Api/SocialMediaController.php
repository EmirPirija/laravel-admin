<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SocialMediaController extends Controller
{
    private const SUPPORTED_PLATFORMS = ['instagram', 'facebook', 'tiktok'];

    public function getConnectedAccounts(Request $request)
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $accountsByPlatform = SocialAccount::where('user_id', $user->id)
                ->get()
                ->keyBy('platform');

            $accounts = collect(self::SUPPORTED_PLATFORMS)
                ->map(function (string $platform) use ($accountsByPlatform) {
                    /** @var SocialAccount|null $account */
                    $account = $accountsByPlatform->get($platform);
                    return $this->toAccountPayload($account, $platform);
                })
                ->values()
                ->all();

            $connectedPlatforms = collect($accounts)
                ->filter(fn(array $row) => ! empty($row['connected']))
                ->pluck('platform')
                ->values()
                ->all();

            return ResponseService::successResponse('Povezani nalozi uspješno dohvaćeni', [
                'accounts' => $accounts,
                'connected_platforms' => $connectedPlatforms,
                'last_sync_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SocialMediaController -> getConnectedAccounts');
            return ResponseService::errorResponse('Greška pri dohvatu povezanih naloga');
        }
    }

    public function connectAccount(Request $request, string $platform)
    {
        try {
            $platform = strtolower(trim($platform));
            if (! in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
                return ResponseService::validationError('Nepodržana platforma');
            }

            $validator = Validator::make($request->all(), [
                'platform_user_id' => 'nullable|string|max:255',
                'account_name' => 'nullable|string|max:255',
                'access_token' => 'nullable|string',
                'refresh_token' => 'nullable|string',
                'token_expires_at' => 'nullable|date',
                'page_id' => 'nullable|string|max:255',
                'page_access_token' => 'nullable|string',
                'instagram_account_id' => 'nullable|string|max:255',
                'has_shop_access' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $existing = SocialAccount::where('user_id', $user->id)
                ->where('platform', $platform)
                ->first();

            $meta = is_array($existing?->meta) ? $existing->meta : [];
            $meta['connected_at'] = now()->toIso8601String();
            $meta['last_synced_at'] = now()->toIso8601String();
            $meta['connection_mode'] = $request->filled('access_token') ? 'token' : 'quick_connect';

            $account = SocialAccount::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'platform' => $platform,
                ],
                [
                    'platform_user_id' => $request->input('platform_user_id', sprintf('%s-%d', $platform, $user->id)),
                    'account_name' => $request->input('account_name', $user->name),
                    'access_token' => $request->input('access_token', $existing?->access_token),
                    'refresh_token' => $request->input('refresh_token', $existing?->refresh_token),
                    'token_expires_at' => $request->filled('token_expires_at')
                        ? Carbon::parse($request->input('token_expires_at'))
                        : ($existing?->token_expires_at),
                    'page_id' => $request->input('page_id', $existing?->page_id),
                    'page_access_token' => $request->input('page_access_token', $existing?->page_access_token),
                    'instagram_account_id' => $request->input('instagram_account_id', $existing?->instagram_account_id),
                    'has_shop_access' => $request->has('has_shop_access')
                        ? $request->boolean('has_shop_access')
                        : ((bool) ($existing?->has_shop_access)),
                    'is_active' => true,
                    'meta' => $meta,
                ]
            );

            return ResponseService::successResponse('Nalog uspješno povezan', [
                'platform' => $platform,
                'account' => $this->toAccountPayload($account, $platform),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SocialMediaController -> connectAccount');
            return ResponseService::errorResponse('Greška pri povezivanju naloga');
        }
    }

    public function disconnectAccount(Request $request, string $platform)
    {
        try {
            $platform = strtolower(trim($platform));
            if (! in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
                return ResponseService::validationError('Nepodržana platforma');
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $account = SocialAccount::where('user_id', $user->id)
                ->where('platform', $platform)
                ->first();

            if (! $account) {
                return ResponseService::successResponse('Nalog je već odspojen', [
                    'platform' => $platform,
                    'account' => $this->toAccountPayload(null, $platform),
                ]);
            }

            $meta = is_array($account->meta) ? $account->meta : [];
            $meta['disconnected_at'] = now()->toIso8601String();

            $account->is_active = false;
            $account->meta = $meta;
            $account->save();

            return ResponseService::successResponse('Nalog uspješno odspojen', [
                'platform' => $platform,
                'account' => $this->toAccountPayload($account->fresh(), $platform),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SocialMediaController -> disconnectAccount');
            return ResponseService::errorResponse('Greška pri prekidu povezivanja');
        }
    }

    public function syncAccount(Request $request, string $platform)
    {
        try {
            $platform = strtolower(trim($platform));
            if (! in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
                return ResponseService::validationError('Nepodržana platforma');
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $account = SocialAccount::where('user_id', $user->id)
                ->where('platform', $platform)
                ->where('is_active', true)
                ->first();

            if (! $account) {
                return ResponseService::errorResponse('Nalog nije povezan');
            }

            $meta = is_array($account->meta) ? $account->meta : [];
            $meta['last_synced_at'] = now()->toIso8601String();
            $account->meta = $meta;
            $account->save();

            return ResponseService::successResponse('Sinhronizacija je uspješno pokrenuta', [
                'platform' => $platform,
                'synced_at' => $meta['last_synced_at'],
                'account' => $this->toAccountPayload($account->fresh(), $platform),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SocialMediaController -> syncAccount');
            return ResponseService::errorResponse('Greška pri sinhronizaciji naloga');
        }
    }

    public function schedulePost(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'platforms' => 'nullable',
                'caption' => 'nullable|string|max:2200',
                'hashtags' => 'nullable|string|max:500',
                'scheduled_at' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $item = Item::where('id', $request->input('item_id'))
                ->where('user_id', $user->id)
                ->first();

            if (! $item) {
                return ResponseService::errorResponse('Nije moguće zakazati objavu za traženi oglas');
            }

            $requestedPlatforms = $this->normalizePlatforms($request->input('platforms'));
            if (count($requestedPlatforms) === 0) {
                $requestedPlatforms = ['instagram'];
            }

            $connectedPlatforms = SocialAccount::where('user_id', $user->id)
                ->whereIn('platform', $requestedPlatforms)
                ->where('is_active', true)
                ->pluck('platform')
                ->values()
                ->all();

            if (count($connectedPlatforms) === 0) {
                return ResponseService::errorResponse('Nijedna odabranа platforma nije povezana');
            }

            $scheduledAt = $request->filled('scheduled_at')
                ? Carbon::parse($request->input('scheduled_at'))
                : now()->addMinute();
            if ($scheduledAt->lte(now())) {
                $scheduledAt = now()->addMinute();
            }

            $scheduledPost = ScheduledPost::create([
                'user_id' => $user->id,
                'item_id' => $item->id,
                'platforms' => $connectedPlatforms,
                'caption' => $request->input('caption'),
                'hashtags' => $request->input('hashtags'),
                'scheduled_at' => $scheduledAt,
                'status' => ScheduledPost::STATUS_PENDING,
            ]);

            $skipped = array_values(array_diff($requestedPlatforms, $connectedPlatforms));

            return ResponseService::successResponse('Objava je uspješno zakazana', [
                'post' => [
                    'id' => $scheduledPost->id,
                    'item_id' => $scheduledPost->item_id,
                    'platforms' => $scheduledPost->platforms,
                    'status' => $scheduledPost->status,
                    'scheduled_at' => optional($scheduledPost->scheduled_at)->toIso8601String(),
                ],
                'skipped_platforms' => $skipped,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SocialMediaController -> schedulePost');
            return ResponseService::errorResponse('Greška pri zakazivanju objave');
        }
    }

    public function getScheduledPosts(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'nullable|string|max:50',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $status = $this->normalizeStatus($request->input('status'));
            $perPage = (int) ($request->input('per_page', 20));

            $query = ScheduledPost::where('user_id', $user->id)
                ->with(['item:id,name,slug,image,price'])
                ->orderByDesc('scheduled_at')
                ->orderByDesc('id');

            if ($status !== null) {
                $query->where('status', $status);
            }

            $posts = $query->paginate($perPage);

            $rows = collect($posts->items())->map(function (ScheduledPost $post) {
                return [
                    'id' => $post->id,
                    'item_id' => $post->item_id,
                    'status' => $post->status,
                    'platforms' => $post->platforms ?? [],
                    'caption' => $post->caption,
                    'hashtags' => $post->hashtags,
                    'scheduled_at' => optional($post->scheduled_at)->toIso8601String(),
                    'published_at' => optional($post->published_at)->toIso8601String(),
                    'error_message' => $post->error_message,
                    'platform_post_ids' => $post->platform_post_ids ?? [],
                    'item' => $post->item ? [
                        'id' => $post->item->id,
                        'name' => $post->item->name,
                        'slug' => $post->item->slug,
                        'image' => $post->item->image,
                        'price' => $post->item->price,
                    ] : null,
                ];
            })->values()->all();

            return ResponseService::successResponse('Zakazane objave su uspješno dohvaćene', [
                'data' => $rows,
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SocialMediaController -> getScheduledPosts');
            return ResponseService::errorResponse('Greška pri dohvatu zakazanih objava');
        }
    }

    public function cancelScheduledPost(Request $request, int $id)
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $post = ScheduledPost::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (! $post) {
                return ResponseService::errorResponse('Zakazana objava nije pronađena');
            }

            if (in_array($post->status, [ScheduledPost::STATUS_CANCELLED, ScheduledPost::STATUS_PUBLISHED], true)) {
                return ResponseService::errorResponse('Objavu nije moguće otkazati u trenutnom statusu');
            }

            $post->status = ScheduledPost::STATUS_CANCELLED;
            $post->error_message = null;
            $post->save();

            return ResponseService::successResponse('Zakazana objava je otkazana', [
                'id' => $post->id,
                'status' => $post->status,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SocialMediaController -> cancelScheduledPost');
            return ResponseService::errorResponse('Greška pri otkazivanju zakazane objave');
        }
    }

    public function retryScheduledPost(Request $request, int $id)
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $post = ScheduledPost::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (! $post) {
                return ResponseService::errorResponse('Zakazana objava nije pronađena');
            }

            if (! in_array($post->status, [ScheduledPost::STATUS_FAILED, ScheduledPost::STATUS_CANCELLED], true)) {
                return ResponseService::errorResponse('Ponovno slanje je dozvoljeno samo za neuspjele ili otkazane objave');
            }

            $post->status = ScheduledPost::STATUS_PENDING;
            $post->scheduled_at = now()->addMinute();
            $post->published_at = null;
            $post->error_message = null;
            $post->save();

            return ResponseService::successResponse('Objava je vraćena u red za slanje', [
                'id' => $post->id,
                'status' => $post->status,
                'scheduled_at' => optional($post->scheduled_at)->toIso8601String(),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SocialMediaController -> retryScheduledPost');
            return ResponseService::errorResponse('Greška pri ponovnom slanju objave');
        }
    }

    private function normalizePlatforms($rawPlatforms): array
    {
        if (is_string($rawPlatforms)) {
            $decoded = json_decode($rawPlatforms, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $rawPlatforms = $decoded;
            } else {
                $rawPlatforms = array_filter(array_map('trim', explode(',', $rawPlatforms)));
            }
        }

        if (! is_array($rawPlatforms)) {
            return [];
        }

        $platforms = collect($rawPlatforms)
            ->map(fn($platform) => strtolower(trim((string) $platform)))
            ->filter(fn($platform) => in_array($platform, self::SUPPORTED_PLATFORMS, true))
            ->unique()
            ->values()
            ->all();

        return $platforms;
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        $status = strtolower(trim($status));
        $allowed = [
            ScheduledPost::STATUS_PENDING,
            ScheduledPost::STATUS_PROCESSING,
            ScheduledPost::STATUS_PUBLISHED,
            ScheduledPost::STATUS_FAILED,
            ScheduledPost::STATUS_CANCELLED,
        ];

        return in_array($status, $allowed, true) ? $status : null;
    }

    private function toAccountPayload(?SocialAccount $account, string $platform): array
    {
        $meta = is_array($account?->meta) ? $account->meta : [];

        return [
            'platform' => $platform,
            'connected' => (bool) ($account && $account->is_active),
            'status' => ($account && $account->is_active) ? 'connected' : 'not_connected',
            'account_name' => $account?->account_name,
            'platform_user_id' => $account?->platform_user_id,
            'has_shop_access' => (bool) ($account?->has_shop_access),
            'is_token_expired' => $account ? $account->isTokenExpired() : null,
            'token_expires_at' => optional($account?->token_expires_at)->toIso8601String(),
            'last_synced_at' => $meta['last_synced_at'] ?? null,
            'meta' => $meta,
        ];
    }
}
