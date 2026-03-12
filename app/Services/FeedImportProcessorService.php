<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CustomField;
use App\Models\InstagramImport;
use App\Models\Item;
use App\Models\ItemCustomFieldValue;
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
    private static array $cachedCategoryFields = [];
    private static ?array $cachedCategoryHintPool = null;

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

    /**
     * Dry-run preview for wizard based imports.
     *
     * @return array{
     *   requested_sources:int,
     *   processed_sources:int,
     *   success_sources:int,
     *   failed_sources:int,
     *   entries_total:int,
     *   entries_ready:int,
     *   entries:array<int,array<string,mixed>>,
     *   source_results:array<int,array<string,mixed>>,
     *   domain_summary:array<string,int>,
     *   generated_at:string
     * }
     */
    public function previewSources(array $rawUrls, ?int $forcedCategoryId = null, int $maxEntriesPerSource = 15): array
    {
        $urls = collect($rawUrls)
            ->map(fn ($url) => $this->toValidUrl($url))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $maxEntriesPerSource = max(1, min(50, $maxEntriesPerSource));
        $allEntries = [];
        $sourceResults = [];
        $successSources = 0;
        $failedSources = 0;

        foreach ($urls as $url) {
            $sourceResult = [
                'source_url' => $url,
                'source_host' => parse_url($url, PHP_URL_HOST) ?: null,
                'status' => 'failed',
                'http_status' => null,
                'message' => null,
                'blocked' => false,
                'entries_count' => 0,
            ];

            try {
                $response = $this->fetchUrl($url);
                $body = (string) $response->body();
                $contentType = (string) $response->header('Content-Type', '');
                $sourceResult['http_status'] = $response->status();

                if ($response->status() === 404 || $response->serverError()) {
                    $sourceResult['message'] = 'URL nije dostupan (HTTP ' . $response->status() . ').';
                    $failedSources++;
                    $sourceResults[] = $sourceResult;
                    continue;
                }

                if ($this->looksLikeAccessProtectionPage($body)) {
                    $sourceResult['blocked'] = true;
                    $sourceResult['message'] = 'Udaljeni sajt je blokirao automatski pristup (Cloudflare/anti-bot).';
                    $failedSources++;
                    $sourceResults[] = $sourceResult;
                    continue;
                }

                $entries = $this->extractEntriesFromResponse($url, $body, $contentType, 'api');
                if (count($entries) === 0) {
                    $sourceResult['message'] = 'Nisu pronađeni proizvodi za preview.';
                    $failedSources++;
                    $sourceResults[] = $sourceResult;
                    continue;
                }

                $preparedCount = 0;
                foreach ($entries as $index => $entry) {
                    if ($preparedCount >= $maxEntriesPerSource) {
                        break;
                    }

                    $entryUrl = $this->toValidUrl($entry['source_url'] ?? null, $url);
                    if (! $entryUrl) {
                        $entryKey = trim((string) ($entry['title'] ?? '')) . '|' . trim((string) ($entry['price'] ?? ''));
                        $entryHash = substr(sha1($entryKey ?: ('row-' . $index)), 0, 10);
                        $entryUrl = rtrim($url, '/') . '#preview-' . ($index + 1) . '-' . $entryHash;
                    }

                    $previewEntry = $this->buildPreviewEntry($entry, $entryUrl, $forcedCategoryId, $index + 1);
                    $allEntries[] = $previewEntry;
                    $preparedCount++;
                }

                $sourceResult['status'] = 'ok';
                $sourceResult['entries_count'] = $preparedCount;
                $sourceResult['message'] = $preparedCount > 0
                    ? 'Preview uspješno generisan.'
                    : 'Izvor obrađen, ali nije bilo dovoljno podataka za preview.';

                if ($preparedCount > 0) {
                    $successSources++;
                } else {
                    $failedSources++;
                }
            } catch (Throwable $th) {
                $sourceResult['message'] = 'Greška obrade: ' . Str::limit((string) $th->getMessage(), 180, '...');
                $failedSources++;
            }

            $sourceResults[] = $sourceResult;
        }

        $domainSummary = [];
        foreach ($allEntries as $entry) {
            $host = trim((string) ($entry['source_host'] ?? 'nepoznat'));
            $domainSummary[$host] = ($domainSummary[$host] ?? 0) + 1;
        }
        ksort($domainSummary);

        $entriesReady = collect($allEntries)
            ->filter(static fn (array $entry) => ($entry['is_ready'] ?? false) === true)
            ->count();

        return [
            'requested_sources' => count($urls),
            'processed_sources' => count($sourceResults),
            'success_sources' => $successSources,
            'failed_sources' => $failedSources,
            'entries_total' => count($allEntries),
            'entries_ready' => (int) $entriesReady,
            'entries' => $allEntries,
            'source_results' => $sourceResults,
            'domain_summary' => $domainSummary,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Commit preview-ed entries into actual LMX ads.
     *
     * @return array{
     *   requested_count:int,
     *   imported_count:int,
     *   failed_count:int,
     *   item_ids:array<int,int>,
     *   results:array<int,array<string,mixed>>
     * }
     */
    public function importPreparedEntries(int $userId, array $entries, ?int $fallbackCategoryId = null): array
    {
        $results = [];
        $importedCount = 0;
        $failedCount = 0;
        $itemIds = [];

        foreach ($entries as $index => $rawEntry) {
            if (! is_array($rawEntry)) {
                $failedCount++;
                $results[] = [
                    'index' => $index,
                    'ok' => false,
                    'message' => 'Neispravan format zapisa za import.',
                ];
                continue;
            }

            $entry = $this->sanitizePreparedEntry($rawEntry);
            $entryCategoryId = isset($entry['category_id']) ? (int) $entry['category_id'] : null;
            $categoryId = $this->resolvePreviewCategoryId($entryCategoryId ?: $fallbackCategoryId);
            $sourceUrl = $this->toValidUrl($entry['source_url'] ?? null);

            if (! $sourceUrl) {
                $fallbackBase = 'https://import.lmx.ba/product-' . ($index + 1);
                $sourceUrl = rtrim($fallbackBase, '/');
            }

            if (! $categoryId) {
                $failedCount++;
                $results[] = [
                    'index' => $index,
                    'source_url' => $sourceUrl,
                    'ok' => false,
                    'message' => 'Kategorija nije odabrana ili nije validna.',
                ];
                continue;
            }

            if (! $this->isEntryMeaningful($entry, $sourceUrl)) {
                $failedCount++;
                $results[] = [
                    'index' => $index,
                    'source_url' => $sourceUrl,
                    'ok' => false,
                    'message' => 'Zapis nema dovoljno podataka za kreiranje oglasa.',
                ];
                continue;
            }

            $virtualImport = new InstagramImport([
                'user_id' => $userId,
                'category_id' => $categoryId,
                'feed_format' => 'api',
            ]);

            try {
                $item = $this->upsertItemFromEntry($virtualImport, $entry, $sourceUrl);
                if (! $item) {
                    $failedCount++;
                    $results[] = [
                        'index' => $index,
                        'source_url' => $sourceUrl,
                        'ok' => false,
                        'message' => 'Nije moguće kreirati ili ažurirati oglas.',
                    ];
                    continue;
                }

                $itemId = (int) $item->id;
                $itemIds[] = $itemId;
                $importedCount++;

                $results[] = [
                    'index' => $index,
                    'source_url' => $sourceUrl,
                    'ok' => true,
                    'item_id' => $itemId,
                    'slug' => $item->slug,
                    'message' => 'Oglas je uspješno kreiran/ažuriran.',
                ];
            } catch (Throwable $th) {
                $failedCount++;
                $results[] = [
                    'index' => $index,
                    'source_url' => $sourceUrl,
                    'ok' => false,
                    'message' => 'Greška obrade: ' . Str::limit((string) $th->getMessage(), 180, '...'),
                ];
            }
        }

        return [
            'requested_count' => count($entries),
            'imported_count' => $importedCount,
            'failed_count' => $failedCount,
            'item_ids' => array_values(array_unique($itemIds)),
            'results' => $results,
        ];
    }

    private function buildPreviewEntry(array $entry, string $sourceUrl, ?int $forcedCategoryId, int $rowNumber): array
    {
        $title = trim((string) ($entry['title'] ?? ''));
        if ($title === '') {
            $title = $this->titleFromUrl($sourceUrl);
        }

        $description = trim((string) ($entry['description'] ?? ''));
        $price = $this->normalizePrice($entry['price'] ?? null);
        $oldPrice = $this->normalizePrice($entry['old_price'] ?? null);

        if (($price === null || $price <= 0) && $oldPrice !== null && $oldPrice > 0) {
            $price = $oldPrice;
            $oldPrice = null;
        }

        $images = $this->normalizeImageUrls($entry['images'] ?? null, $sourceUrl);
        $image = $this->toValidUrl($entry['image'] ?? null, $sourceUrl) ?: ($images[0] ?? null);
        $video = $this->toValidUrl($entry['video'] ?? ($entry['video_link'] ?? null), $sourceUrl);
        $specs = $this->normalizeSpecs($entry['specs'] ?? null);

        $quality = $this->evaluatePreviewQuality([
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'old_price' => $oldPrice,
            'image' => $image,
            'images' => $images,
            'video' => $video,
            'specs' => $specs,
        ], $sourceUrl);

        $categoryHint = $this->suggestCategoryHintsForEntry(
            [
                'title' => $title,
                'description' => $description,
                'specs' => $specs,
            ],
            $forcedCategoryId
        );

        $selectedCategoryId = $this->resolvePreviewCategoryId(
            $forcedCategoryId ?: ($categoryHint['primary']['id'] ?? null)
        );

        $host = parse_url($sourceUrl, PHP_URL_HOST) ?: null;
        $previewId = 'preview_' . substr(
            sha1(($host ?: 'source') . '|' . $sourceUrl . '|' . $title . '|' . ($price ?? 'none') . '|' . $rowNumber),
            0,
            16
        );

        return [
            'preview_id' => $previewId,
            'source_url' => $sourceUrl,
            'source_host' => $host,
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'old_price' => $oldPrice,
            'image' => $image,
            'images' => $images,
            'video' => $video,
            'specs' => $specs,
            'quality_score' => $quality['score'],
            'quality_level' => $quality['level'],
            'warnings' => $quality['warnings'],
            'is_ready' => $quality['is_ready'],
            'selected_category_id' => $selectedCategoryId,
            'category_suggestion' => $categoryHint['primary'],
            'category_candidates' => $categoryHint['candidates'],
        ];
    }

    private function sanitizePreparedEntry(array $entry): array
    {
        $sourceUrl = $this->toValidUrl($entry['source_url'] ?? null);
        $title = trim((string) ($entry['title'] ?? ''));
        $description = trim((string) ($entry['description'] ?? ''));
        $price = $entry['price'] ?? null;
        $oldPrice = $entry['old_price'] ?? null;
        $image = $this->toValidUrl($entry['image'] ?? null, $sourceUrl);
        $images = $this->normalizeImageUrls($entry['images'] ?? [], $sourceUrl);
        $video = $this->toValidUrl($entry['video'] ?? ($entry['video_link'] ?? null), $sourceUrl);
        $specs = $this->normalizeSpecs($entry['specs'] ?? []);

        if (! $image && count($images) > 0) {
            $image = $images[0];
        }

        return [
            'source_url' => $sourceUrl,
            'title' => Str::limit($title, 180, ''),
            'description' => Str::limit($description, 5000, ''),
            'price' => $price,
            'old_price' => $oldPrice,
            'image' => $image,
            'images' => array_slice($images, 0, 16),
            'video' => $video,
            'specs' => array_slice($specs, 0, 16),
            'category_id' => isset($entry['category_id']) ? (int) $entry['category_id'] : null,
        ];
    }

    private function evaluatePreviewQuality(array $entry, string $sourceUrl): array
    {
        $score = 0;
        $warnings = [];

        $title = trim((string) ($entry['title'] ?? ''));
        $description = trim((string) ($entry['description'] ?? ''));
        $price = $this->normalizePrice($entry['price'] ?? null);
        $oldPrice = $this->normalizePrice($entry['old_price'] ?? null);
        $image = $this->toValidUrl($entry['image'] ?? null, $sourceUrl);
        $images = $this->normalizeImageUrls($entry['images'] ?? null, $sourceUrl);
        $video = $this->toValidUrl($entry['video'] ?? null, $sourceUrl);
        $specs = $this->normalizeSpecs($entry['specs'] ?? null);

        if ($title !== '' && strlen($title) >= 6 && ! $this->looksGenericBlockedTitle($title)) {
            $score += 25;
        } else {
            $warnings[] = 'Nedostaje kvalitetan naslov.';
        }

        if ($description !== '' && strlen($description) >= 30) {
            $score += 18;
        } else {
            $warnings[] = 'Opis je kratak ili nedostaje.';
        }

        if ($price !== null && $price > 0) {
            $score += 24;
        } elseif ($oldPrice !== null && $oldPrice > 0) {
            $score += 10;
            $warnings[] = 'Pronađena je samo stara cijena.';
        } else {
            $warnings[] = 'Cijena nije pronađena.';
        }

        if ($image || count($images) > 0) {
            $score += 18;
        } else {
            $warnings[] = 'Nedostaje slika proizvoda.';
        }

        if (count($specs) >= 3) {
            $score += 10;
        } elseif (count($specs) > 0) {
            $score += 4;
        } else {
            $warnings[] = 'Nema tehničkih specifikacija.';
        }

        if ($video) {
            $score += 3;
        }

        $isMeaningful = $this->isEntryMeaningful([
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'old_price' => $oldPrice,
            'image' => $image,
            'images' => $images,
            'video' => $video,
            'specs' => $specs,
        ], $sourceUrl);

        $score = min(100, max(0, $score));
        $level = 'low';
        if ($score >= 75) {
            $level = 'high';
        } elseif ($score >= 50) {
            $level = 'medium';
        }

        return [
            'score' => $score,
            'level' => $level,
            'warnings' => array_values(array_unique($warnings)),
            'is_ready' => $isMeaningful && $score >= 35,
        ];
    }

    private function suggestCategoryHintsForEntry(array $entry, ?int $forcedCategoryId = null): array
    {
        $forced = $this->resolvePreviewCategoryId($forcedCategoryId);
        if ($forced) {
            $forcedCategory = Category::without('translations')
                ->select('id', 'name', 'slug')
                ->find($forced);

            if ($forcedCategory) {
                $payload = [
                    'id' => (int) $forcedCategory->id,
                    'name' => (string) $forcedCategory->name,
                    'slug' => (string) ($forcedCategory->slug ?? ''),
                    'score' => 100,
                    'source' => 'manual',
                ];

                return [
                    'primary' => $payload,
                    'candidates' => [$payload],
                ];
            }
        }

        $textChunks = [
            trim((string) ($entry['title'] ?? '')),
            trim((string) ($entry['description'] ?? '')),
        ];

        $specs = $this->normalizeSpecs($entry['specs'] ?? null);
        if (count($specs) > 0) {
            $textChunks[] = implode(' ', array_slice($specs, 0, 12));
        }

        $fullText = trim(implode(' ', array_filter($textChunks)));
        if ($fullText === '') {
            return [
                'primary' => null,
                'candidates' => [],
            ];
        }

        $textTokens = array_values(array_unique(array_filter(
            preg_split('/\s+/', $this->normalizeKeyword($fullText)) ?: [],
            static fn ($token) => strlen((string) $token) >= 3
        )));

        if (count($textTokens) === 0) {
            return [
                'primary' => null,
                'candidates' => [],
            ];
        }

        $candidates = [];
        foreach ($this->getCategoryHintPool() as $row) {
            $categoryTokens = $row['tokens'] ?? [];
            if (count($categoryTokens) === 0) {
                continue;
            }

            $intersection = array_intersect($textTokens, $categoryTokens);
            if (count($intersection) === 0) {
                continue;
            }

            $nameNorm = $row['name_norm'] ?? '';
            $pathNorm = $row['path_norm'] ?? '';
            $matchedWeight = count($intersection) * 14;
            $coverage = (int) round((count($intersection) / max(1, min(12, count($categoryTokens)))) * 60);
            $bonus = 0;

            if ($nameNorm !== '' && str_contains($this->normalizeKeyword($fullText), $nameNorm)) {
                $bonus += 18;
            }

            if (($row['is_leaf'] ?? false) === true) {
                $bonus += 4;
            }

            if ($pathNorm !== '' && str_contains($this->normalizeKeyword($fullText), $pathNorm)) {
                $bonus += 10;
            }

            $score = min(99, $matchedWeight + $coverage + $bonus);
            if ($score < 28) {
                continue;
            }

            $candidates[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) ($row['slug'] ?? ''),
                'score' => (int) $score,
                'source' => 'auto',
            ];
        }

        usort($candidates, static function (array $a, array $b) {
            if (($a['score'] ?? 0) === ($b['score'] ?? 0)) {
                return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
            }
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $candidates = array_values(array_slice($candidates, 0, 5));

        return [
            'primary' => $candidates[0] ?? null,
            'candidates' => $candidates,
        ];
    }

    private function getCategoryHintPool(): array
    {
        if (is_array(self::$cachedCategoryHintPool)) {
            return self::$cachedCategoryHintPool;
        }

        $rows = Category::without('translations')
            ->select('id', 'name', 'slug', 'parent_category_id', 'status')
            ->where('status', 1)
            ->get()
            ->map(static function (Category $category) {
                return [
                    'id' => (int) $category->id,
                    'name' => trim((string) $category->name),
                    'slug' => trim((string) ($category->slug ?? '')),
                    'parent_category_id' => $category->parent_category_id ? (int) $category->parent_category_id : null,
                ];
            })
            ->filter(static fn (array $row) => $row['name'] !== '')
            ->values()
            ->all();

        $byId = [];
        $childrenMap = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
            if (! empty($row['parent_category_id'])) {
                $childrenMap[$row['parent_category_id']] = ($childrenMap[$row['parent_category_id']] ?? 0) + 1;
            }
        }

        $buildPath = static function (int $id) use (&$buildPath, $byId): string {
            $parts = [];
            $current = $byId[$id] ?? null;
            $guard = 0;
            while ($current && $guard < 12) {
                $parts[] = trim((string) ($current['name'] ?? ''));
                $parentId = $current['parent_category_id'] ?? null;
                if (! $parentId || ! isset($byId[$parentId])) {
                    break;
                }
                $current = $byId[$parentId];
                $guard++;
            }

            $parts = array_values(array_filter(array_reverse($parts)));
            return implode(' > ', $parts);
        };

        self::$cachedCategoryHintPool = collect($rows)->map(function (array $row) use ($buildPath, $childrenMap) {
            $path = $buildPath((int) $row['id']);
            $nameNorm = $this->normalizeKeyword((string) $row['name']);
            $pathNorm = $this->normalizeKeyword($path);
            $slugNorm = $this->normalizeKeyword(str_replace('-', ' ', (string) ($row['slug'] ?? '')));

            $tokenPool = trim(implode(' ', array_filter([$nameNorm, $pathNorm, $slugNorm])));
            $tokens = array_values(array_unique(array_filter(
                preg_split('/\s+/', $tokenPool) ?: [],
                static fn ($token) => strlen((string) $token) >= 3
            )));

            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) ($row['slug'] ?? ''),
                'path' => $path,
                'name_norm' => $nameNorm,
                'path_norm' => $pathNorm,
                'tokens' => $tokens,
                'is_leaf' => empty($childrenMap[$row['id']]),
            ];
        })->filter(static fn (array $row) => count($row['tokens']) > 0)->values()->all();

        return self::$cachedCategoryHintPool;
    }

    private function resolvePreviewCategoryId(?int $categoryId): ?int
    {
        if (! $categoryId || $categoryId <= 0) {
            return self::$cachedFallbackCategoryId ?? $this->resolveCategoryId(new InstagramImport());
        }

        $exists = Category::where('id', $categoryId)->exists();
        if ($exists) {
            return $categoryId;
        }

        return self::$cachedFallbackCategoryId ?? $this->resolveCategoryId(new InstagramImport());
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
            $contentType = (string) $response->header('Content-Type', '');
            $feedFormat = (string) ($import->feed_format ?? 'api');

            if ($response->status() === 404 || $response->serverError()) {
                $result['message'] = 'URL nije dostupan (HTTP ' . $response->status() . ').';
                return $result;
            }

            if ($this->looksLikeAccessProtectionPage($body)) {
                $result['message'] = 'Udaljeni sajt je blokirao automatski pristup (Cloudflare/anti-bot). Koristi API/CSV/XML feed ili omogući pristup serveru admin.lmx.ba.';
                return $result;
            }

            $entries = $this->extractEntriesFromResponse(
                $url,
                $body,
                $contentType,
                $feedFormat
            );

            if (count($entries) === 0) {
                $result['message'] = 'Nije pronađen nijedan proizvod u dostavljenom izvoru.';
                return $result;
            }

            $itemIds = [];
            $skippedInsufficient = 0;
            foreach ($entries as $index => $entry) {
                $entryUrl = $this->toValidUrl($entry['source_url'] ?? null, $url);
                if (! $entryUrl) {
                    $entryKey = trim((string) ($entry['title'] ?? '')) . '|' . trim((string) ($entry['price'] ?? ''));
                    $entryHash = substr(sha1($entryKey ?: ('row-' . $index)), 0, 10);
                    $entryUrl = rtrim($url, '/') . '#feed-' . ($index + 1) . '-' . $entryHash;
                }

                if (! $this->isEntryMeaningful($entry, $entryUrl)) {
                    $skippedInsufficient++;
                    continue;
                }

                $item = $this->upsertItemFromEntry($import, $entry, $entryUrl);

                if (! $item) {
                    continue;
                }

                $itemIds[] = (int) $item->id;
            }

            $itemIds = array_values(array_unique($itemIds));
            if (count($itemIds) === 0) {
                if ($response->clientError()) {
                    $result['message'] = 'Udaljeni server vraća HTTP ' . $response->status() . ' i nema dovoljno javnih podataka za kreiranje oglasa.';
                    return $result;
                }

                if ($skippedInsufficient > 0 && $this->isHtmlLikeSource($contentType, $body, $feedFormat)) {
                    $result['message'] = 'Stranica je učitana, ali nema dovoljno pouzdanih podataka za oglas (naslov/opis/cijena/slike/specifikacije).';
                    return $result;
                }

                $result['message'] = 'Feed je obrađen, ali nijedan oglas nije kreiran (nedovoljno podataka).';
                return $result;
            }

            $result['ok'] = true;
            $result['imported_count'] = count($itemIds);
            $result['item_ids'] = $itemIds;
            $result['message'] = 'Uspješno obrađeno i kreirani/ažurirani oglasi.';

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

        $galleryCandidates = $this->normalizeImageUrls($entry['images'] ?? null, $sourceUrl);
        $image = $this->resolveImageValue($entry['image'] ?? ($galleryCandidates[0] ?? null));
        $videoLink = $this->toValidUrl($entry['video'] ?? ($entry['video_url'] ?? ($entry['video_link'] ?? null)), $sourceUrl);

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
            $this->syncCustomFieldValuesFromEntry($existing, $categoryId, $entry, $description);
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
        $this->syncCustomFieldValuesFromEntry($item, $categoryId, $entry, $description);

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
                $url = $this->toValidUrl($node, $fallbackUrl);
                if ($url) {
                    $entries[] = $this->buildFallbackEntryFromUrl($url);
                }
                continue;
            }

            if (! is_array($node)) {
                continue;
            }

            $descriptionFromNode = $this->firstNonEmpty($node, [
                'description',
                'description_short',
                'short_description',
                'desc',
                'details',
                'summary',
                'body',
            ]);

            $specCandidates = array_merge(
                $this->normalizeSpecs($node['attributes'] ?? null),
                $this->normalizeSpecs($node['specs'] ?? null),
                $this->normalizeSpecs($node['properties'] ?? null),
                $this->normalizeSpecs($node['features'] ?? null),
                $this->normalizeSpecs($node['characteristics'] ?? null),
                $this->extractSpecsFromRichText($node['description_short'] ?? null)
            );

            $imageCandidates = array_merge(
                $this->normalizeImageUrls($node['images'] ?? null, $fallbackUrl),
                $this->normalizeImageUrls($node['gallery'] ?? null, $fallbackUrl),
                $this->normalizeImageUrls($node['cover'] ?? null, $fallbackUrl),
                $this->normalizeImageUrls($node['image'] ?? null, $fallbackUrl)
            );

            $entry = [
                'title' => $this->firstNonEmpty($node, [
                    'title',
                    'name',
                    'product_name',
                    'meta_title',
                    'label',
                    'headline',
                ]),
                'description' => $descriptionFromNode,
                'price' => $this->firstNonEmpty($node, [
                    'price',
                    'price_amount',
                    'amount',
                    'current_price',
                    'regular_price',
                    'sale_price',
                    'price_with_reduction',
                    'unit_price',
                ]),
                'old_price' => $this->firstNonEmpty($node, [
                    'old_price',
                    'list_price',
                    'compare_at_price',
                    'original_price',
                    'regular_price',
                    'price_without_reduction',
                ]),
                'image' => $this->extractImageFromNode($node, $fallbackUrl),
                'images' => $imageCandidates,
                'video' => $this->firstNonEmpty($node, ['video', 'video_url', 'video_link', 'trailer']),
                'specs' => array_values(array_unique(array_filter($specCandidates))),
                'source_url' => $this->firstValidUrl(
                    $node,
                    ['url', 'link', 'product_url', 'permalink', 'source_url'],
                    $fallbackUrl
                ),
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
        $globalKeywordFields = $this->extractKeywordFieldsFromHtml($html);
        $htmlAttributeImages = $this->extractImageUrlsFromHtmlAttributes($html, $sourceUrl);

        $candidateEntries = [];
        $candidateEntries = array_merge($candidateEntries, $this->entriesFromJsonLdHtml($html, $sourceUrl));
        $candidateEntries = array_merge($candidateEntries, $this->entriesFromEmbeddedJsonScripts($html, $sourceUrl));
        $candidateEntries = array_merge($candidateEntries, $this->entriesFromDataProductAttributes($html, $sourceUrl));
        $candidateEntries[] = $this->entryFromHtml($html, $sourceUrl);

        $preparedEntries = [];
        foreach ($candidateEntries as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $entry = $this->mergeEntryWithKeywordFields($candidate, $globalKeywordFields);
            $entry['source_url'] = $this->toValidUrl($entry['source_url'] ?? null, $sourceUrl) ?: $sourceUrl;

            $mergedImages = array_merge(
                $this->normalizeImageUrls($entry['images'] ?? null, $sourceUrl),
                $this->normalizeImageUrls($entry['image'] ?? null, $sourceUrl),
                $htmlAttributeImages
            );
            $entry['images'] = $this->normalizeImageUrls($mergedImages, $sourceUrl);
            if (empty($entry['image']) && count($entry['images']) > 0) {
                $entry['image'] = $entry['images'][0];
            }

            $entry['specs'] = array_values(array_unique(array_merge(
                $this->normalizeSpecs($entry['specs'] ?? null),
                $this->extractSpecsFromRichText($entry['description'] ?? null)
            )));

            $preparedEntries[] = $entry;
        }

        return $this->consolidateExtractedHtmlEntries($preparedEntries, $sourceUrl);
    }

    private function consolidateExtractedHtmlEntries(array $entries, string $sourceUrl): array
    {
        if (count($entries) === 0) {
            return [$this->buildFallbackEntryFromUrl($sourceUrl)];
        }

        $byKey = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $resolvedUrl = $this->toValidUrl($entry['source_url'] ?? null, $sourceUrl) ?: $sourceUrl;
            $urlKey = $this->normalizeKeyword($resolvedUrl);
            $titleKey = $this->normalizeKeyword((string) ($entry['title'] ?? ''));
            $key = $urlKey !== '' ? sha1($urlKey) : sha1($urlKey . '|' . $titleKey);

            if (! array_key_exists($key, $byKey)) {
                $byKey[$key] = $entry;
                continue;
            }

            $existing = $byKey[$key];
            $merged = $this->mergeEntryWithKeywordFields($existing, $entry);
            $merged['images'] = $this->normalizeImageUrls(array_merge(
                $this->normalizeImageUrls($existing['images'] ?? null, $sourceUrl),
                $this->normalizeImageUrls($entry['images'] ?? null, $sourceUrl),
                $this->normalizeImageUrls($existing['image'] ?? null, $sourceUrl),
                $this->normalizeImageUrls($entry['image'] ?? null, $sourceUrl)
            ), $sourceUrl);
            if (empty($merged['image']) && count($merged['images']) > 0) {
                $merged['image'] = $merged['images'][0];
            }

            $merged['specs'] = array_values(array_unique(array_merge(
                $this->normalizeSpecs($existing['specs'] ?? null),
                $this->normalizeSpecs($entry['specs'] ?? null)
            )));

            $byKey[$key] = $merged;
        }

        $result = array_values($byKey);
        $meaningful = array_values(array_filter($result, function (array $entry) use ($sourceUrl) {
            return $this->isEntryMeaningful($entry, $sourceUrl);
        }));

        if (count($meaningful) > 0) {
            return $meaningful;
        }

        return count($result) > 0 ? $result : [$this->buildFallbackEntryFromUrl($sourceUrl)];
    }

    private function entriesFromDataProductAttributes(string $html, string $sourceUrl): array
    {
        $entries = [];
        $patterns = [
            '/\bdata-product\s*=\s*"([^"]+)"/iu',
            "/\bdata-product\s*=\s*'([^']+)'/iu",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach (($matches[1] ?? []) as $rawCandidate) {
                    $decodedCandidate = html_entity_decode((string) $rawCandidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $decodedCandidate = trim($decodedCandidate);
                    if (! $this->looksLikeJson($decodedCandidate)) {
                        continue;
                    }

                    $decoded = json_decode($decodedCandidate, true);
                    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                        continue;
                    }

                    $payloadEntries = $this->entriesFromJsonPayload($decoded, $sourceUrl);
                    foreach ($payloadEntries as $payloadEntry) {
                        if (is_array($payloadEntry)) {
                            $entries[] = $payloadEntry;
                        }
                    }
                }
            }
        }

        return $entries;
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
            // Fallback handled below.
        }

        if (count($entries) === 0) {
            $entries = $this->entriesFromJsonLdRegex($html, $sourceUrl);
        }

        return $entries;
    }

    private function entriesFromJsonLdRegex(string $html, string $sourceUrl): array
    {
        $entries = [];
        if (! preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return [];
        }

        foreach (($matches[1] ?? []) as $rawJson) {
            $rawJson = trim(html_entity_decode((string) $rawJson, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($rawJson === '') {
                continue;
            }

            $decoded = json_decode($rawJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Common recovery for trailing commas in schema blocks.
                $sanitized = preg_replace('/,\s*([}\]])/m', '$1', $rawJson) ?? $rawJson;
                $decoded = json_decode($sanitized, true);
            }

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
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

        return $entries;
    }

    private function entriesFromEmbeddedJsonScripts(string $html, string $sourceUrl): array
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
            $scripts = $xpath->query('//script[not(@src)]');
            if (! $scripts) {
                return [];
            }

            foreach ($scripts as $scriptNode) {
                $scriptBody = trim((string) $scriptNode->textContent);
                if ($scriptBody === '' || strlen($scriptBody) < 8) {
                    continue;
                }

                $type = Str::lower(trim((string) ($scriptNode->attributes?->getNamedItem('type')?->nodeValue ?? '')));
                $jsonCandidates = $this->extractEmbeddedJsonCandidates($scriptBody, $type);
                if (count($jsonCandidates) === 0) {
                    continue;
                }

                foreach ($jsonCandidates as $jsonCandidate) {
                    $decoded = json_decode($jsonCandidate, true);
                    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                        continue;
                    }

                    $payloadEntries = $this->entriesFromJsonPayload($decoded, $sourceUrl);
                    foreach ($payloadEntries as $payloadEntry) {
                        if (! is_array($payloadEntry)) {
                            continue;
                        }

                        $entries[] = $payloadEntry;
                    }
                }
            }
        } catch (Throwable) {
            return [];
        }

        if (count($entries) === 0) {
            return [];
        }

        $dedup = [];
        $result = [];
        foreach ($entries as $entry) {
            $hash = sha1(
                trim((string) ($entry['source_url'] ?? '')) . '|' .
                trim((string) ($entry['title'] ?? '')) . '|' .
                trim((string) ($entry['price'] ?? ''))
            );

            if (isset($dedup[$hash])) {
                continue;
            }

            $dedup[$hash] = true;
            $result[] = $entry;
        }

        return $result;
    }

    private function extractEmbeddedJsonCandidates(string $scriptBody, string $scriptType = ''): array
    {
        $candidates = [];
        $trimmed = trim($scriptBody);

        if (str_contains($scriptType, 'json') && $this->looksLikeJson($trimmed)) {
            $candidates[] = $trimmed;
        }

        if ($this->looksLikeJson($trimmed)) {
            $candidates[] = $trimmed;
        }

        $assignmentPattern = '/(?:window\.)?(?:__NEXT_DATA__|__INITIAL_STATE__|__PRELOADED_STATE__|__NUXT__|INITIAL_STATE|PRELOADED_STATE)\s*=\s*/i';
        if (preg_match_all($assignmentPattern, $scriptBody, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $offset = (int) $match[1] + strlen((string) $match[0]);
                $json = $this->extractBalancedJsonFragment($scriptBody, $offset);
                if ($json !== null) {
                    $candidates[] = $json;
                }
            }
        }

        $variableAssignmentPattern = '/\b(?:var|let|const)\s+(?:prestashop|product|productData|product_details|__PRODUCT__|__PRODUCT_DATA__)\s*=\s*/i';
        if (preg_match_all($variableAssignmentPattern, $scriptBody, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $offset = (int) $match[1] + strlen((string) $match[0]);
                $json = $this->extractBalancedJsonFragment($scriptBody, $offset);
                if ($json !== null) {
                    $candidates[] = $json;
                }
            }
        }

        $keywordPattern = '/"(?:products|product|items|offers|itemListElement)"\s*:/i';
        if (preg_match_all($keywordPattern, $scriptBody, $keywordMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($keywordMatches[0] as $match) {
                $keywordOffset = (int) $match[1];
                $prefix = substr($scriptBody, 0, $keywordOffset);
                $objStart = strrpos($prefix, '{');
                if ($objStart === false) {
                    continue;
                }

                $json = $this->extractBalancedJsonFragment($scriptBody, $objStart);
                if ($json !== null) {
                    $candidates[] = $json;
                }
            }
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            $hash = sha1($candidate);
            if (isset($unique[$hash])) {
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                continue;
            }

            $unique[$hash] = $candidate;
        }

        return array_values($unique);
    }

    private function extractBalancedJsonFragment(string $text, int $offset): ?string
    {
        $length = strlen($text);
        if ($offset < 0 || $offset >= $length) {
            return null;
        }

        $start = $offset;
        while ($start < $length && ! in_array($text[$start], ['{', '['], true)) {
            $start++;
        }

        if ($start >= $length) {
            return null;
        }

        $stack = [];
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{' || $char === '[') {
                $stack[] = $char;
                continue;
            }

            if ($char === '}' || $char === ']') {
                $last = array_pop($stack);
                if ($last === null) {
                    return null;
                }

                if (($last === '{' && $char !== '}') || ($last === '[' && $char !== ']')) {
                    return null;
                }

                if (count($stack) === 0) {
                    return substr($text, $start, ($i - $start) + 1);
                }
            }
        }

        return null;
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

        $imageList = $this->normalizeImageUrls($node['image'] ?? ($node['images'] ?? null), $sourceUrl);
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
            'video' => $this->toValidUrl($videoUrl, $sourceUrl),
            'specs' => $specs,
            'source_url' => $this->firstValidUrl($node, ['url'], $sourceUrl),
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

            $images = $this->findMetaContents($xpath, ['og:image', 'twitter:image', 'image'], $sourceUrl);
            $image = $images[0] ?? null;

            if (! $image) {
                $imgNodes = $xpath->query('//img[@src]');
                if ($imgNodes) {
                    foreach ($imgNodes as $imgNode) {
                        $src = $this->toValidUrl($imgNode->attributes?->getNamedItem('src')?->nodeValue, $sourceUrl);
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
            $attributeImages = $this->extractImageUrlsFromHtmlAttributes($html, $sourceUrl);
            if (count($attributeImages) > 0) {
                $images = array_values(array_unique(array_merge($images, $attributeImages)));
                if (! $image && count($images) > 0) {
                    $image = $images[0];
                }
            }
        } catch (Throwable) {
            // fallback is handled below
        }

        if (count($specs) === 0) {
            $specs = $this->extractSpecsFromRichText($description ?? null);
        }

        $entry = [
            'title' => $title ?: $this->titleFromUrl($sourceUrl),
            'description' => $description ?: ('Automatski uvezeno sa: ' . $sourceUrl),
            'price' => $price,
            'old_price' => $oldPrice,
            'image' => $image,
            'images' => $images,
            'video' => $this->toValidUrl($video, $sourceUrl),
            'specs' => $specs,
            'source_url' => $sourceUrl,
        ];

        return $this->mergeEntryWithKeywordFields($entry, $this->extractKeywordFieldsFromHtml($html));
    }

    private function extractVisiblePriceFromHtml(string $html): ?string
    {
        $scriptPatterns = [
            '/"price_amount"\s*:\s*([0-9]+(?:\.[0-9]{1,2})?)/i',
            '/"price_with_reduction"\s*:\s*"([^"]+)"/i',
            '/product:price:amount["\']?\s*(?:content=)?["\']\s*([0-9]+(?:[.,][0-9]{1,2})?)/i',
        ];
        foreach ($scriptPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1 && ! empty($matches[1])) {
                return trim((string) $matches[1]);
            }
        }

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

    private function extractSpecsFromRichText($content): array
    {
        if (! is_scalar($content)) {
            return [];
        }

        $raw = trim((string) $content);
        if ($raw === '') {
            return [];
        }

        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace("\u{00A0}", ' ', $decoded);
        $specs = [];

        if (preg_match_all('/<strong[^>]*>\s*([^:<]{2,120})\s*:?\s*<\/strong>\s*([^<]{1,260})/iu', $decoded, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = trim((string) ($match[1] ?? ''));
                $value = trim((string) ($match[2] ?? ''));
                if ($label !== '' && $value !== '') {
                    $specs[] = $label . ': ' . $value;
                }
            }
        }

        $lineSource = preg_replace('/<\/p>|<br\s*\/?>/iu', "\n", $decoded) ?? $decoded;
        $lineSource = strip_tags($lineSource);
        $lines = preg_split('/\r\n|\r|\n/', $lineSource) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([^:]{2,120})\s*:\s*(.{1,220})$/u', $line, $parts) === 1) {
                $label = trim((string) $parts[1]);
                $value = trim((string) $parts[2]);
                if ($label !== '' && $value !== '') {
                    $specs[] = $label . ': ' . $value;
                }
            }
        }

        return array_values(array_unique(array_slice(array_filter($specs), 0, 16)));
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

    private function findMetaContents(\DOMXPath $xpath, array $keys, ?string $baseUrl = null): array
    {
        $values = [];
        foreach ($keys as $key) {
            $query = sprintf('//meta[@property="%1$s"]/@content | //meta[@name="%1$s"]/@content', $key);
            $nodes = $xpath->query($query);
            if (! $nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                $value = $this->toValidUrl(trim((string) $node->nodeValue), $baseUrl);
                if ($value) {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }

    private function extractImageUrlsFromHtmlAttributes(string $html, string $sourceUrl): array
    {
        $urls = [];
        $patterns = [
            '/\bdata-image-large-src\s*=\s*"([^"]+)"/iu',
            "/\bdata-image-large-src\s*=\s*'([^']+)'/iu",
            '/\bdata-image-medium-src\s*=\s*"([^"]+)"/iu',
            "/\bdata-image-medium-src\s*=\s*'([^']+)'/iu",
            '/\bdata-image-src\s*=\s*"([^"]+)"/iu',
            "/\bdata-image-src\s*=\s*'([^']+)'/iu",
            '/\bdata-full-size-image-url\s*=\s*"([^"]+)"/iu',
            "/\bdata-full-size-image-url\s*=\s*'([^']+)'/iu",
            '/\bdata-zoom-image\s*=\s*"([^"]+)"/iu',
            "/\bdata-zoom-image\s*=\s*'([^']+)'/iu",
            '/\bdata-large-image\s*=\s*"([^"]+)"/iu',
            "/\bdata-large-image\s*=\s*'([^']+)'/iu",
            '/\bdata-src\s*=\s*"([^"]+)"/iu',
            "/\bdata-src\s*=\s*'([^']+)'/iu",
            '/\bsrcset\s*=\s*"([^"]+)"/iu',
            "/\bsrcset\s*=\s*'([^']+)'/iu",
            '/\bdata-image-medium-sources\s*=\s*"([^"]+)"/iu',
            "/\bdata-image-medium-sources\s*=\s*'([^']+)'/iu",
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $html, $matches)) {
                continue;
            }

            foreach (($matches[1] ?? []) as $rawValue) {
                $value = html_entity_decode((string) $rawValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $value = trim($value);
                if ($value === '') {
                    continue;
                }

                if ($this->looksLikeJson($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $urls = array_merge($urls, $this->normalizeImageUrls($decoded, $sourceUrl));
                    }
                    continue;
                }

                if (str_contains($value, ',')) {
                    $parts = explode(',', $value);
                    foreach ($parts as $part) {
                        $token = trim((string) $part);
                        if ($token === '') {
                            continue;
                        }
                        $candidateUrl = preg_split('/\s+/', $token)[0] ?? '';
                        $candidate = $this->toValidUrl($candidateUrl, $sourceUrl);
                        if ($candidate && $this->looksLikeImageUrl($candidate)) {
                            $urls[] = $candidate;
                        }
                    }
                    continue;
                }

                $candidate = $this->toValidUrl($value, $sourceUrl);
                if ($candidate && $this->looksLikeImageUrl($candidate)) {
                    $urls[] = $candidate;
                }
            }
        }

        return array_values(array_unique($urls));
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
        $trimmed = Str::lower(ltrim($content));
        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '<!doctype html') || str_starts_with($trimmed, '<html')) {
            return false;
        }

        if (str_starts_with($trimmed, '<?xml')) {
            return true;
        }

        $xmlRoots = [
            '<rss',
            '<feed',
            '<urlset',
            '<sitemapindex',
            '<channel',
            '<atom:feed',
        ];

        foreach ($xmlRoots as $root) {
            if (str_starts_with($trimmed, $root)) {
                return true;
            }
        }

        return false;
    }

    private function isHtmlLikeSource(string $contentType, string $body, string $feedFormat): bool
    {
        $type = Str::lower($contentType);
        $format = Str::lower(trim($feedFormat));

        if (str_contains($type, 'html')) {
            return true;
        }

        if ($format === 'api' || $format === '') {
            if ($this->looksLikeJson($body) || $this->looksLikeXml($body)) {
                return false;
            }

            $trimmed = ltrim($body);
            return str_starts_with($trimmed, '<!doctype') || str_starts_with($trimmed, '<html') || str_contains($trimmed, '<body');
        }

        return false;
    }

    private function isEntryMeaningful(array $entry, string $sourceUrl): bool
    {
        $title = trim((string) ($entry['title'] ?? ''));
        $description = trim((string) ($entry['description'] ?? ''));
        $price = $this->normalizePrice($entry['price'] ?? null);
        $oldPrice = $this->normalizePrice($entry['old_price'] ?? null);
        $image = $this->toValidUrl($entry['image'] ?? null, $sourceUrl);
        $images = $this->normalizeImageUrls($entry['images'] ?? null, $sourceUrl);
        $video = $this->toValidUrl($entry['video'] ?? null, $sourceUrl);
        $specs = $this->normalizeSpecs($entry['specs'] ?? null);

        $defaultTitle = $this->titleFromUrl($sourceUrl);
        $isGeneratedTitle = $title !== '' && $this->normalizeKeyword($title) === $this->normalizeKeyword($defaultTitle);
        $hasUsefulTitle = $title !== '' && ! $this->looksGenericBlockedTitle($title) && ! $isGeneratedTitle && strlen($title) >= 6;

        $defaultDescriptionPrefix = 'automatski uvezeno sa';
        $normalizedDescription = $this->normalizeKeyword($description);
        $hasUsefulDescription = $description !== '' &&
            ! str_starts_with($normalizedDescription, $defaultDescriptionPrefix) &&
            strlen($description) >= 20;

        $hasPrice = $price !== null || $oldPrice !== null;
        $hasImage = $image !== null || count($images) > 0;
        $hasVideo = $video !== null;
        $hasSpecs = count($specs) > 0;

        return $hasUsefulTitle || $hasUsefulDescription || $hasPrice || $hasImage || $hasVideo || $hasSpecs;
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

    private function firstValidUrl(array $node, array $keys, ?string $baseUrl = null): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $node)) {
                continue;
            }

            $url = $this->toValidUrl($node[$key], $baseUrl);
            if ($url) {
                return $url;
            }
        }

        return null;
    }

    private function extractImageFromNode(array $node, ?string $baseUrl = null): ?string
    {
        $direct = $this->firstValidUrl($node, [
            'image',
            'image_url',
            'thumbnail',
            'photo',
            'picture',
            'main_image',
            'cover',
        ], $baseUrl);
        if ($direct) {
            return $direct;
        }

        foreach (['images', 'gallery', 'cover', 'bySize', 'sources'] as $key) {
            if (! array_key_exists($key, $node) || ! is_array($node[$key])) {
                continue;
            }

            $candidates = $this->normalizeImageUrls($node[$key], $baseUrl);
            if (count($candidates) > 0) {
                return $candidates[0];
            }
        }

        return null;
    }

    private function toValidUrl($value, ?string $baseUrl = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $baseScheme = parse_url((string) $baseUrl, PHP_URL_SCHEME) ?: 'https';
            $candidate = $baseScheme . ':' . $url;
            return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : null;
        }

        if ($baseUrl && (str_starts_with($url, '/') || ! preg_match('/^[a-z][a-z0-9+\-.]*:/i', $url))) {
            $absolute = $this->resolveRelativeUrl($baseUrl, $url);
            return filter_var($absolute, FILTER_VALIDATE_URL) ? $absolute : null;
        }

        return null;
    }

    private function resolveRelativeUrl(string $baseUrl, string $relative): string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return $baseUrl;
        }

        $baseParts = parse_url($baseUrl);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $basePath = $baseParts['path'] ?? '/';

        if ($host === '') {
            return $relative;
        }

        if (str_starts_with($relative, '/')) {
            return $scheme . '://' . $host . $port . $relative;
        }

        $dir = preg_replace('#/[^/]*$#', '/', $basePath);
        $dir = $dir ?: '/';
        $path = $dir . $relative;

        $path = preg_replace('#/\.?/#', '/', $path);
        while (str_contains($path, '../')) {
            $path = preg_replace('#/(?!\.\./)[^/]+/\.\./#', '/', $path, 1) ?? $path;
            if (! str_contains($path, '../')) {
                break;
            }
            // Safety break for malformed paths.
            if (! preg_match('#/(?!\.\./)[^/]+/\.\./#', $path)) {
                $path = str_replace('../', '', $path);
                break;
            }
        }

        return $scheme . '://' . $host . $port . $path;
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

    private function normalizeImageUrls($images, ?string $baseUrl = null): array
    {
        $urls = [];

        if (is_null($images)) {
            return [];
        }

        $this->collectImageUrlsFromMixedData($images, $baseUrl, $urls);
        $unique = array_values(array_unique($urls));
        usort($unique, function (string $a, string $b) {
            return $this->scoreImageUrlCandidate($b) <=> $this->scoreImageUrlCandidate($a);
        });
        return array_slice($unique, 0, 48);
    }

    private function collectImageUrlsFromMixedData($value, ?string $baseUrl, array &$collector, int $depth = 0, string $path = ''): void
    {
        if ($depth > 8 || is_null($value)) {
            return;
        }

        if (is_scalar($value)) {
            $candidate = $this->toValidUrl($value, $baseUrl);
            if (! $candidate) {
                return;
            }

            $pathNorm = $this->normalizeKeyword($path);
            $pathSuggestsImage = str_contains($pathNorm, 'image') ||
                str_contains($pathNorm, 'cover') ||
                str_contains($pathNorm, 'thumb') ||
                str_contains($pathNorm, 'photo') ||
                str_contains($pathNorm, 'picture') ||
                str_contains($pathNorm, 'src') ||
                str_contains($pathNorm, 'large') ||
                str_contains($pathNorm, 'medium') ||
                str_contains($pathNorm, 'small');

            if ($pathSuggestsImage || $this->looksLikeImageUrl($candidate)) {
                $collector[] = $candidate;
            }
            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $nested) {
            $nextPath = is_string($key) && $key !== '' ? ($path === '' ? $key : ($path . '.' . $key)) : $path;
            $this->collectImageUrlsFromMixedData($nested, $baseUrl, $collector, $depth + 1, $nextPath);
        }
    }

    private function looksLikeImageUrl(string $url): bool
    {
        $path = Str::lower((string) parse_url($url, PHP_URL_PATH));
        if ($path === '') {
            return false;
        }

        if (preg_match('/\.(jpg|jpeg|png|webp|gif|bmp|svg|avif)$/i', $path) === 1) {
            return true;
        }

        $markers = [
            'large_default',
            'medium_default',
            'small_default',
            '/img/p/',
            '/images/',
            '/image/',
            '/product/',
            '/thumb',
        ];

        foreach ($markers as $marker) {
            if (str_contains($path, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function scoreImageUrlCandidate(string $url): int
    {
        $path = Str::lower((string) parse_url($url, PHP_URL_PATH));
        $score = 0;

        if (str_contains($path, 'large_default')) {
            $score += 50;
        }
        if (str_contains($path, 'medium_default')) {
            $score += 32;
        }
        if (str_contains($path, 'home_default')) {
            $score += 24;
        }
        if (str_contains($path, 'small_default')) {
            $score -= 8;
        }
        if (str_contains($path, 'thumb')) {
            $score -= 4;
        }
        if (preg_match('/\.(avif|webp)$/i', $path) === 1) {
            $score += 3;
        }

        return $score;
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

    private function syncCustomFieldValuesFromEntry(Item $item, int $categoryId, array $entry, string $description): void
    {
        $pairs = $this->buildSpecPairsForFieldMapping($entry, $description);
        if (count($pairs) === 0) {
            return;
        }

        $fields = $this->getCategoryCustomFields($categoryId);
        if ($fields->isEmpty()) {
            return;
        }

        $hasLanguageColumn = Schema::hasColumn('item_custom_field_values', 'language_id');
        $defaultLangId = 1;

        foreach ($fields as $field) {
            if (! $field instanceof CustomField) {
                continue;
            }

            $rawValue = $this->findBestSpecValueForCustomField($field, $pairs);
            if ($rawValue === null || trim($rawValue) === '') {
                continue;
            }

            $mappedValues = $this->mapRawValueToFieldType($field, $rawValue);
            if (count($mappedValues) === 0) {
                continue;
            }

            $payloadValue = json_encode($mappedValues, JSON_UNESCAPED_UNICODE);
            if (! is_string($payloadValue) || $payloadValue === '') {
                continue;
            }

            $conditions = [
                'item_id' => $item->id,
                'custom_field_id' => $field->id,
            ];

            $attributes = [
                'value' => $payloadValue,
            ];

            if ($hasLanguageColumn) {
                $conditions['language_id'] = $defaultLangId;
                $attributes['language_id'] = $defaultLangId;
            }

            ItemCustomFieldValue::updateOrCreate($conditions, $attributes);
        }
    }

    private function getCategoryCustomFields(int $categoryId)
    {
        if (array_key_exists($categoryId, self::$cachedCategoryFields)) {
            return self::$cachedCategoryFields[$categoryId];
        }

        $fields = CustomField::with('translations')
            ->where('status', 1)
            ->whereHas('custom_field_category', function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->get();

        self::$cachedCategoryFields[$categoryId] = $fields;

        return $fields;
    }

    private function buildSpecPairsForFieldMapping(array $entry, string $description): array
    {
        $pairs = [];

        $specLines = $this->normalizeSpecs($entry['specs'] ?? []);
        foreach ($specLines as $line) {
            if (preg_match('/^([^:]{2,120})\s*:\s*(.{1,260})$/u', $line, $matches) === 1) {
                $pairs[] = [
                    'key' => trim((string) $matches[1]),
                    'value' => trim((string) $matches[2]),
                ];
            }
        }

        $keywordFields = $this->extractKeywordFieldsFromText($description);
        foreach ($this->normalizeSpecs($keywordFields['specs'] ?? []) as $line) {
            if (preg_match('/^([^:]{2,120})\s*:\s*(.{1,260})$/u', $line, $matches) === 1) {
                $pairs[] = [
                    'key' => trim((string) $matches[1]),
                    'value' => trim((string) $matches[2]),
                ];
            }
        }

        foreach ([
            'price' => $entry['price'] ?? null,
            'old_price' => $entry['old_price'] ?? null,
            'title' => $entry['title'] ?? null,
            'description' => $entry['description'] ?? null,
        ] as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $pairs[] = ['key' => $key, 'value' => trim((string) $value)];
            }
        }

        $dedup = [];
        $result = [];
        foreach ($pairs as $pair) {
            $key = trim((string) ($pair['key'] ?? ''));
            $value = trim((string) ($pair['value'] ?? ''));
            if ($key === '' || $value === '') {
                continue;
            }

            $hash = $this->normalizeKeyword($key) . '|' . $this->normalizeKeyword($value);
            if (isset($dedup[$hash])) {
                continue;
            }
            $dedup[$hash] = true;
            $result[] = ['key' => $key, 'value' => $value];
        }

        return $result;
    }

    private function findBestSpecValueForCustomField(CustomField $field, array $pairs): ?string
    {
        $aliases = [$field->name];

        if ($field->relationLoaded('translations')) {
            foreach ($field->translations as $translation) {
                $name = trim((string) ($translation->name ?? ''));
                if ($name !== '') {
                    $aliases[] = $name;
                }
            }
        }

        $aliases = array_values(array_unique(array_filter($aliases)));
        if (count($aliases) === 0) {
            return null;
        }

        $bestScore = 0;
        $bestValue = null;

        foreach ($pairs as $pair) {
            $pairKey = trim((string) ($pair['key'] ?? ''));
            $pairValue = trim((string) ($pair['value'] ?? ''));
            if ($pairKey === '' || $pairValue === '') {
                continue;
            }

            foreach ($aliases as $alias) {
                $score = $this->scoreKeywordSimilarity($pairKey, (string) $alias);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestValue = $pairValue;
                }
            }
        }

        return $bestScore >= 35 ? $bestValue : null;
    }

    private function scoreKeywordSimilarity(string $a, string $b): int
    {
        $na = $this->normalizeKeyword($a);
        $nb = $this->normalizeKeyword($b);

        if ($na === '' || $nb === '') {
            return 0;
        }

        if ($na === $nb) {
            return 100;
        }

        if (str_contains($na, $nb) || str_contains($nb, $na)) {
            return 75;
        }

        if ($this->keywordMatches($na, $nb)) {
            return 60;
        }

        $tokensA = array_values(array_filter(preg_split('/\s+/', $na) ?: []));
        $tokensB = array_values(array_filter(preg_split('/\s+/', $nb) ?: []));
        if (count($tokensA) === 0 || count($tokensB) === 0) {
            return 0;
        }

        $best = 0;
        foreach ($tokensA as $ta) {
            foreach ($tokensB as $tb) {
                if ($ta === $tb) {
                    $best = max($best, 50);
                    continue;
                }

                $distance = levenshtein($ta, $tb);
                if ($distance <= 1) {
                    $best = max($best, 45);
                } elseif ($distance <= 2 && strlen($tb) >= 6) {
                    $best = max($best, 38);
                }
            }
        }

        return $best;
    }

    private function mapRawValueToFieldType(CustomField $field, string $rawValue): array
    {
        $type = trim((string) $field->type);
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return [];
        }

        if ($type === 'number') {
            $number = $this->extractFirstNumericValue($rawValue);
            return $number !== null ? [(string) $number] : [];
        }

        if ($type === 'textbox') {
            $clean = trim($rawValue);
            if ($clean === '') {
                return [];
            }

            if (! empty($field->max_length) && is_numeric($field->max_length)) {
                $max = (int) $field->max_length;
                if ($max > 0) {
                    $clean = substr($clean, 0, $max);
                }
            }

            return [$clean];
        }

        if (in_array($type, ['radio', 'dropdown', 'checkbox'], true)) {
            $options = $this->extractOptionsForCustomField($field);
            if (count($options) === 0) {
                return [$rawValue];
            }

            if ($type === 'checkbox') {
                $chunks = preg_split('/[,;|\/]+/', $rawValue) ?: [$rawValue];
                $selected = [];
                foreach ($chunks as $chunk) {
                    $chunk = trim((string) $chunk);
                    if ($chunk === '') {
                        continue;
                    }

                    $match = $this->findBestOptionMatch($chunk, $options);
                    if ($match !== null) {
                        $selected[] = $match;
                    }
                }

                $selected = array_values(array_unique($selected));
                return count($selected) > 0 ? $selected : [];
            }

            $match = $this->findBestOptionMatch($rawValue, $options);
            return $match !== null ? [$match] : [];
        }

        return [$rawValue];
    }

    private function extractOptionsForCustomField(CustomField $field): array
    {
        $options = [];

        if (is_array($field->values)) {
            foreach ($field->values as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $options[] = $value;
                }
            }
        } elseif (is_string($field->values)) {
            $parts = preg_split('/[|,]/', $field->values) ?: [];
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $options[] = $part;
                }
            }
        }

        if ($field->relationLoaded('translations')) {
            foreach ($field->translations as $translation) {
                $translatedValues = $translation->value ?? null;
                if (is_array($translatedValues)) {
                    foreach ($translatedValues as $tv) {
                        $tv = trim((string) $tv);
                        if ($tv !== '') {
                            $options[] = $tv;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($options));
    }

    private function findBestOptionMatch(string $value, array $options): ?string
    {
        $value = trim($value);
        if ($value === '' || count($options) === 0) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($options as $option) {
            $score = $this->scoreKeywordSimilarity($value, (string) $option);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $option;
            }
        }

        return $bestScore >= 38 ? $best : null;
    }

    private function extractFirstNumericValue(string $value): ?string
    {
        if (preg_match('/-?\d+(?:[\.,]\d+)?/', $value, $matches) !== 1) {
            return null;
        }

        $number = str_replace(',', '.', (string) $matches[0]);
        return is_numeric($number) ? (string) $number : null;
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
