<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstagramImport;
use App\Models\Item;
use App\Models\SocialAccount;
use App\Services\FeedImportProcessorService;
use App\Services\ResponseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class InstagramController extends Controller
{
    public function getProducts(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'search' => 'nullable|string|max:255',
                'synced_only' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $perPage = (int) ($request->input('per_page', 20));
            $query = Item::where('user_id', $user->id)
                ->with('gallery_images:id,image,item_id')
                ->orderByDesc('id');

            if ($request->filled('search')) {
                $search = trim((string) $request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                        ->orWhere('slug', 'like', '%' . $search . '%');
                });
            }

            if ($request->boolean('synced_only')) {
                $query->whereNotNull('instagram_product_id');
            }

            $rows = $query->paginate($perPage);

            $items = collect($rows->items())->map(function (Item $item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'price' => $item->price,
                    'price_per_unit' => Schema::hasColumn('items', 'price_per_unit')
                        ? $item->getAttribute('price_per_unit')
                        : null,
                    'inventory_count' => $item->inventory_count,
                    'image' => $item->image,
                    'gallery' => $item->gallery_images?->pluck('image')->values()->all() ?? [],
                    'instagram_product_id' => $item->getAttribute('instagram_product_id'),
                    'instagram_synced_at' => $item->getAttribute('instagram_synced_at'),
                    'instagram_source_url' => Schema::hasColumn('items', 'instagram_source_url')
                        ? $item->getAttribute('instagram_source_url')
                        : null,
                    'publish_to_instagram' => Schema::hasColumn('items', 'publish_to_instagram')
                        ? (bool) $item->getAttribute('publish_to_instagram')
                        : false,
                    'is_synced' => ! empty($item->getAttribute('instagram_product_id')),
                ];
            })->values()->all();

            return ResponseService::successResponse('Feed proizvodi su uspješno dohvaćeni', [
                'data' => $items,
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'InstagramController -> getProducts');
            return ResponseService::errorResponse('Greška pri dohvatu Instagram proizvoda');
        }
    }

    public function importProducts(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'source_url' => 'nullable|url|max:1000',
                'source_urls' => 'nullable',
                'category_id' => 'nullable|integer|exists:categories,id',
                'format' => 'nullable|in:api,csv,xml',
                'feed_file' => 'nullable|file|max:15360|mimes:csv,txt,xml',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $sourceUrl = $request->filled('source_url')
                ? trim((string) $request->input('source_url'))
                : null;

            $urls = $this->normalizeUrls($request->input('source_urls'));
            if (! empty($sourceUrl)) {
                $urls[] = $sourceUrl;
            }

            $uploadedFile = $request->file('feed_file');
            $format = $this->resolveImportFormat($request->input('format'), $uploadedFile);

            if ($uploadedFile instanceof UploadedFile) {
                $urls = array_merge($urls, $this->extractUrlsFromUpload($uploadedFile, $format));
            }

            $urls = array_values(array_unique(array_filter($urls)));

            if (count($urls) === 0) {
                return ResponseService::validationError('Pošaljite barem jedan važeći URL ili feed datoteku sa URL-ovima');
            }

            $importPayload = [
                'user_id' => $user->id,
                'products_requested' => count($urls),
                'products_imported' => 0,
                'products_failed' => 0,
                'category_id' => $request->input('category_id'),
            ];

            if (Schema::hasColumn('instagram_imports', 'source_url')) {
                $importPayload['source_url'] = $sourceUrl ?: ($urls[0] ?? null);
            }
            if (Schema::hasColumn('instagram_imports', 'source_urls_json')) {
                $importPayload['source_urls_json'] = $urls;
            }
            if (Schema::hasColumn('instagram_imports', 'feed_format')) {
                $importPayload['feed_format'] = $format;
            }
            if (Schema::hasColumn('instagram_imports', 'status')) {
                $importPayload['status'] = 'queued';
            }
            if (Schema::hasColumn('instagram_imports', 'message')) {
                $importPayload['message'] = 'Feed import je zaprimljen i čeka obradu.';
            }
            if (Schema::hasColumn('instagram_imports', 'meta')) {
                $importPayload['meta'] = [
                    'file_name' => $uploadedFile?->getClientOriginalName(),
                    'file_mime' => $uploadedFile?->getMimeType(),
                    'source_urls_count' => count($urls),
                ];
            }

            $import = InstagramImport::create($importPayload);

            // Obradi odmah kako status ne bi ostao na "čekanju" bez razloga.
            $import = app(FeedImportProcessorService::class)->processImport($import, $urls);

            return ResponseService::successResponse('Feed import je uspješno evidentiran', [
                'import' => [
                    'id' => $import->id,
                    'products_requested' => $import->products_requested,
                    'products_imported' => $import->products_imported,
                    'products_failed' => $import->products_failed,
                    'category_id' => $import->category_id,
                    'status' => $import->status ?? 'queued',
                    'source_url' => $import->source_url ?? ($sourceUrl ?: ($urls[0] ?? null)),
                    'format' => $import->feed_format ?? $format,
                    'message' => $import->message ?? null,
                    'created_at' => optional($import->created_at)->toIso8601String(),
                ],
                'queued_urls' => $urls,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'InstagramController -> importProducts');
            return ResponseService::errorResponse('Greška pri uvozu feed proizvoda');
        }
    }

    public function getImportHistory(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
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

            $this->processQueuedImportsForUser((int) $user->id);

            $perPage = (int) ($request->input('per_page', 20));
            $rows = InstagramImport::where('user_id', $user->id)
                ->with('category:id,name,slug')
                ->orderByDesc('id')
                ->paginate($perPage);

            $history = collect($rows->items())->map(function (InstagramImport $import) {
                $status = (string) ($import->status ?? 'completed');
                $sourceUrl = $import->source_url ?? null;

                if (empty($sourceUrl) && is_array($import->source_urls_json) && count($import->source_urls_json) > 0) {
                    $sourceUrl = $import->source_urls_json[0];
                }

                return [
                    'id' => $import->id,
                    'products_requested' => (int) $import->products_requested,
                    'products_imported' => (int) $import->products_imported,
                    'products_failed' => (int) $import->products_failed,
                    'requested_count' => (int) $import->products_requested,
                    'imported_count' => (int) $import->products_imported,
                    'failed_count' => (int) $import->products_failed,
                    'status' => $status,
                    'source_url' => $sourceUrl,
                    'feed_format' => $import->feed_format ?? 'api',
                    'message' => $import->message,
                    'meta' => $import->meta,
                    'category_id' => $import->category_id,
                    'category' => $import->category ? [
                        'id' => $import->category->id,
                        'name' => $import->category->name,
                        'slug' => $import->category->slug,
                    ] : null,
                    'started_at' => optional($import->created_at)->toIso8601String(),
                    'processed_at' => optional($import->processed_at)->toIso8601String(),
                    'created_at' => optional($import->created_at)->toIso8601String(),
                ];
            })->values()->all();

            return ResponseService::successResponse('Historija feed importa uspješno dohvaćena', [
                'data' => $history,
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'InstagramController -> getImportHistory');
            return ResponseService::errorResponse('Greška pri dohvatu historije feed importa');
        }
    }

    public function syncProduct(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'instagram_product_id' => 'nullable|string|max:255',
                'source_url' => 'nullable|url|max:1000',
                'publish_to_instagram' => 'nullable|boolean',
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
                return ResponseService::errorResponse('Traženi oglas nije pronađen');
            }

            $sourceUrl = $request->input('source_url');
            $instagramProductId = $request->input('instagram_product_id');

            if (! $instagramProductId && $sourceUrl) {
                $instagramProductId = 'ig_' . Str::lower(Str::substr(sha1($sourceUrl . '|' . $item->id), 0, 16));
            }

            if (! $instagramProductId && ! $item->getAttribute('instagram_product_id')) {
                return ResponseService::validationError('Pošaljite instagram_product_id ili source_url');
            }

            if ($instagramProductId) {
                $item->setAttribute('instagram_product_id', $instagramProductId);
            }

            if (Schema::hasColumn('items', 'instagram_source_url') && $request->has('source_url')) {
                $item->setAttribute('instagram_source_url', $sourceUrl);
            }

            if (Schema::hasColumn('items', 'publish_to_instagram') && $request->has('publish_to_instagram')) {
                $item->setAttribute('publish_to_instagram', $request->boolean('publish_to_instagram'));
            }

            if (Schema::hasColumn('items', 'instagram_synced_at')) {
                $item->setAttribute('instagram_synced_at', now());
            }

            $item->save();

            $account = SocialAccount::where('user_id', $user->id)
                ->where('platform', 'instagram')
                ->where('is_active', true)
                ->first();
            if ($account) {
                $meta = is_array($account->meta) ? $account->meta : [];
                $meta['last_synced_at'] = now()->toIso8601String();
                $account->meta = $meta;
                $account->save();
            }

            return ResponseService::successResponse('Instagram sync je uspješno završen', $this->buildSyncPayload($item, $account));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'InstagramController -> syncProduct');
            return ResponseService::errorResponse('Greška pri sinhronizaciji proizvoda');
        }
    }

    public function getSyncStatus(Request $request, int $itemId)
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $item = Item::where('id', $itemId)
                ->where('user_id', $user->id)
                ->first();

            if (! $item) {
                return ResponseService::errorResponse('Traženi oglas nije pronađen');
            }

            $account = SocialAccount::where('user_id', $user->id)
                ->where('platform', 'instagram')
                ->where('is_active', true)
                ->first();

            return ResponseService::successResponse('Sync status uspješno dohvaćen', $this->buildSyncPayload($item, $account));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'InstagramController -> getSyncStatus');
            return ResponseService::errorResponse('Greška pri dohvatu sync statusa');
        }
    }

    private function processQueuedImportsForUser(int $userId): void
    {
        if (! Schema::hasColumn('instagram_imports', 'status')) {
            return;
        }

        $pendingImports = InstagramImport::where('user_id', $userId)
            ->whereIn('status', ['queued', 'processing'])
            ->orderBy('id')
            ->limit(5)
            ->get();

        if ($pendingImports->isEmpty()) {
            return;
        }

        $processor = app(FeedImportProcessorService::class);

        foreach ($pendingImports as $import) {
            try {
                $processor->processImport($import);
            } catch (Throwable $th) {
                ResponseService::logErrorResponse($th, 'InstagramController -> processQueuedImportsForUser');
            }
        }
    }

    private function normalizeUrls($sourceUrls): array
    {
        if (is_string($sourceUrls)) {
            $decoded = json_decode($sourceUrls, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $sourceUrls = $decoded;
            } else {
                $sourceUrls = array_filter(array_map('trim', explode(',', $sourceUrls)));
            }
        }

        if (! is_array($sourceUrls)) {
            return [];
        }

        return collect($sourceUrls)
            ->map(fn($url) => trim((string) $url))
            ->filter(fn($url) => filter_var($url, FILTER_VALIDATE_URL))
            ->values()
            ->all();
    }

    private function resolveImportFormat(?string $format, ?UploadedFile $file = null): string
    {
        $normalized = Str::lower(trim((string) $format));
        if (in_array($normalized, ['api', 'csv', 'xml'], true)) {
            return $normalized;
        }

        if ($file instanceof UploadedFile) {
            $extension = Str::lower((string) $file->getClientOriginalExtension());
            if ($extension === 'xml') {
                return 'xml';
            }
            if (in_array($extension, ['csv', 'txt'], true)) {
                return 'csv';
            }
        }

        return 'api';
    }

    private function extractUrlsFromUpload(UploadedFile $file, string $format): array
    {
        $content = (string) @file_get_contents($file->getRealPath());
        if ($content === '') {
            return [];
        }

        if ($format === 'xml') {
            return $this->extractUrlsFromXml($content);
        }

        return $this->extractUrlsFromCsv($content);
    }

    private function extractUrlsFromCsv(string $content): array
    {
        $rows = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $rows = array_values(array_filter(array_map(static fn($row) => trim((string) $row), $rows)));
        if (count($rows) === 0) {
            return [];
        }

        $urls = [];
        foreach ($rows as $row) {
            $cells = preg_split('/[,;\t|]/', $row) ?: [];
            foreach ($cells as $cell) {
                $candidate = trim((string) $cell, " \t\n\r\0\x0B\"'");
                if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                    $urls[] = $candidate;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function extractUrlsFromXml(string $content): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [];
        }

        $nodes = ['loc', 'link', 'url', 'source_url', 'product_url', 'item_url'];
        $urls = [];
        foreach ($nodes as $nodeName) {
            $results = $xml->xpath(sprintf('//*[local-name()="%s"]', $nodeName)) ?: [];
            foreach ($results as $node) {
                $candidate = trim((string) $node);
                if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                    $urls[] = $candidate;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function buildSyncPayload(Item $item, ?SocialAccount $account = null): array
    {
        $syncedAt = $item->getAttribute('instagram_synced_at');
        $syncedAtIso = null;
        if ($syncedAt) {
            try {
                $syncedAtIso = \Illuminate\Support\Carbon::parse($syncedAt)->toIso8601String();
            } catch (Throwable) {
                $syncedAtIso = (string) $syncedAt;
            }
        }

        $accountMeta = is_array($account?->meta) ? $account->meta : [];

        return [
            'item_id' => (int) $item->id,
            'instagram_product_id' => $item->getAttribute('instagram_product_id'),
            'instagram_source_url' => Schema::hasColumn('items', 'instagram_source_url')
                ? $item->getAttribute('instagram_source_url')
                : null,
            'publish_to_instagram' => Schema::hasColumn('items', 'publish_to_instagram')
                ? (bool) $item->getAttribute('publish_to_instagram')
                : false,
            'instagram_synced_at' => $syncedAtIso,
            'is_synced' => ! empty($item->getAttribute('instagram_product_id')),
            'account_connected' => (bool) ($account && $account->is_active),
            'account_last_synced_at' => $accountMeta['last_synced_at'] ?? null,
        ];
    }
}
