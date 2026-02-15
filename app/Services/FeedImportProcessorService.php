<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InstagramImport;
use App\Models\Item;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class FeedImportProcessorService
{
    private const DEFAULT_LATITUDE = 43.8563;
    private const DEFAULT_LONGITUDE = 18.4131;
    private const DEFAULT_COUNTRY = 'Bosna i Hercegovina';
    private const DEFAULT_CITY = 'Online';
    private const DEFAULT_STATE = 'Online';

    private static ?float $cachedLatitude = null;
    private static ?float $cachedLongitude = null;
    private static ?int $cachedFallbackCategoryId = null;

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

        $firstError = collect($results)->first(static fn (array $row) => ($row['ok'] ?? false) !== true);
        $firstErrorMessage = is_array($firstError) ? trim((string) ($firstError['message'] ?? '')) : '';

        $message = match ($status) {
            'completed' => "Feed import završen. Kreirano/ažurirano oglasa: {$imported}.",
            'partial' => "Feed import djelimično završen. Kreirano/ažurirano: {$imported}, greške: {$failed}.",
            default => "Feed import nije uspio. Greške: {$failed}.",
        };

        if ($firstErrorMessage !== '') {
            $message .= ' Detalj: ' . $firstErrorMessage;
        }

        return $this->finalizeImport($import, $imported, $failed, $results, $status, $message, $requested, $urls);
    }

    private function processSingleUrl(InstagramImport $import, string $url): array
    {
        $result = [
            'url' => $url,
            'ok' => false,
            'http_status' => null,
            'imported_count' => 0,
            'item_ids' => [],
            'message' => null,
        ];

        try {
            $response = $this->fetchUrl($url);
            $result['http_status'] = $response->status();

            if ($response->status() === 404 || $response->serverError()) {
                $result['message'] = 'URL nije dostupan (HTTP ' . $response->status() . ').';
                return $result;
            }

            $entries = [];

            if ($response->clientError()) {
                // URL je validan, ali server ograničava scraping/API poziv.
                $entries[] = $this->buildFallbackEntryFromUrl($url);
            } else {
                $entries = $this->extractEntriesFromResponse(
                    $url,
                    (string) $response->body(),
                    (string) $response->header('Content-Type', ''),
                    (string) ($import->feed_format ?? 'api')
                );
            }

            if (count($entries) === 0) {
                $entries[] = $this->buildFallbackEntryFromUrl($url);
            }

            $itemIds = [];
            foreach ($entries as $index => $entry) {
                $entryUrl = $this->toValidUrl($entry['source_url'] ?? null);
                if (! $entryUrl) {
                    $entryKey = trim((string) ($entry['title'] ?? '')) . '|' . trim((string) ($entry['price'] ?? ''));
                    $entryHash = substr(sha1($entryKey ?: ('row-' . $index)), 0, 10);
                    $entryUrl = rtrim($url, '/') . '#feed-' . ($index + 1) . '-' . $entryHash;
                }
                $item = $this->upsertItemFromEntry($import, $entry, $entryUrl);

                if (! $item) {
                    continue;
                }

                $itemIds[] = (int) $item->id;
            }

            $itemIds = array_values(array_unique($itemIds));
            if (count($itemIds) === 0) {
                $result['message'] = 'Feed je obrađen, ali nijedan oglas nije kreiran (nedovoljno podataka).';
                return $result;
            }

            $result['ok'] = true;
            $result['imported_count'] = count($itemIds);
            $result['item_ids'] = $itemIds;
            $result['message'] = $response->clientError()
                ? 'Oglas je kreiran iz fallback podataka jer udaljeni server ograničava dohvat.'
                : 'Uspješno obrađeno i kreirani/ažurirani oglasi.';

            return $result;
        } catch (Throwable $th) {
            $result['message'] = 'Greška obrade: ' . Str::limit((string) $th->getMessage(), 180, '...');
            return $result;
        }
    }

    private function upsertItemFromEntry(InstagramImport $import, array $entry, string $sourceUrl): ?Item
    {
        $user = User::find($import->user_id);
        if (! $user) {
            return null;
        }

        $categoryId = $this->resolveCategoryId($import);
        if (! $categoryId) {
            return null;
        }

        $title = trim((string) ($entry['title'] ?? ''));
        if ($title === '') {
            $title = $this->titleFromUrl($sourceUrl);
        }

        $description = trim((string) ($entry['description'] ?? ''));
        if ($description === '') {
            $description = 'Automatski uvezeno sa feed URL-a: ' . $sourceUrl;
        }

        $price = $this->normalizePrice($entry['price'] ?? null);
        if ($price === null || $price < 0) {
            $price = 0.0;
        }

        $image = $this->resolveImageValue($entry['image'] ?? null);
        $contact = trim((string) ($user->mobile ?: $user->email ?: 'N/A'));

        $address = $sourceUrl;
        $lat = $this->resolveDefaultLatitude();
        $lng = $this->resolveDefaultLongitude();
        $slugSeed = Str::limit($title, 120, '');

        $existing = null;
        if (Schema::hasColumn('items', 'instagram_source_url')) {
            $existing = Item::where('user_id', $user->id)
                ->where('instagram_source_url', $sourceUrl)
                ->orderByDesc('id')
                ->first();
        }

        if ($existing) {
            $existing->name = $title;
            $existing->description = $description;
            $existing->price = $price;
            $existing->image = $image ?: $existing->getRawOriginal('image');
            $existing->category_id = $categoryId;
            $existing->contact = $contact;
            $existing->address = $address;
            $existing->latitude = $lat;
            $existing->longitude = $lng;
            $existing->country = self::DEFAULT_COUNTRY;
            $existing->state = self::DEFAULT_STATE;
            $existing->city = self::DEFAULT_CITY;
            $existing->status = 'approved';
            $existing->show_only_to_premium = false;
            $existing->all_category_ids = (string) $categoryId;

            if (Schema::hasColumn('items', 'price_per_unit') && empty($existing->price_per_unit)) {
                $existing->price_per_unit = $price;
            }
            if (Schema::hasColumn('items', 'minimum_order_quantity') && empty($existing->minimum_order_quantity)) {
                $existing->minimum_order_quantity = 1;
            }
            if (Schema::hasColumn('items', 'publish_to_instagram')) {
                $existing->publish_to_instagram = false;
            }
            if (Schema::hasColumn('items', 'instagram_source_url')) {
                $existing->instagram_source_url = $sourceUrl;
            }
            if (Schema::hasColumn('items', 'instagram_product_id') && empty($existing->instagram_product_id)) {
                $existing->instagram_product_id = 'ig_' . Str::lower(Str::substr(sha1($sourceUrl . '|' . $existing->id), 0, 16));
            }
            if (Schema::hasColumn('items', 'instagram_synced_at')) {
                $existing->instagram_synced_at = now();
            }

            $existing->save();
            return $existing;
        }

        $slug = Str::slug($slugSeed);
        if ($slug === '') {
            $slug = HelperService::generateRandomSlug();
        }
        $slug = HelperService::generateUniqueSlug(new Item, $slug);

        $data = [
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'name' => $title,
            'slug' => $slug,
            'description' => $description,
            'price' => $price,
            'image' => $image,
            'latitude' => $lat,
            'longitude' => $lng,
            'address' => $address,
            'contact' => $contact,
            'show_only_to_premium' => false,
            'status' => 'approved',
            'all_category_ids' => (string) $categoryId,
            'country' => self::DEFAULT_COUNTRY,
            'state' => self::DEFAULT_STATE,
            'city' => self::DEFAULT_CITY,
        ];

        if (Schema::hasColumn('items', 'publish_to_instagram')) {
            $data['publish_to_instagram'] = false;
        }
        if (Schema::hasColumn('items', 'instagram_source_url')) {
            $data['instagram_source_url'] = $sourceUrl;
        }
        if (Schema::hasColumn('items', 'price_per_unit')) {
            $data['price_per_unit'] = $price;
        }
        if (Schema::hasColumn('items', 'minimum_order_quantity')) {
            $data['minimum_order_quantity'] = 1;
        }
        if (Schema::hasColumn('items', 'inventory_count')) {
            $data['inventory_count'] = 1;
        }

        $item = Item::create($data);

        if (Schema::hasColumn('items', 'instagram_product_id') && empty($item->instagram_product_id)) {
            $item->instagram_product_id = 'ig_' . Str::lower(Str::substr(sha1($sourceUrl . '|' . $item->id), 0, 16));
        }
        if (Schema::hasColumn('items', 'instagram_synced_at')) {
            $item->instagram_synced_at = now();
        }
        $item->save();

        return $item;
    }

    private function extractEntriesFromResponse(string $url, string $body, string $contentType, string $format): array
    {
        $normalizedType = Str::lower($contentType);
        $normalizedFormat = Str::lower(trim($format));

        if (str_contains($normalizedType, 'json') || $this->looksLikeJson($body) || $normalizedFormat === 'api') {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->entriesFromJsonPayload($decoded, $url);
            }
        }

        if (str_contains($normalizedType, 'xml') || $this->looksLikeXml($body) || $normalizedFormat === 'xml') {
            return $this->entriesFromXmlPayload($body, $url);
        }

        return [$this->entryFromHtml($body, $url)];
    }

    private function entriesFromJsonPayload($payload, string $fallbackUrl): array
    {
        $nodes = $this->extractProductNodes($payload);
        if (count($nodes) === 0) {
            return [];
        }

        $entries = [];
        foreach ($nodes as $node) {
            if (is_string($node)) {
                $url = $this->toValidUrl($node);
                if ($url) {
                    $entries[] = $this->buildFallbackEntryFromUrl($url);
                }
                continue;
            }

            if (! is_array($node)) {
                continue;
            }

            $entries[] = [
                'title' => $this->firstNonEmpty($node, ['title', 'name', 'product_name', 'label', 'headline']),
                'description' => $this->firstNonEmpty($node, ['description', 'desc', 'details', 'summary', 'body']),
                'price' => $this->firstNonEmpty($node, ['price', 'amount', 'regular_price', 'sale_price', 'unit_price']),
                'image' => $this->extractImageFromNode($node),
                'source_url' => $this->firstValidUrl($node, ['url', 'link', 'product_url', 'permalink', 'source_url']) ?: $fallbackUrl,
            ];
        }

        return array_values(array_filter($entries, static fn (array $entry) => ! empty($entry['source_url'])));
    }

    private function entriesFromXmlPayload(string $content, string $fallbackUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [$this->buildFallbackEntryFromUrl($fallbackUrl)];
        }

        $entries = [];
        $productNodes = $xml->xpath('//*[local-name()="item" or local-name()="product" or local-name()="entry"]') ?: [];

        foreach ($productNodes as $node) {
            $entry = [
                'title' => $this->xmlNodeValue($node, ['title', 'name']),
                'description' => $this->xmlNodeValue($node, ['description', 'summary']),
                'price' => $this->xmlNodeValue($node, ['price', 'amount']),
                'image' => $this->xmlNodeValue($node, ['image', 'image_link', 'thumbnail']),
                'source_url' => $this->xmlNodeValue($node, ['url', 'link', 'product_url', 'loc']) ?: $fallbackUrl,
            ];
            $entries[] = $entry;
        }

        if (count($entries) === 0) {
            $urls = $this->extractUrlsFromXml($content);
            foreach ($urls as $url) {
                $entries[] = $this->buildFallbackEntryFromUrl($url);
            }
        }

        if (count($entries) === 0) {
            $entries[] = $this->buildFallbackEntryFromUrl($fallbackUrl);
        }

        return $entries;
    }

    private function entryFromHtml(string $html, string $sourceUrl): array
    {
        $title = null;
        $description = null;
        $price = null;
        $image = null;

        try {
            $previous = libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $payload = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')
                : $html;
            $dom->loadHTML($payload);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $xpath = new \DOMXPath($dom);

            $title = $this->findMetaContent($xpath, [
                'og:title',
                'twitter:title',
                'title',
            ]);

            if (! $title) {
                $titleNode = $xpath->query('//title');
                if ($titleNode && $titleNode->length > 0) {
                    $title = trim((string) $titleNode->item(0)?->textContent);
                }
            }

            $description = $this->findMetaContent($xpath, [
                'og:description',
                'description',
                'twitter:description',
            ]);

            $image = $this->findMetaContent($xpath, [
                'og:image',
                'twitter:image',
                'image',
            ]);

            $priceRaw = $this->findMetaContent($xpath, [
                'product:price:amount',
                'og:price:amount',
                'price',
            ]);

            if ($priceRaw) {
                $price = $priceRaw;
            }
        } catch (Throwable) {
            // fallback is handled below
        }

        return [
            'title' => $title ?: $this->titleFromUrl($sourceUrl),
            'description' => $description ?: ('Automatski uvezeno sa: ' . $sourceUrl),
            'price' => $price,
            'image' => $image,
            'source_url' => $sourceUrl,
        ];
    }

    private function findMetaContent(\DOMXPath $xpath, array $keys): ?string
    {
        foreach ($keys as $key) {
            $query = sprintf('//meta[@property="%1$s"]/@content | //meta[@name="%1$s"]/@content', $key);
            $nodes = $xpath->query($query);
            if (! $nodes || $nodes->length === 0) {
                continue;
            }

            $value = trim((string) $nodes->item(0)?->nodeValue);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function fetchUrl(string $url)
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (compatible; LMXFeedImportBot/1.0; +https://lmx.ba)',
            'Accept' => 'application/json,text/xml,application/xml,text/html;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'bs-BA,bs;q=0.9,en;q=0.8',
        ];

        try {
            return Http::retry(2, 350, null, false)
                ->timeout(20)
                ->connectTimeout(8)
                ->withHeaders($headers)
                ->withOptions([
                    'allow_redirects' => ['max' => 8, 'strict' => false],
                ])
                ->get($url);
        } catch (Throwable) {
            return Http::retry(1, 250, null, false)
                ->timeout(20)
                ->connectTimeout(8)
                ->withHeaders($headers)
                ->withOptions([
                    'allow_redirects' => ['max' => 8, 'strict' => false],
                    'verify' => false,
                ])
                ->get($url);
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
            ->map(fn ($url) => $this->toValidUrl($url))
            ->filter()
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

    private function extractProductNodes($payload): array
    {
        if (is_null($payload)) {
            return [];
        }

        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        if (is_array($payload)) {
            foreach (['data', 'items', 'products', 'results', 'list'] as $key) {
                if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                    return array_is_list($payload[$key]) ? $payload[$key] : [$payload[$key]];
                }
            }

            return [$payload];
        }

        return [];
    }

    private function firstNonEmpty(array $node, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $node)) {
                continue;
            }

            $value = $node[$key];
            if (is_scalar($value)) {
                $string = trim((string) $value);
                if ($string !== '') {
                    return $string;
                }
            }
        }

        return null;
    }

    private function firstValidUrl(array $node, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $node)) {
                continue;
            }

            $url = $this->toValidUrl($node[$key]);
            if ($url) {
                return $url;
            }
        }

        return null;
    }

    private function extractImageFromNode(array $node): ?string
    {
        $direct = $this->firstValidUrl($node, ['image', 'image_url', 'thumbnail', 'photo', 'picture', 'main_image']);
        if ($direct) {
            return $direct;
        }

        foreach (['images', 'gallery'] as $key) {
            if (! array_key_exists($key, $node) || ! is_array($node[$key])) {
                continue;
            }

            foreach ($node[$key] as $candidate) {
                $url = $this->toValidUrl($candidate);
                if ($url) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function toValidUrl($value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function titleFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $lastSegment = $path !== '' ? basename($path) : 'uvoz-oglas';
        $human = trim(str_replace(['-', '_'], ' ', $lastSegment));

        return $human !== '' ? Str::title($human) : 'Uvezeni oglas';
    }

    private function buildFallbackEntryFromUrl(string $url): array
    {
        return [
            'title' => $this->titleFromUrl($url),
            'description' => 'Automatski uvezeno sa feed URL-a: ' . $url,
            'price' => null,
            'image' => null,
            'source_url' => $url,
        ];
    }

    private function normalizePrice($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $clean = preg_replace('/[^\d,\.\-]/', '', $raw);
        if (! $clean) {
            return null;
        }

        $hasComma = str_contains($clean, ',');
        $hasDot = str_contains($clean, '.');

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($clean, ',');
            $lastDot = strrpos($clean, '.');
            if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } else {
                $clean = str_replace(',', '', $clean);
            }
        } elseif ($hasComma) {
            $parts = explode(',', $clean);
            if (count($parts) > 1 && strlen(end($parts)) <= 2) {
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } else {
                $clean = str_replace(',', '', $clean);
            }
        } elseif ($hasDot && substr_count($clean, '.') > 1) {
            $last = strrpos($clean, '.');
            if ($last !== false) {
                $intPart = str_replace('.', '', substr($clean, 0, $last));
                $decimalPart = substr($clean, $last + 1);
                $clean = $intPart . '.' . $decimalPart;
            }
        }

        if (! is_numeric($clean)) {
            return null;
        }

        return max(0, (float) $clean);
    }

    private function resolveImageValue($candidate): string
    {
        $url = $this->toValidUrl($candidate);
        if ($url) {
            return Str::limit($url, 240, '');
        }

        $configured = Setting::where('name', 'placeholder_image')->value('value');
        if (is_string($configured) && trim($configured) !== '') {
            $configured = trim($configured);
            if (filter_var($configured, FILTER_VALIDATE_URL)) {
                return Str::limit($configured, 240, '');
            }

            if (str_starts_with($configured, '/')) {
                $base = rtrim((string) config('app.url', 'https://admin.lmx.ba'), '/');
                return Str::limit($base . $configured, 240, '');
            }
        }

        $base = rtrim((string) config('app.url', 'https://admin.lmx.ba'), '/');
        return Str::limit($base . '/assets/img_placeholder.jpeg', 240, '');
    }

    private function resolveCategoryId(InstagramImport $import): ?int
    {
        if (! empty($import->category_id)) {
            $exists = Category::where('id', $import->category_id)->exists();
            if ($exists) {
                return (int) $import->category_id;
            }
        }

        if (self::$cachedFallbackCategoryId !== null) {
            return self::$cachedFallbackCategoryId;
        }

        // Prefer leaf categories for better item compatibility.
        $leafId = Category::whereNotNull('parent_category_id')->orderBy('id')->value('id');
        if (! $leafId) {
            $leafId = Category::orderBy('id')->value('id');
        }

        self::$cachedFallbackCategoryId = $leafId ? (int) $leafId : null;
        return self::$cachedFallbackCategoryId;
    }

    private function resolveDefaultLatitude(): float
    {
        if (self::$cachedLatitude !== null) {
            return self::$cachedLatitude;
        }

        $fromSettings = Setting::where('name', 'default_latitude')->value('value');
        self::$cachedLatitude = is_numeric($fromSettings) ? (float) $fromSettings : self::DEFAULT_LATITUDE;

        return self::$cachedLatitude;
    }

    private function resolveDefaultLongitude(): float
    {
        if (self::$cachedLongitude !== null) {
            return self::$cachedLongitude;
        }

        $fromSettings = Setting::where('name', 'default_longitude')->value('value');
        self::$cachedLongitude = is_numeric($fromSettings) ? (float) $fromSettings : self::DEFAULT_LONGITUDE;

        return self::$cachedLongitude;
    }

    private function xmlNodeValue(\SimpleXMLElement $node, array $names): ?string
    {
        foreach ($names as $name) {
            $result = $node->xpath('.//*[local-name()="' . $name . '"]');
            if (! $result || count($result) === 0) {
                continue;
            }

            $value = trim((string) $result[0]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
