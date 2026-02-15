<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InstagramImport;
use App\Models\Item;
use App\Models\ItemImages;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
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
            $body = (string) $response->body();

            if ($response->status() === 404 || $response->serverError()) {
                $result['message'] = 'URL nije dostupan (HTTP ' . $response->status() . ').';
                return $result;
            }

            if ($this->looksLikeAccessProtectionPage($body)) {
                $result['message'] = 'Udaljeni sajt je blokirao automatski pristup (Cloudflare/anti-bot). Koristi API/CSV/XML feed ili omogući pristup serveru admin.lmx.ba.';
                return $result;
            }

            $entries = [];

            if ($response->clientError()) {
                // URL je validan, ali server ograničava scraping/API poziv.
                $entries[] = $this->buildFallbackEntryFromUrl($url);
            } else {
                $entries = $this->extractEntriesFromResponse(
                    $url,
                    $body,
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

        $specs = $this->normalizeSpecs($entry['specs'] ?? null);
        if (count($specs) > 0) {
            $description .= "\n\nSpecifikacije:\n- " . implode("\n- ", $specs);
        }

        $price = $this->normalizePrice($entry['price'] ?? null);
        $oldPrice = $this->normalizePrice($entry['old_price'] ?? null);

        if (($price === null || $price <= 0) && $oldPrice !== null && $oldPrice > 0) {
            $price = $oldPrice;
            $oldPrice = null;
        }

        if ($price === null || $price < 0) {
            $price = 0.0;
        }

        $galleryCandidates = $this->normalizeImageUrls($entry['images'] ?? null);
        $image = $this->resolveImageValue($entry['image'] ?? ($galleryCandidates[0] ?? null));
        $videoLink = $this->toValidUrl($entry['video'] ?? ($entry['video_url'] ?? ($entry['video_link'] ?? null)));

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
            if (Schema::hasColumn('items', 'video_link') && $videoLink) {
                $existing->video_link = $videoLink;
            }
            if (Schema::hasColumn('items', 'instagram_source_url')) {
                $existing->instagram_source_url = $sourceUrl;
            }
            if (Schema::hasColumn('items', 'is_on_sale')) {
                $existing->is_on_sale = $oldPrice !== null && $oldPrice > $price;
            }
            if (Schema::hasColumn('items', 'old_price')) {
                $existing->old_price = ($oldPrice !== null && $oldPrice > $price) ? $oldPrice : null;
            }
            if (Schema::hasColumn('items', 'instagram_product_id') && empty($existing->instagram_product_id)) {
                $existing->instagram_product_id = 'ig_' . Str::lower(Str::substr(sha1($sourceUrl . '|' . $existing->id), 0, 16));
            }
            if (Schema::hasColumn('items', 'instagram_synced_at')) {
                $existing->instagram_synced_at = now();
            }

            $existing->save();
            $this->syncGalleryImages($existing, $galleryCandidates);
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
        if (Schema::hasColumn('items', 'video_link') && $videoLink) {
            $data['video_link'] = $videoLink;
        }
        if (Schema::hasColumn('items', 'instagram_source_url')) {
            $data['instagram_source_url'] = $sourceUrl;
        }
        if (Schema::hasColumn('items', 'is_on_sale')) {
            $data['is_on_sale'] = $oldPrice !== null && $oldPrice > $price;
        }
        if (Schema::hasColumn('items', 'old_price')) {
            $data['old_price'] = ($oldPrice !== null && $oldPrice > $price) ? $oldPrice : null;
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

        $this->syncGalleryImages($item, $galleryCandidates);

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

        return $this->entriesFromHtml($body, $url);
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

            $entry = [
                'title' => $this->firstNonEmpty($node, ['title', 'name', 'product_name', 'label', 'headline']),
                'description' => $this->firstNonEmpty($node, ['description', 'desc', 'details', 'summary', 'body']),
                'price' => $this->firstNonEmpty($node, ['price', 'amount', 'regular_price', 'sale_price', 'unit_price']),
                'old_price' => $this->firstNonEmpty($node, ['old_price', 'list_price', 'compare_at_price', 'original_price', 'regular_price']),
                'image' => $this->extractImageFromNode($node),
                'images' => $node['images'] ?? $node['gallery'] ?? null,
                'video' => $this->firstNonEmpty($node, ['video', 'video_url', 'video_link', 'trailer']),
                'specs' => $node['attributes'] ?? $node['specs'] ?? $node['properties'] ?? null,
                'source_url' => $this->firstValidUrl($node, ['url', 'link', 'product_url', 'permalink', 'source_url']),
            ];

            $entry = $this->mergeEntryWithKeywordFields($entry, $this->extractKeywordFieldsFromNode($node));
            $entries[] = $entry;
        }

        return array_values($entries);
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
                'old_price' => $this->xmlNodeValue($node, ['old_price', 'regular_price', 'list_price']),
                'image' => $this->xmlNodeValue($node, ['image', 'image_link', 'thumbnail']),
                'images' => $this->xmlNodeValues($node, ['image', 'image_link', 'thumbnail', 'gallery_image']),
                'video' => $this->xmlNodeValue($node, ['video', 'video_url', 'video_link']),
                'specs' => $this->xmlNodeValues($node, ['specification', 'attribute', 'feature']),
                'source_url' => $this->xmlNodeValue($node, ['url', 'link', 'product_url', 'loc']),
            ];
            $entry = $this->mergeEntryWithKeywordFields(
                $entry,
                $this->extractKeywordFieldsFromText((string) $node->asXML())
            );
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

    private function entriesFromHtml(string $html, string $sourceUrl): array
    {
        $jsonLdEntries = $this->entriesFromJsonLdHtml($html, $sourceUrl);
        if (count($jsonLdEntries) > 0) {
            $globalKeywordFields = $this->extractKeywordFieldsFromHtml($html);
            $jsonLdEntries = array_map(function (array $entry) use ($globalKeywordFields) {
                return $this->mergeEntryWithKeywordFields($entry, $globalKeywordFields);
            }, $jsonLdEntries);
            return $jsonLdEntries;
        }

        return [$this->entryFromHtml($html, $sourceUrl)];
    }

    private function entriesFromJsonLdHtml(string $html, string $sourceUrl): array
    {
        $entries = [];

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
            $scripts = $xpath->query('//script[@type="application/ld+json"]');
            if (! $scripts) {
                return [];
            }

            foreach ($scripts as $scriptNode) {
                $rawJson = trim((string) $scriptNode->textContent);
                if ($rawJson === '') {
                    continue;
                }

                $decoded = json_decode($rawJson, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                $productNodes = [];
                $this->collectJsonLdProductNodes($decoded, $productNodes);

                foreach ($productNodes as $productNode) {
                    $entry = $this->buildEntryFromJsonLdProductNode($productNode, $sourceUrl);
                    if (! empty($entry['title']) || ! empty($entry['price']) || ! empty($entry['image'])) {
                        $entries[] = $entry;
                    }
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $entries;
    }

    private function collectJsonLdProductNodes($node, array &$products): void
    {
        if (! is_array($node)) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $child) {
                $this->collectJsonLdProductNodes($child, $products);
            }
            return;
        }

        $type = $node['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $candidateType) {
            if (is_string($candidateType) && stripos($candidateType, 'product') !== false) {
                $products[] = $node;
                break;
            }
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectJsonLdProductNodes($child, $products);
            }
        }
    }

    private function buildEntryFromJsonLdProductNode(array $node, string $sourceUrl): array
    {
        $offerData = is_array($node['offers'] ?? null) ? $node['offers'] : [];

        $price = $this->recursiveFindScalar($offerData, ['price', 'lowPrice', 'highPrice']);
        if ($price === null) {
            $price = $this->recursiveFindScalar($node, ['price']);
        }

        $oldPrice = $this->recursiveFindScalar($offerData, [
            'priceBeforeDiscount',
            'listPrice',
            'regularPrice',
            'msrp',
            'highPrice',
        ]);

        $imageList = $this->normalizeImageUrls($node['image'] ?? ($node['images'] ?? null));
        $videoUrl = $this->recursiveFindScalar($node, ['contentUrl', 'embedUrl', 'video', 'videoUrl', 'video_url']);

        $specs = [];
        $brand = $this->recursiveFindScalar($node['brand'] ?? [], ['name']) ?: $this->recursiveFindScalar($node, ['brand']);
        if ($brand) {
            $specs[] = 'Brend: ' . $brand;
        }
        $sku = $this->recursiveFindScalar($node, ['sku', 'mpn', 'gtin', 'gtin13', 'gtin12']);
        if ($sku) {
            $specs[] = 'Šifra: ' . $sku;
        }

        if (is_array($node['additionalProperty'] ?? null)) {
            foreach ($node['additionalProperty'] as $property) {
                if (! is_array($property)) {
                    continue;
                }
                $name = trim((string) ($property['name'] ?? ''));
                $value = trim((string) ($property['value'] ?? ($property['valueReference'] ?? '')));
                if ($name !== '' && $value !== '') {
                    $specs[] = $name . ': ' . $value;
                }
            }
        }

        return [
            'title' => $this->firstNonEmpty($node, ['name', 'title', 'headline']) ?: $this->titleFromUrl($sourceUrl),
            'description' => $this->firstNonEmpty($node, ['description', 'summary']) ?: ('Automatski uvezeno sa: ' . $sourceUrl),
            'price' => $price,
            'old_price' => $oldPrice,
            'image' => $imageList[0] ?? null,
            'images' => $imageList,
            'video' => $this->toValidUrl($videoUrl),
            'specs' => $specs,
            'source_url' => $this->firstValidUrl($node, ['url']),
        ];
    }

    private function recursiveFindScalar($node, array $keys): ?string
    {
        if (! is_array($node)) {
            if (is_scalar($node)) {
                $value = trim((string) $node);
                return $value !== '' ? $value : null;
            }
            return null;
        }

        if (! array_is_list($node)) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $node) && is_scalar($node[$key])) {
                    $value = trim((string) $node[$key]);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        foreach ($node as $child) {
            $value = $this->recursiveFindScalar($child, $keys);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function entryFromHtml(string $html, string $sourceUrl): array
    {
        $title = null;
        $description = null;
        $price = null;
        $oldPrice = null;
        $image = null;
        $images = [];
        $video = null;
        $specs = [];

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

            if (! $description) {
                $firstParagraph = $xpath->query('//p[string-length(normalize-space(.)) > 40]');
                if ($firstParagraph && $firstParagraph->length > 0) {
                    $description = trim((string) $firstParagraph->item(0)?->textContent);
                }
            }

            $images = $this->findMetaContents($xpath, ['og:image', 'twitter:image', 'image']);
            $image = $images[0] ?? null;

            if (! $image) {
                $imgNodes = $xpath->query('//img[@src]');
                if ($imgNodes) {
                    foreach ($imgNodes as $imgNode) {
                        $src = $this->toValidUrl($imgNode->attributes?->getNamedItem('src')?->nodeValue);
                        if ($src) {
                            $images[] = $src;
                        }
                    }
                    $images = array_values(array_unique($images));
                    $image = $images[0] ?? null;
                }
            }

            $video = $this->findMetaContent($xpath, ['og:video', 'og:video:url', 'twitter:player']);

            $priceRaw = $this->findMetaContent($xpath, [
                'product:price:amount',
                'og:price:amount',
                'price',
            ]);
            $oldPrice = $this->findMetaContent($xpath, [
                'product:price:regular_amount',
                'product:price:original_amount',
                'og:price:standard_amount',
            ]);

            if (! $priceRaw) {
                $priceRaw = $this->extractVisiblePriceFromHtml($html);
            }

            if ($priceRaw) {
                $price = $priceRaw;
            }

            $specs = $this->extractSpecsFromDom($xpath);
        } catch (Throwable) {
            // fallback is handled below
        }

        $entry = [
            'title' => $title ?: $this->titleFromUrl($sourceUrl),
            'description' => $description ?: ('Automatski uvezeno sa: ' . $sourceUrl),
            'price' => $price,
            'old_price' => $oldPrice,
            'image' => $image,
            'images' => $images,
            'video' => $this->toValidUrl($video),
            'specs' => $specs,
            'source_url' => $sourceUrl,
        ];

        return $this->mergeEntryWithKeywordFields($entry, $this->extractKeywordFieldsFromHtml($html));
    }

    private function extractVisiblePriceFromHtml(string $html): ?string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        if ($plain === '') {
            return null;
        }

        $patterns = [
            '/(?:KM|BAM|EUR|€|\$)\s*([0-9][0-9\.\,\s]{0,14})/iu',
            '/([0-9][0-9\.\,\s]{0,14})\s*(?:KM|BAM|EUR|€|\$)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $plain, $matches) === 1 && ! empty($matches[1])) {
                return trim((string) $matches[1]);
            }
        }

        return null;
    }

    private function extractSpecsFromDom(\DOMXPath $xpath): array
    {
        $specs = [];

        $tableRows = $xpath->query('//table//tr');
        if ($tableRows) {
            foreach ($tableRows as $row) {
                $cells = $xpath->query('./th|./td', $row);
                if (! $cells || $cells->length < 2) {
                    continue;
                }

                $label = trim((string) $cells->item(0)?->textContent);
                $value = trim((string) $cells->item(1)?->textContent);
                if ($label !== '' && $value !== '') {
                    $specs[] = $label . ': ' . $value;
                }
                if (count($specs) >= 12) {
                    break;
                }
            }
        }

        if (count($specs) < 12) {
            $listItems = $xpath->query('//*[contains(translate(@class,"SPECATTRIB","specattrib"),"spec") or contains(translate(@class,"SPECATTRIB","specattrib"),"attrib")]//li');
            if ($listItems) {
                foreach ($listItems as $li) {
                    $line = trim((string) $li->textContent);
                    if ($line !== '') {
                        $specs[] = $line;
                    }
                    if (count($specs) >= 12) {
                        break;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($specs)));
    }

    private function extractKeywordFieldsFromNode(array $node): array
    {
        $pairs = [];
        $this->collectPairsFromArray($node, '', $pairs);

        $fields = $this->extractKeywordFieldsFromPairs($pairs);
        $jsonText = json_encode($node, JSON_UNESCAPED_UNICODE);
        if (is_string($jsonText) && $jsonText !== '') {
            $fields = $this->mergeKeywordFieldSets($fields, $this->extractKeywordFieldsFromText($jsonText));
        }

        return $fields;
    }

    private function extractKeywordFieldsFromHtml(string $html): array
    {
        $pairs = [];

        try {
            $crawler = new Crawler($html);

            $crawler->filter('table tr')->each(function (Crawler $row) use (&$pairs) {
                $cells = $row->filter('th,td');
                if ($cells->count() < 2) {
                    return;
                }

                $key = trim($cells->eq(0)->text(''));
                $value = trim($cells->eq(1)->text(''));
                if ($key !== '' && $value !== '') {
                    $pairs[] = ['key' => $key, 'value' => $value];
                }
            });

            $crawler->filter('dt')->each(function (Crawler $dt) use (&$pairs) {
                $key = trim($dt->text(''));
                if ($key === '') {
                    return;
                }

                $dd = $dt->nextAll()->first();
                $value = trim($dd->text(''));
                if ($value !== '') {
                    $pairs[] = ['key' => $key, 'value' => $value];
                }
            });

            $crawler->filter('[itemprop]')->each(function (Crawler $node) use (&$pairs) {
                $itemprop = trim((string) $node->attr('itemprop'));
                if ($itemprop === '') {
                    return;
                }

                $value = trim((string) ($node->attr('content') ?: $node->text('')));
                if ($value === '') {
                    return;
                }

                $pairs[] = ['key' => $itemprop, 'value' => $value];
            });

            $crawler->filter('li')->each(function (Crawler $li) use (&$pairs) {
                $line = trim($li->text(''));
                if ($line === '') {
                    return;
                }

                if (preg_match('/^([^:]{2,80})\s*:\s*(.{1,220})$/u', $line, $matches) === 1) {
                    $pairs[] = [
                        'key' => trim((string) $matches[1]),
                        'value' => trim((string) $matches[2]),
                    ];
                }
            });
        } catch (Throwable) {
            // Fallback via plain text parsing below.
        }

        $fieldsFromPairs = $this->extractKeywordFieldsFromPairs($pairs);

        $plainText = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
        $fieldsFromText = $this->extractKeywordFieldsFromText($plainText);

        return $this->mergeKeywordFieldSets($fieldsFromPairs, $fieldsFromText);
    }

    private function extractKeywordFieldsFromText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $pairs = [];

        if (preg_match_all('/([A-Za-zČĆŽŠĐčćžšđ0-9 _\\/-]{2,80})\s*[:\-]\s*([^\r\n]{1,240})/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = trim((string) ($match[1] ?? ''));
                $value = trim((string) ($match[2] ?? ''));
                if ($key !== '' && $value !== '') {
                    $pairs[] = ['key' => $key, 'value' => $value];
                }
            }
        }

        $fields = $this->extractKeywordFieldsFromPairs($pairs);

        if (empty($fields['price'])) {
            $price = $this->extractVisiblePriceFromHtml($text);
            if ($price) {
                $fields['price'] = $price;
            }
        }

        return $fields;
    }

    private function extractKeywordFieldsFromPairs(array $pairs): array
    {
        $fields = [
            'title' => null,
            'description' => null,
            'price' => null,
            'old_price' => null,
            'image' => null,
            'images' => [],
            'video' => null,
            'source_url' => null,
            'specs' => [],
        ];

        foreach ($pairs as $pair) {
            $key = trim((string) ($pair['key'] ?? ''));
            $value = trim((string) ($pair['value'] ?? ''));
            if ($key === '' || $value === '') {
                continue;
            }

            $field = $this->detectFieldByKeyword($key);

            if ($field === 'price' || $field === 'old_price') {
                if (! $fields[$field]) {
                    $fields[$field] = $value;
                }
                continue;
            }

            if ($field === 'title') {
                if (! $fields['title'] || strlen($value) > strlen((string) $fields['title'])) {
                    $fields['title'] = $value;
                }
                continue;
            }

            if ($field === 'description') {
                if (! $fields['description'] || strlen($value) > strlen((string) $fields['description'])) {
                    $fields['description'] = $value;
                }
                continue;
            }

            if ($field === 'source_url') {
                $url = $this->toValidUrl($value);
                if ($url) {
                    $fields['source_url'] = $url;
                }
                continue;
            }

            if ($field === 'image') {
                $url = $this->toValidUrl($value);
                if ($url) {
                    if (! $fields['image']) {
                        $fields['image'] = $url;
                    }
                    $fields['images'][] = $url;
                }
                continue;
            }

            if ($field === 'video') {
                $url = $this->toValidUrl($value);
                if ($url) {
                    $fields['video'] = $url;
                }
                continue;
            }

            if (strlen($value) <= 180) {
                $fields['specs'][] = $key . ': ' . $value;
            }
        }

        $fields['images'] = $this->normalizeImageUrls($fields['images']);
        $fields['specs'] = array_values(array_unique(array_slice(array_filter($fields['specs']), 0, 16)));

        return $fields;
    }

    private function collectPairsFromArray($data, string $prefix, array &$pairs, int $depth = 0): void
    {
        if ($depth > 6 || ! is_array($data)) {
            return;
        }

        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    $this->collectPairsFromArray($item, $prefix, $pairs, $depth + 1);
                } elseif (is_scalar($item) && $prefix !== '') {
                    $pairs[] = ['key' => $prefix, 'value' => trim((string) $item)];
                }
            }
            return;
        }

        foreach ($data as $key => $value) {
            $keyString = trim((string) $key);
            if ($keyString === '') {
                continue;
            }

            $path = $prefix === '' ? $keyString : ($prefix . '.' . $keyString);

            if (is_array($value)) {
                $this->collectPairsFromArray($value, $path, $pairs, $depth + 1);
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $valueString = trim((string) $value);
            if ($valueString === '') {
                continue;
            }

            $pairs[] = ['key' => $path, 'value' => $valueString];
        }
    }

    private function detectFieldByKeyword(string $key): ?string
    {
        $normalized = $this->normalizeKeyword($key);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->keywordAliases() as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->keywordMatches($normalized, $this->normalizeKeyword($alias))) {
                    return $field;
                }
            }
        }

        return null;
    }

    private function keywordAliases(): array
    {
        return [
            'title' => ['title', 'name', 'naziv', 'ime', 'product_name', 'product name', 'item_name', 'model'],
            'description' => ['description', 'opis', 'summary', 'details', 'detalji', 'content', 'tekst'],
            'price' => ['price', 'cijena', 'cena', 'amount', 'cost', 'akcijska_cijena', 'sale_price', 'current_price'],
            'old_price' => ['old_price', 'stara_cijena', 'stara cena', 'regular_price', 'original_price', 'list_price', 'compare_price'],
            'image' => ['image', 'slika', 'photo', 'thumbnail', 'cover', 'gallery_image', 'image_url'],
            'video' => ['video', 'video_url', 'video_link', 'youtube', 'vimeo', 'tiktok', 'trailer'],
            'source_url' => ['url', 'link', 'permalink', 'product_url', 'source_url', 'item_url', 'loc'],
        ];
    }

    private function keywordMatches(string $key, string $alias): bool
    {
        if ($key === '' || $alias === '') {
            return false;
        }

        if (str_contains($key, $alias) || str_contains($alias, $key)) {
            return true;
        }

        $tokens = preg_split('/\s+/', $key) ?: [];
        $aliasTokens = preg_split('/\s+/', $alias) ?: [];

        foreach ($tokens as $token) {
            $token = trim($token);
            if (strlen($token) < 3) {
                continue;
            }

            foreach ($aliasTokens as $aliasToken) {
                $aliasToken = trim($aliasToken);
                if (strlen($aliasToken) < 3) {
                    continue;
                }

                if ($token === $aliasToken) {
                    return true;
                }

                $distance = levenshtein($token, $aliasToken);
                if ($distance <= 1 || ($distance <= 2 && strlen($aliasToken) >= 7)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeKeyword(string $value): string
    {
        $normalized = Str::lower(Str::ascii($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';
        return trim($normalized);
    }

    private function mergeEntryWithKeywordFields(array $entry, array $keywordFields): array
    {
        $entry['title'] = $this->pickBetterScalar(
            $entry['title'] ?? null,
            $keywordFields['title'] ?? null,
            true
        );
        $entry['description'] = $this->pickBetterScalar(
            $entry['description'] ?? null,
            $keywordFields['description'] ?? null,
            false
        );
        $entry['price'] = $entry['price'] ?? ($keywordFields['price'] ?? null);
        $entry['old_price'] = $entry['old_price'] ?? ($keywordFields['old_price'] ?? null);
        $entry['image'] = $entry['image'] ?? ($keywordFields['image'] ?? null);
        $entry['video'] = $entry['video'] ?? ($keywordFields['video'] ?? null);
        $entry['source_url'] = $entry['source_url'] ?? ($keywordFields['source_url'] ?? null);

        $entryImages = $this->normalizeImageUrls($entry['images'] ?? []);
        $keywordImages = $this->normalizeImageUrls($keywordFields['images'] ?? []);
        $entry['images'] = array_values(array_unique(array_merge($entryImages, $keywordImages)));
        if (! $entry['image'] && count($entry['images']) > 0) {
            $entry['image'] = $entry['images'][0];
        }

        $entrySpecs = $this->normalizeSpecs($entry['specs'] ?? []);
        $keywordSpecs = $this->normalizeSpecs($keywordFields['specs'] ?? []);
        $entry['specs'] = array_values(array_unique(array_merge($entrySpecs, $keywordSpecs)));

        return $entry;
    }

    private function mergeKeywordFieldSets(array $base, array $extra): array
    {
        $merged = $base;

        foreach (['title', 'description', 'price', 'old_price', 'image', 'video', 'source_url'] as $key) {
            if (empty($merged[$key]) && ! empty($extra[$key])) {
                $merged[$key] = $extra[$key];
            }
        }

        $merged['images'] = array_values(array_unique(array_merge(
            $this->normalizeImageUrls($merged['images'] ?? []),
            $this->normalizeImageUrls($extra['images'] ?? [])
        )));

        $merged['specs'] = array_values(array_unique(array_merge(
            $this->normalizeSpecs($merged['specs'] ?? []),
            $this->normalizeSpecs($extra['specs'] ?? [])
        )));

        return $merged;
    }

    private function pickBetterScalar($current, $candidate, bool $isTitle): ?string
    {
        $current = is_scalar($current) ? trim((string) $current) : '';
        $candidate = is_scalar($candidate) ? trim((string) $candidate) : '';

        if ($candidate === '') {
            return $current !== '' ? $current : null;
        }

        if ($current === '') {
            return $candidate;
        }

        if ($isTitle && $this->looksGenericBlockedTitle($current) && ! $this->looksGenericBlockedTitle($candidate)) {
            return $candidate;
        }

        if (strlen($candidate) > strlen($current)) {
            return $candidate;
        }

        return $current;
    }

    private function looksGenericBlockedTitle(string $title): bool
    {
        $normalized = $this->normalizeKeyword($title);
        $signals = [
            'attention required',
            'cloudflare',
            'access denied',
            'forbidden',
            'blocked',
        ];

        foreach ($signals as $signal) {
            if (str_contains($normalized, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeAccessProtectionPage(string $body): bool
    {
        $normalized = $this->normalizeKeyword(substr(strip_tags($body), 0, 15000));
        if ($normalized === '') {
            return false;
        }

        $signals = [
            'cloudflare ray id',
            'sorry you have been blocked',
            'attention required',
            'access denied',
            'enable cookies',
            'challenge platform',
            'just a moment',
            'security service to protect itself',
        ];

        $hits = 0;
        foreach ($signals as $signal) {
            if (str_contains($normalized, $signal)) {
                $hits++;
            }
        }

        return $hits >= 2;
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

    private function findMetaContents(\DOMXPath $xpath, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $query = sprintf('//meta[@property="%1$s"]/@content | //meta[@name="%1$s"]/@content', $key);
            $nodes = $xpath->query($query);
            if (! $nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                $value = $this->toValidUrl(trim((string) $node->nodeValue));
                if ($value) {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
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

    private function normalizeSpecs($specs): array
    {
        if (is_null($specs)) {
            return [];
        }

        $lines = [];

        if (is_string($specs)) {
            $parts = preg_split('/\r\n|\r|\n|[;,]/', $specs) ?: [];
            foreach ($parts as $part) {
                $line = trim((string) $part);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
            return array_values(array_unique(array_slice($lines, 0, 12)));
        }

        if (is_array($specs)) {
            foreach ($specs as $key => $value) {
                if (is_array($value)) {
                    if (array_key_exists('name', $value) && array_key_exists('value', $value)) {
                        $name = trim((string) $value['name']);
                        $val = trim((string) $value['value']);
                        if ($name !== '' && $val !== '') {
                            $lines[] = $name . ': ' . $val;
                        }
                        continue;
                    }
                    foreach ($value as $nested) {
                        if (is_scalar($nested)) {
                            $line = trim((string) $nested);
                            if ($line !== '') {
                                $lines[] = $line;
                            }
                        }
                    }
                    continue;
                }

                if (is_string($key) && is_scalar($value)) {
                    $label = trim($key);
                    $val = trim((string) $value);
                    if ($label !== '' && $val !== '') {
                        $lines[] = $label . ': ' . $val;
                    }
                    continue;
                }

                if (is_scalar($value)) {
                    $line = trim((string) $value);
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
        }

        return array_values(array_unique(array_slice($lines, 0, 12)));
    }

    private function normalizeImageUrls($images): array
    {
        $urls = [];

        if (is_null($images)) {
            return [];
        }

        if (is_string($images)) {
            $url = $this->toValidUrl($images);
            return $url ? [$url] : [];
        }

        if (! is_array($images)) {
            return [];
        }

        foreach ($images as $image) {
            if (is_array($image)) {
                foreach (['url', 'src', 'image', 'image_url'] as $key) {
                    if (array_key_exists($key, $image)) {
                        $candidate = $this->toValidUrl($image[$key]);
                        if ($candidate) {
                            $urls[] = $candidate;
                        }
                    }
                }
                continue;
            }

            $candidate = $this->toValidUrl($image);
            if ($candidate) {
                $urls[] = $candidate;
            }
        }

        return array_values(array_unique($urls));
    }

    private function syncGalleryImages(Item $item, array $imageUrls): void
    {
        if (count($imageUrls) === 0) {
            return;
        }

        $imageUrls = array_values(array_unique(array_filter(array_map(function ($url) {
            return $this->toValidUrl($url);
        }, $imageUrls))));

        if (count($imageUrls) === 0) {
            return;
        }

        $existingRaw = ItemImages::where('item_id', $item->id)
            ->pluck('image')
            ->map(static fn ($value) => trim((string) $value))
            ->filter(static fn ($value) => $value !== '')
            ->values()
            ->all();

        $toInsert = [];
        foreach (array_slice($imageUrls, 0, 12) as $url) {
            if (in_array($url, $existingRaw, true)) {
                continue;
            }

            $toInsert[] = [
                'item_id' => $item->id,
                'image' => $url,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (count($toInsert) > 0) {
            ItemImages::insert($toInsert);
        }
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

    private function xmlNodeValues(\SimpleXMLElement $node, array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            $result = $node->xpath('.//*[local-name()="' . $name . '"]') ?: [];
            foreach ($result as $candidate) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }
}
