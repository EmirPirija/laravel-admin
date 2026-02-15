<?php

namespace App\Services;

use App\Models\InstagramImport;
use App\Models\Item;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class FeedImportProcessorService
{
    /**
     * Process queued/created feed import and persist summary status.
     */
    public function processImport(InstagramImport $import, ?array $fallbackUrls = null): InstagramImport
    {
        if ($this->isTerminalStatus($import->status ?? null)) {
            return $import->fresh() ?? $import;
        }

        $urls = $this->resolveImportUrls($import, $fallbackUrls);
        if (count($urls) === 0) {
            return $this->finalizeImport(
                $import,
                0,
                max(1, (int) ($import->products_requested ?? 1)),
                [],
                'failed',
                'Feed import nije uspio: nije pronađen nijedan važeći URL za obradu.'
            );
        }

        $this->updateImport($import, [
            'status' => 'processing',
            'message' => 'Feed import je u toku obrade.',
        ]);

        $imported = 0;
        $failed = 0;
        $results = [];

        foreach ($urls as $url) {
            $result = $this->processSingleUrl($import, $url);
            $results[] = $result;

            if (($result['ok'] ?? false) === true) {
                $imported += max(0, (int) ($result['imported_count'] ?? 0));
                continue;
            }

            $failed++;
        }

        $requested = max(count($urls), (int) ($import->products_requested ?? 0));

        $status = 'completed';
        if ($imported <= 0 && $failed > 0) {
            $status = 'failed';
        } elseif ($failed > 0) {
            $status = 'partial';
        }

        $message = match ($status) {
            'completed' => "Feed import završen. Uvezeno stavki: {$imported}.",
            'partial' => "Feed import djelimično završen. Uvezeno: {$imported}, greške: {$failed}.",
            default => "Feed import nije uspio. Greške: {$failed}.",
        };

        return $this->finalizeImport($import, $imported, $failed, $results, $status, $message, $requested, $urls);
    }

    private function processSingleUrl(InstagramImport $import, string $url): array
    {
        $result = [
            'url' => $url,
            'ok' => false,
            'http_status' => null,
            'imported_count' => 0,
            'item_id' => null,
            'message' => null,
        ];

        try {
            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->withHeaders([
                    'User-Agent' => 'LMXFeedImportBot/1.0',
                    'Accept' => 'application/json,text/xml,application/xml,text/html;q=0.9,*/*;q=0.8',
                ])
                ->get($url);

            $result['http_status'] = $response->status();

            if (! $response->successful()) {
                $result['message'] = 'URL nije dostupan (HTTP ' . $response->status() . ').';
                return $result;
            }

            $body = (string) $response->body();
            $contentType = Str::lower((string) $response->header('Content-Type', ''));
            $importedCount = 1;

            if (str_contains($contentType, 'json') || $this->looksLikeJson($body)) {
                $payload = $response->json();
                $importedCount = max(1, $this->countProductsFromPayload($payload));
            } elseif (str_contains($contentType, 'xml') || $this->looksLikeXml($body) || ($import->feed_format ?? null) === 'xml') {
                $xmlUrls = $this->extractUrlsFromXml($body);
                $importedCount = max(1, count($xmlUrls));
            }

            $syncedItemId = $this->syncExistingItemBySourceUrl($import, $url);

            $result['ok'] = true;
            $result['imported_count'] = $importedCount;
            $result['item_id'] = $syncedItemId;
            $result['message'] = 'Uspješno obrađeno.';

            return $result;
        } catch (Throwable $th) {
            $result['message'] = 'Greška obrade: ' . Str::limit((string) $th->getMessage(), 180, '...');
            return $result;
        }
    }

    private function resolveImportUrls(InstagramImport $import, ?array $fallbackUrls = null): array
    {
        $urls = [];

        if (is_array($import->source_urls_json)) {
            $urls = array_merge($urls, $import->source_urls_json);
        }

        if (! empty($import->source_url)) {
            $urls[] = $import->source_url;
        }

        if (is_array($fallbackUrls)) {
            $urls = array_merge($urls, $fallbackUrls);
        }

        $urls = collect($urls)
            ->map(static fn ($url) => trim((string) $url))
            ->filter(static fn ($url) => filter_var($url, FILTER_VALIDATE_URL))
            ->unique()
            ->values()
            ->all();

        return $urls;
    }

    private function finalizeImport(
        InstagramImport $import,
        int $imported,
        int $failed,
        array $results,
        string $status,
        string $message,
        ?int $requested = null,
        ?array $urls = null
    ): InstagramImport {
        $meta = is_array($import->meta) ? $import->meta : [];
        $meta['summary'] = [
            'status' => $status,
            'requested' => $requested ?? (int) ($import->products_requested ?? 0),
            'imported' => $imported,
            'failed' => $failed,
            'processed_at' => now()->toIso8601String(),
        ];
        $meta['results'] = $results;

        $payload = [
            'products_requested' => $requested ?? (int) ($import->products_requested ?? 0),
            'products_imported' => max(0, $imported),
            'products_failed' => max(0, $failed),
            'status' => $status,
            'message' => $message,
            'meta' => $meta,
            'processed_at' => now(),
        ];

        if (is_array($urls) && count($urls) > 0) {
            $payload['source_url'] = $payload['source_url'] ?? ($import->source_url ?: $urls[0]);
            $payload['source_urls_json'] = $urls;
        }

        $this->updateImport($import, $payload);

        return $import->fresh() ?? $import;
    }

    private function updateImport(InstagramImport $import, array $payload): void
    {
        $table = $import->getTable();

        $filtered = [];
        foreach ($payload as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $filtered[$column] = $value;
            }
        }

        if (count($filtered) === 0) {
            return;
        }

        $import->fill($filtered);
        $import->save();
    }

    private function countProductsFromPayload($payload): int
    {
        if (is_null($payload)) {
            return 0;
        }

        if (is_array($payload)) {
            if ($payload === []) {
                return 0;
            }

            if (array_is_list($payload)) {
                return count($payload);
            }

            foreach (['data', 'items', 'products', 'results', 'list'] as $key) {
                if (array_key_exists($key, $payload)) {
                    return $this->countProductsFromPayload($payload[$key]);
                }
            }

            return 1;
        }

        return 1;
    }

    private function syncExistingItemBySourceUrl(InstagramImport $import, string $url): ?int
    {
        if (! Schema::hasColumn('items', 'instagram_source_url')) {
            return null;
        }

        $item = Item::where('user_id', $import->user_id)
            ->where('instagram_source_url', $url)
            ->orderByDesc('id')
            ->first();

        if (! $item) {
            return null;
        }

        $shouldSave = false;

        if (Schema::hasColumn('items', 'instagram_product_id') && empty($item->instagram_product_id)) {
            $item->instagram_product_id = 'ig_' . Str::lower(Str::substr(sha1($url . '|' . $item->id), 0, 16));
            $shouldSave = true;
        }

        if (Schema::hasColumn('items', 'instagram_synced_at')) {
            $item->instagram_synced_at = now();
            $shouldSave = true;
        }

        if ($shouldSave) {
            $item->save();
        }

        return (int) $item->id;
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

    private function looksLikeJson(string $content): bool
    {
        $content = trim($content);
        if ($content === '' || (! str_starts_with($content, '{') && ! str_starts_with($content, '['))) {
            return false;
        }

        json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function looksLikeXml(string $content): bool
    {
        $content = ltrim($content);
        return str_starts_with($content, '<');
    }

    private function isTerminalStatus(?string $status): bool
    {
        $normalized = Str::lower(trim((string) $status));
        return in_array($normalized, ['completed', 'partial', 'failed'], true);
    }
}
