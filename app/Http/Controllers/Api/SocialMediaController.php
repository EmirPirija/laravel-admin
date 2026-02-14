<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class SocialMediaController extends Controller
{
    private const SUPPORTED_PLATFORMS = ['instagram', 'facebook', 'tiktok'];
    private const OAUTH_MESSAGE_TYPE = 'lmx-social-oauth';

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
                ->filter(fn (array $row) => ! empty($row['connected']))
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

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $mode = strtolower((string) $request->input('mode', 'oauth'));
            if ($mode === 'quick_connect') {
                return $this->quickConnectAccount($request, $platform, $user);
            }

            $state = $this->createOauthState($user->id, $platform);
            $authUrl = $this->buildAuthorizationUrl($platform, $state);
            if (! $authUrl) {
                return ResponseService::errorResponse(
                    'OAuth konfiguracija za ovu platformu nije postavljena. Provjerite env varijable.'
                );
            }

            return ResponseService::successResponse('OAuth autorizacija je spremna', [
                'platform' => $platform,
                'mode' => 'oauth',
                'auth_url' => $authUrl,
                'state' => $state,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'SocialMediaController -> connectAccount');
            return ResponseService::errorResponse('Greška pri povezivanju naloga');
        }
    }

    public function handleCallback(Request $request, string $platform)
    {
        $platform = strtolower(trim($platform));
        if (! in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
            return $this->popupCallbackResponse(false, 'Nepodržana platforma', $platform, [], 400);
        }

        try {
            $providerError = trim((string) ($request->input('error_description') ?: $request->input('error') ?: ''));
            if ($providerError !== '') {
                return $this->popupCallbackResponse(false, $providerError, $platform, [], 400);
            }

            $state = (string) $request->input('state', '');
            if ($state === '') {
                return $this->popupCallbackResponse(false, 'Nedostaje state parametar.', $platform, [], 400);
            }

            $stateData = $this->consumeOauthState($state);
            if (! is_array($stateData)) {
                return $this->popupCallbackResponse(false, 'OAuth state je istekao. Pokrenite povezivanje ponovo.', $platform, [], 400);
            }

            if (($stateData['platform'] ?? null) !== $platform) {
                return $this->popupCallbackResponse(false, 'OAuth state nije validan za traženu platformu.', $platform, [], 400);
            }

            $userId = (int) ($stateData['user_id'] ?? 0);
            $user = User::find($userId);
            if (! $user) {
                return $this->popupCallbackResponse(false, 'Korisnik nije pronađen.', $platform, [], 404);
            }

            $code = trim((string) $request->input('code', ''));
            if ($code === '') {
                return $this->popupCallbackResponse(false, 'Nedostaje autorizacioni kod.', $platform, [], 400);
            }

            $exchangeResult = $this->exchangeOAuthCode($platform, $code);
            if (! ($exchangeResult['success'] ?? false)) {
                $message = (string) ($exchangeResult['message'] ?? 'OAuth razmjena nije uspjela.');
                return $this->popupCallbackResponse(false, $message, $platform, [
                    'details' => $exchangeResult['details'] ?? null,
                ], 400);
            }

            $connectionData = $exchangeResult['data'] ?? [];
            $existing = SocialAccount::where('user_id', $user->id)
                ->where('platform', $platform)
                ->first();

            $meta = is_array($existing?->meta) ? $existing->meta : [];
            $meta = array_merge($meta, (array) ($connectionData['meta'] ?? []));
            $meta['connected_at'] = now()->toIso8601String();
            $meta['last_synced_at'] = now()->toIso8601String();
            $meta['connection_mode'] = 'oauth';
            $meta['oauth_provider'] = $platform === 'instagram' ? 'facebook' : $platform;

            $account = SocialAccount::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'platform' => $platform,
                ],
                [
                    'platform_user_id' => (string) ($connectionData['platform_user_id'] ?? ($existing?->platform_user_id ?? '')),
                    'account_name' => (string) ($connectionData['account_name'] ?? ($existing?->account_name ?? $user->name)),
                    'access_token' => $connectionData['access_token'] ?? $existing?->access_token,
                    'refresh_token' => $connectionData['refresh_token'] ?? $existing?->refresh_token,
                    'token_expires_at' => $connectionData['token_expires_at'] ?? $existing?->token_expires_at,
                    'page_id' => $connectionData['page_id'] ?? $existing?->page_id,
                    'page_access_token' => $connectionData['page_access_token'] ?? $existing?->page_access_token,
                    'instagram_account_id' => $connectionData['instagram_account_id'] ?? $existing?->instagram_account_id,
                    'has_shop_access' => isset($connectionData['has_shop_access'])
                        ? (bool) $connectionData['has_shop_access']
                        : (bool) ($existing?->has_shop_access),
                    'is_active' => true,
                    'meta' => $meta,
                ]
            );

            return $this->popupCallbackResponse(true, 'Nalog je uspješno povezan.', $platform, [
                'account' => $this->toAccountPayload($account->fresh(), $platform),
            ]);
        } catch (Throwable $th) {
            Log::error('SocialMediaController -> handleCallback: ' . $th->getMessage(), [
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'platform' => $platform,
                'query' => $request->query(),
            ]);

            return $this->popupCallbackResponse(false, 'Greška tokom OAuth callback obrade.', $platform, [], 500);
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

            if (! $this->isAccountReady($account, $platform)) {
                return ResponseService::errorResponse('Nalog nije potpuno konfigurisan. Povežite platformu ponovo kroz OAuth.');
            }

            if ($account->isTokenExpired()) {
                return ResponseService::errorResponse('Token je istekao. Ponovo povežite nalog.');
            }

            $syncResult = $this->syncRemoteAccount($platform, $account);
            if (! ($syncResult['success'] ?? false)) {
                return ResponseService::errorResponse((string) ($syncResult['message'] ?? 'Sinhronizacija nije uspjela.'));
            }

            $meta = is_array($account->meta) ? $account->meta : [];
            $meta = array_merge($meta, (array) ($syncResult['meta'] ?? []));
            $meta['last_synced_at'] = now()->toIso8601String();
            $account->meta = $meta;

            if (! empty($syncResult['account_name'])) {
                $account->account_name = (string) $syncResult['account_name'];
            }
            if (! empty($syncResult['platform_user_id'])) {
                $account->platform_user_id = (string) $syncResult['platform_user_id'];
            }
            if (! empty($syncResult['page_id'])) {
                $account->page_id = (string) $syncResult['page_id'];
            }
            if (! empty($syncResult['instagram_account_id'])) {
                $account->instagram_account_id = (string) $syncResult['instagram_account_id'];
            }

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

            $accounts = SocialAccount::where('user_id', $user->id)
                ->whereIn('platform', $requestedPlatforms)
                ->where('is_active', true)
                ->get()
                ->keyBy('platform');

            $connectedPlatforms = collect($requestedPlatforms)
                ->filter(function (string $p) use ($accounts) {
                    /** @var SocialAccount|null $account */
                    $account = $accounts->get($p);
                    return $account
                        && $this->isAccountReady($account, $p)
                        && ! $account->isTokenExpired();
                })
                ->values()
                ->all();

            if (count($connectedPlatforms) === 0) {
                return ResponseService::errorResponse('Nijedna odabrana platforma nije validno povezana');
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

    private function quickConnectAccount(Request $request, string $platform, User $user)
    {
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

        $existing = SocialAccount::where('user_id', $user->id)
            ->where('platform', $platform)
            ->first();

        $meta = is_array($existing?->meta) ? $existing->meta : [];
        $meta['connected_at'] = now()->toIso8601String();
        $meta['last_synced_at'] = now()->toIso8601String();
        $meta['connection_mode'] = 'quick_connect';

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

        return ResponseService::successResponse('Quick connect je sačuvan (test način)', [
            'platform' => $platform,
            'mode' => 'quick_connect',
            'account' => $this->toAccountPayload($account, $platform),
        ]);
    }

    private function exchangeOAuthCode(string $platform, string $code): array
    {
        return match ($platform) {
            'instagram', 'facebook' => $this->exchangeMetaCode($platform, $code),
            'tiktok' => $this->exchangeTikTokCode($code),
            default => [
                'success' => false,
                'message' => 'Nepodržana platforma.',
            ],
        };
    }

    private function exchangeMetaCode(string $platform, string $code): array
    {
        $clientId = (string) config('services.social.facebook.client_id');
        $clientSecret = (string) config('services.social.facebook.client_secret');
        $graphVersion = trim((string) config('services.social.facebook.graph_version', 'v20.0'));
        $redirectUri = $this->resolveMetaRedirectUri($platform);

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            return [
                'success' => false,
                'message' => 'Meta OAuth nije konfigurisan. Provjerite APP ID/SECRET i redirect URI.',
            ];
        }

        $tokenResponse = Http::timeout(20)->get("https://graph.facebook.com/{$graphVersion}/oauth/access_token", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (! $tokenResponse->successful()) {
            return [
                'success' => false,
                'message' => $this->extractProviderError($tokenResponse, 'Neuspješna razmjena Meta OAuth koda'),
            ];
        }

        $tokenData = $tokenResponse->json();
        $accessToken = (string) data_get($tokenData, 'access_token', '');
        $expiresIn = (int) data_get($tokenData, 'expires_in', 0);
        if ($accessToken === '') {
            return [
                'success' => false,
                'message' => 'Meta nije vratila access token.',
            ];
        }

        $longTokenResponse = Http::timeout(20)->get("https://graph.facebook.com/{$graphVersion}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'fb_exchange_token' => $accessToken,
        ]);

        if ($longTokenResponse->successful()) {
            $longTokenData = $longTokenResponse->json();
            $accessToken = (string) data_get($longTokenData, 'access_token', $accessToken);
            $expiresIn = (int) data_get($longTokenData, 'expires_in', $expiresIn);
        }

        $tokenExpiresAt = $expiresIn > 0 ? now()->addSeconds($expiresIn) : null;

        $userResponse = Http::timeout(20)->get("https://graph.facebook.com/{$graphVersion}/me", [
            'fields' => 'id,name',
            'access_token' => $accessToken,
        ]);
        if (! $userResponse->successful()) {
            return [
                'success' => false,
                'message' => $this->extractProviderError($userResponse, 'Ne mogu dohvatiti Meta korisnički profil'),
            ];
        }

        $metaUser = $userResponse->json();
        $pagesResponse = Http::timeout(20)->get("https://graph.facebook.com/{$graphVersion}/me/accounts", [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'access_token' => $accessToken,
        ]);
        if (! $pagesResponse->successful()) {
            return [
                'success' => false,
                'message' => $this->extractProviderError($pagesResponse, 'Ne mogu dohvatiti Facebook stranice'),
            ];
        }

        $pages = collect((array) data_get($pagesResponse->json(), 'data', []));
        $pageWithAccess = $pages->first(function ($page) {
            return is_array($page) && ! empty($page['id']) && ! empty($page['access_token']);
        });

        if (! is_array($pageWithAccess)) {
            return [
                'success' => false,
                'message' => 'Nije pronađena Facebook stranica sa pristupnim tokenom.',
            ];
        }

        $instagramBusinessAccount = $pages
            ->map(fn ($page) => is_array($page) ? ($page['instagram_business_account'] ?? null) : null)
            ->filter(fn ($ig) => is_array($ig) && ! empty($ig['id']))
            ->first();

        if ($platform === 'instagram' && ! is_array($instagramBusinessAccount)) {
            return [
                'success' => false,
                'message' => 'Instagram Business nalog nije povezan na Facebook stranicu.',
            ];
        }

        if ($platform === 'instagram') {
            $igId = (string) ($instagramBusinessAccount['id'] ?? '');
            $igUsername = (string) ($instagramBusinessAccount['username'] ?? ($metaUser['name'] ?? 'Instagram nalog'));

            return [
                'success' => true,
                'data' => [
                    'platform_user_id' => $igId,
                    'account_name' => $igUsername,
                    'access_token' => $accessToken,
                    'refresh_token' => null,
                    'token_expires_at' => $tokenExpiresAt,
                    'page_id' => (string) ($pageWithAccess['id'] ?? ''),
                    'page_access_token' => (string) ($pageWithAccess['access_token'] ?? ''),
                    'instagram_account_id' => $igId,
                    'has_shop_access' => true,
                    'meta' => [
                        'graph_version' => $graphVersion,
                        'graph_user_id' => (string) ($metaUser['id'] ?? ''),
                        'graph_user_name' => (string) ($metaUser['name'] ?? ''),
                        'facebook_page_id' => (string) ($pageWithAccess['id'] ?? ''),
                        'facebook_page_name' => (string) ($pageWithAccess['name'] ?? ''),
                        'instagram_username' => $igUsername,
                    ],
                ],
            ];
        }

        return [
            'success' => true,
            'data' => [
                'platform_user_id' => (string) ($metaUser['id'] ?? ''),
                'account_name' => (string) ($pageWithAccess['name'] ?? ($metaUser['name'] ?? 'Facebook nalog')),
                'access_token' => $accessToken,
                'refresh_token' => null,
                'token_expires_at' => $tokenExpiresAt,
                'page_id' => (string) ($pageWithAccess['id'] ?? ''),
                'page_access_token' => (string) ($pageWithAccess['access_token'] ?? ''),
                'instagram_account_id' => is_array($instagramBusinessAccount)
                    ? (string) ($instagramBusinessAccount['id'] ?? '')
                    : null,
                'has_shop_access' => true,
                'meta' => [
                    'graph_version' => $graphVersion,
                    'graph_user_id' => (string) ($metaUser['id'] ?? ''),
                    'graph_user_name' => (string) ($metaUser['name'] ?? ''),
                    'facebook_page_id' => (string) ($pageWithAccess['id'] ?? ''),
                    'facebook_page_name' => (string) ($pageWithAccess['name'] ?? ''),
                ],
            ],
        ];
    }

    private function exchangeTikTokCode(string $code): array
    {
        $clientKey = (string) config('services.social.tiktok.client_key');
        $clientSecret = (string) config('services.social.tiktok.client_secret');
        $redirectUri = $this->resolveTikTokRedirectUri();

        if ($clientKey === '' || $clientSecret === '' || $redirectUri === '') {
            return [
                'success' => false,
                'message' => 'TikTok OAuth nije konfigurisan. Provjerite client key/secret i redirect URI.',
            ];
        }

        $tokenResponse = Http::asForm()
            ->timeout(20)
            ->post('https://open.tiktokapis.com/v2/oauth/token/', [
                'client_key' => $clientKey,
                'client_secret' => $clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]);

        if (! $tokenResponse->successful()) {
            return [
                'success' => false,
                'message' => $this->extractProviderError($tokenResponse, 'Neuspješna razmjena TikTok OAuth koda'),
            ];
        }

        $tokenData = (array) $tokenResponse->json();
        $accessToken = (string) (data_get($tokenData, 'access_token') ?? data_get($tokenData, 'data.access_token') ?? '');
        $refreshToken = (string) (data_get($tokenData, 'refresh_token') ?? data_get($tokenData, 'data.refresh_token') ?? '');
        $expiresIn = (int) (data_get($tokenData, 'expires_in') ?? data_get($tokenData, 'data.expires_in') ?? 0);
        $openId = (string) (data_get($tokenData, 'open_id') ?? data_get($tokenData, 'data.open_id') ?? '');

        if ($accessToken === '') {
            return [
                'success' => false,
                'message' => 'TikTok nije vratio access token.',
            ];
        }

        $profileResponse = Http::withToken($accessToken)
            ->timeout(20)
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,display_name,avatar_url',
            ]);

        $displayName = 'TikTok nalog';
        $avatarUrl = null;
        if ($profileResponse->successful()) {
            $profilePayload = (array) $profileResponse->json();
            $profileData = (array) (data_get($profilePayload, 'data.user') ?? data_get($profilePayload, 'data') ?? []);
            $openId = (string) ($profileData['open_id'] ?? $openId);
            $displayName = (string) ($profileData['display_name'] ?? $displayName);
            $avatarUrl = $profileData['avatar_url'] ?? null;
        }

        if ($openId === '') {
            $openId = 'tiktok-' . Str::random(12);
        }

        return [
            'success' => true,
            'data' => [
                'platform_user_id' => $openId,
                'account_name' => $displayName,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken !== '' ? $refreshToken : null,
                'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
                'page_id' => null,
                'page_access_token' => null,
                'instagram_account_id' => null,
                'has_shop_access' => false,
                'meta' => [
                    'tiktok_open_id' => $openId,
                    'avatar_url' => $avatarUrl,
                    'scopes' => (string) (data_get($tokenData, 'scope') ?? data_get($tokenData, 'data.scope') ?? ''),
                ],
            ],
        ];
    }

    private function syncRemoteAccount(string $platform, SocialAccount $account): array
    {
        return match ($platform) {
            'instagram' => $this->syncInstagramAccount($account),
            'facebook' => $this->syncFacebookAccount($account),
            'tiktok' => $this->syncTikTokAccount($account),
            default => [
                'success' => false,
                'message' => 'Nepodržana platforma.',
            ],
        };
    }

    private function syncInstagramAccount(SocialAccount $account): array
    {
        $graphVersion = trim((string) config('services.social.facebook.graph_version', 'v20.0'));
        $igId = (string) ($account->instagram_account_id ?? '');
        $token = (string) ($account->access_token ?? '');

        $response = Http::timeout(20)->get("https://graph.facebook.com/{$graphVersion}/{$igId}", [
            'fields' => 'id,username',
            'access_token' => $token,
        ]);

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => $this->extractProviderError($response, 'Instagram sync nije uspio'),
            ];
        }

        $data = (array) $response->json();
        return [
            'success' => true,
            'account_name' => (string) ($data['username'] ?? $account->account_name),
            'platform_user_id' => (string) ($data['id'] ?? $account->platform_user_id),
            'instagram_account_id' => (string) ($data['id'] ?? $account->instagram_account_id),
            'meta' => [
                'instagram_username' => (string) ($data['username'] ?? ''),
            ],
        ];
    }

    private function syncFacebookAccount(SocialAccount $account): array
    {
        $graphVersion = trim((string) config('services.social.facebook.graph_version', 'v20.0'));
        $pageId = (string) ($account->page_id ?? '');
        $pageToken = (string) ($account->page_access_token ?? '');

        $response = Http::timeout(20)->get("https://graph.facebook.com/{$graphVersion}/{$pageId}", [
            'fields' => 'id,name',
            'access_token' => $pageToken,
        ]);

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => $this->extractProviderError($response, 'Facebook sync nije uspio'),
            ];
        }

        $data = (array) $response->json();
        return [
            'success' => true,
            'account_name' => (string) ($data['name'] ?? $account->account_name),
            'page_id' => (string) ($data['id'] ?? $account->page_id),
            'meta' => [
                'facebook_page_name' => (string) ($data['name'] ?? ''),
            ],
        ];
    }

    private function syncTikTokAccount(SocialAccount $account): array
    {
        $token = (string) ($account->access_token ?? '');
        $response = Http::withToken($token)
            ->timeout(20)
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,display_name,avatar_url',
            ]);

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => $this->extractProviderError($response, 'TikTok sync nije uspio'),
            ];
        }

        $payload = (array) $response->json();
        $data = (array) (data_get($payload, 'data.user') ?? data_get($payload, 'data') ?? []);

        return [
            'success' => true,
            'account_name' => (string) ($data['display_name'] ?? $account->account_name),
            'platform_user_id' => (string) ($data['open_id'] ?? $account->platform_user_id),
            'meta' => [
                'avatar_url' => $data['avatar_url'] ?? null,
            ],
        ];
    }

    private function buildAuthorizationUrl(string $platform, string $state): ?string
    {
        return match ($platform) {
            'instagram', 'facebook' => $this->buildMetaAuthorizationUrl($platform, $state),
            'tiktok' => $this->buildTikTokAuthorizationUrl($state),
            default => null,
        };
    }

    private function buildMetaAuthorizationUrl(string $platform, string $state): ?string
    {
        $clientId = (string) config('services.social.facebook.client_id');
        $graphVersion = trim((string) config('services.social.facebook.graph_version', 'v20.0'));
        $redirectUri = $this->resolveMetaRedirectUri($platform);
        if ($clientId === '' || $redirectUri === '') {
            return null;
        }

        $scopeConfigKey = $platform === 'instagram' ? 'scopes_instagram' : 'scopes_facebook';
        $scope = $this->normalizeScopes((string) config("services.social.facebook.{$scopeConfigKey}", ''));

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
        ];
        if ($scope !== '') {
            $params['scope'] = $scope;
        }

        return 'https://www.facebook.com/' . $graphVersion . '/dialog/oauth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function buildTikTokAuthorizationUrl(string $state): ?string
    {
        $clientKey = (string) config('services.social.tiktok.client_key');
        $redirectUri = $this->resolveTikTokRedirectUri();
        if ($clientKey === '' || $redirectUri === '') {
            return null;
        }

        $scope = $this->normalizeScopes((string) config('services.social.tiktok.scopes', 'user.info.basic'));
        $params = [
            'client_key' => $clientKey,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];
        if ($scope !== '') {
            $params['scope'] = $scope;
        }

        return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function resolveMetaRedirectUri(string $platform): string
    {
        $configKey = $platform === 'instagram' ? 'redirect_uri_instagram' : 'redirect_uri_facebook';
        $configured = trim((string) config("services.social.facebook.{$configKey}", ''));
        if ($configured !== '') {
            return $configured;
        }

        return url('/api/social/callback/' . $platform);
    }

    private function resolveTikTokRedirectUri(): string
    {
        $configured = trim((string) config('services.social.tiktok.redirect_uri', ''));
        if ($configured !== '') {
            return $configured;
        }

        return url('/api/social/callback/tiktok');
    }

    private function normalizeScopes(string $rawScopes): string
    {
        return collect(preg_split('/[\s,]+/', $rawScopes) ?: [])
            ->map(fn ($scope) => trim((string) $scope))
            ->filter(fn ($scope) => $scope !== '')
            ->unique()
            ->values()
            ->implode(',');
    }

    private function createOauthState(int $userId, string $platform): string
    {
        $state = Str::random(64);
        $ttlMinutes = max(1, (int) config('services.social.oauth_state_ttl', 10));
        Cache::put(
            "social_oauth_state:{$state}",
            [
                'user_id' => $userId,
                'platform' => $platform,
                'created_at' => now()->toIso8601String(),
            ],
            now()->addMinutes($ttlMinutes)
        );

        return $state;
    }

    private function consumeOauthState(string $state): ?array
    {
        $cached = Cache::pull("social_oauth_state:{$state}");
        return is_array($cached) ? $cached : null;
    }

    private function popupCallbackResponse(
        bool $success,
        string $message,
        string $platform,
        array $extra = [],
        int $status = 200
    ) {
        $payload = array_merge([
            'type' => self::OAUTH_MESSAGE_TYPE,
            'source' => 'lmx-social-callback',
            'success' => $success,
            'platform' => $platform,
            'message' => $message,
        ], $extra);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $title = $success ? 'Povezivanje uspješno' : 'Povezivanje nije uspjelo';
        $statusClass = $success ? 'ok' : 'err';

        $html = <<<HTML
<!doctype html>
<html lang="bs">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{$title}</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; display: grid; place-items: center; min-height: 100vh; }
    .card { max-width: 460px; margin: 20px; padding: 20px; border-radius: 14px; background: #111827; border: 1px solid #334155; }
    .ok { color: #34d399; }
    .err { color: #fda4af; }
    p { margin: 0; line-height: 1.5; }
  </style>
</head>
<body>
  <div class="card">
    <p class="{$statusClass}"><strong>{$title}</strong></p>
    <p id="message" style="margin-top: 10px;">{$safeMessage}</p>
  </div>
  <script>
    (function() {
      const payload = {$payloadJson};
      try {
        if (window.opener && !window.opener.closed) {
          window.opener.postMessage(payload, "*");
        }
      } catch (err) {
        // noop
      }
      setTimeout(function() { window.close(); }, 120);
    })();
  </script>
</body>
</html>
HTML;

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function extractProviderError($response, string $fallback): string
    {
        $payload = [];
        try {
            $payload = (array) $response->json();
        } catch (Throwable) {
            $payload = [];
        }

        $message = (string) (
            data_get($payload, 'error.message')
            ?? data_get($payload, 'error_description')
            ?? data_get($payload, 'description')
            ?? data_get($payload, 'message')
            ?? $fallback
        );

        return trim($message) !== '' ? $message : $fallback;
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

        return collect($rawPlatforms)
            ->map(fn ($platform) => strtolower(trim((string) $platform)))
            ->filter(fn ($platform) => in_array($platform, self::SUPPORTED_PLATFORMS, true))
            ->unique()
            ->values()
            ->all();
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

    private function isAccountReady(?SocialAccount $account, string $platform): bool
    {
        if (! $account || ! $account->is_active) {
            return false;
        }

        return match ($platform) {
            'instagram' => ! empty($account->access_token) && ! empty($account->instagram_account_id),
            'facebook' => ! empty($account->page_id) && ! empty($account->page_access_token),
            'tiktok' => ! empty($account->access_token) && ! empty($account->platform_user_id),
            default => false,
        };
    }

    private function toAccountPayload(?SocialAccount $account, string $platform): array
    {
        $meta = is_array($account?->meta) ? $account->meta : [];
        $linked = (bool) ($account && $account->is_active);
        $tokenExpired = $account ? $account->isTokenExpired() : null;
        $ready = $this->isAccountReady($account, $platform);
        $connected = $linked && $ready && ! ($tokenExpired === true);

        $status = 'not_connected';
        if ($linked) {
            if ($tokenExpired === true) {
                $status = 'token_expired';
            } elseif ($ready) {
                $status = 'connected';
            } else {
                $status = 'action_required';
            }
        }

        return [
            'platform' => $platform,
            'connected' => $connected,
            'linked' => $linked,
            'status' => $status,
            'account_name' => $account?->account_name,
            'platform_user_id' => $account?->platform_user_id,
            'has_shop_access' => (bool) ($account?->has_shop_access),
            'is_token_expired' => $tokenExpired,
            'token_expires_at' => optional($account?->token_expires_at)->toIso8601String(),
            'last_synced_at' => $meta['last_synced_at'] ?? null,
            'connection_mode' => $meta['connection_mode'] ?? null,
            'page_id' => $account?->page_id,
            'instagram_account_id' => $account?->instagram_account_id,
            'meta' => $meta,
        ];
    }
}
