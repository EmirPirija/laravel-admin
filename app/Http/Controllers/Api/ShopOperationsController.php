<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\SellerSetting;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ShopOperationsController extends Controller
{
    public function inventoryAlerts(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'threshold' => 'nullable|integer|min:1|max:1000',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $settings = SellerSetting::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'low_stock_threshold' => 3,
                    'continue_selling_out_of_stock' => false,
                ]
            );

            $globalThreshold = (int) ($request->input('threshold') ?? ($settings->low_stock_threshold ?? 3));

            $items = Item::withTrashed()
                ->where('user_id', $user->id)
                ->select([
                    'id',
                    'name',
                    'slug',
                    'status',
                    'inventory_count',
                    'price',
                    'price_per_unit',
                    'minimum_order_quantity',
                    'stock_alert_threshold',
                    'deleted_at',
                ])
                ->orderByDesc('id')
                ->get();

            $prepared = $items->map(function (Item $item) use ($globalThreshold, $settings) {
                $inventory = $item->inventory_count !== null ? (int) $item->inventory_count : null;
                $threshold = $item->getAttribute('stock_alert_threshold') !== null
                    ? (int) $item->getAttribute('stock_alert_threshold')
                    : $globalThreshold;

                $isOutOfStock = $inventory !== null && $inventory <= 0;
                $isLowStock = $inventory !== null && $inventory > 0 && $inventory <= $threshold;

                return [
                    'id' => (int) $item->id,
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'status' => $item->status,
                    'inventory_count' => $inventory,
                    'price' => $item->price,
                    'price_per_unit' => $item->getAttribute('price_per_unit'),
                    'minimum_order_quantity' => (int) ($item->getAttribute('minimum_order_quantity') ?? 1),
                    'threshold' => $threshold,
                    'is_low_stock' => $isLowStock,
                    'is_out_of_stock' => $isOutOfStock,
                    'can_order' => ! $isOutOfStock || (bool) ($settings->continue_selling_out_of_stock ?? false),
                    'is_paused' => $item->trashed(),
                ];
            })->values();

            $outOfStock = $prepared->where('is_out_of_stock', true)->values()->all();
            $lowStock = $prepared->where('is_low_stock', true)->values()->all();

            return ResponseService::successResponse('Inventory alert pregled je uspješno dohvaćen', [
                'summary' => [
                    'total_items' => $prepared->count(),
                    'tracked_items' => $prepared->whereNotNull('inventory_count')->count(),
                    'low_stock' => count($lowStock),
                    'out_of_stock' => count($outOfStock),
                    'global_threshold' => $globalThreshold,
                    'continue_selling_out_of_stock' => (bool) ($settings->continue_selling_out_of_stock ?? false),
                ],
                'low_stock_items' => $lowStock,
                'out_of_stock_items' => $outOfStock,
                'items' => $prepared->all(),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ShopOperationsController -> inventoryAlerts');
            return ResponseService::errorResponse('Greška pri dohvatu inventory alerta');
        }
    }

    public function updateInventory(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required|integer|exists:items,id',
                'quantity' => 'required|integer|min:0|max:1000000',
                'mode' => 'nullable|in:set,add,subtract',
                'threshold' => 'nullable|integer|min:1|max:1000',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $item = Item::withTrashed()
                ->where('id', $request->input('item_id'))
                ->where('user_id', $user->id)
                ->first();

            if (! $item) {
                return ResponseService::errorResponse('Oglas nije pronađen');
            }

            $settings = SellerSetting::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'low_stock_threshold' => 3,
                    'continue_selling_out_of_stock' => false,
                ]
            );

            $current = (int) ($item->inventory_count ?? 0);
            $quantity = (int) $request->input('quantity');
            $mode = (string) $request->input('mode', 'set');

            $newInventory = match ($mode) {
                'add' => $current + $quantity,
                'subtract' => max(0, $current - $quantity),
                default => $quantity,
            };

            $item->inventory_count = $newInventory;

            if ($request->filled('threshold') && Schema::hasColumn('items', 'stock_alert_threshold')) {
                $item->setAttribute('stock_alert_threshold', (int) $request->input('threshold'));
            }

            $continueSelling = (bool) ($settings->continue_selling_out_of_stock ?? false);
            if ($newInventory <= 0 && ! $continueSelling) {
                $item->status = 'sold out';
            } elseif ($newInventory > 0) {
                if ($item->trashed()) {
                    $item->restore();
                    $item->refresh();
                }
                if (in_array(strtolower((string) ($item->getAttributes()['status'] ?? $item->status)), ['sold out', 'inactive', 'expired', 'resubmitted'], true)) {
                    $item->status = 'approved';
                }
            }

            $item->save();

            return ResponseService::successResponse('Zaliha je uspješno ažurirana', [
                'item_id' => (int) $item->id,
                'inventory_count' => (int) ($item->inventory_count ?? 0),
                'status' => $item->status,
                'is_out_of_stock' => (int) ($item->inventory_count ?? 0) <= 0,
                'continue_selling_out_of_stock' => $continueSelling,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ShopOperationsController -> updateInventory');
            return ResponseService::errorResponse('Greška pri ažuriranju zalihe');
        }
    }

    public function getDomainSettings(Request $request)
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            if (! $this->hasDomainColumns()) {
                return ResponseService::errorResponse('Domena nije dostupna dok se ne pokrenu migracije');
            }

            $settings = $this->ensureSellerSettings($user->id);
            $target = $this->resolveCnameTarget();

            return ResponseService::successResponse('Postavke domene uspješno dohvaćene', [
                'domain' => $settings->storefront_domain,
                'status' => $settings->storefront_domain_status ?? 'none',
                'verified_at' => optional($settings->storefront_domain_verified_at)->toIso8601String(),
                'error' => $settings->storefront_domain_error,
                'ssl_enabled' => (bool) ($settings->storefront_domain_ssl_enabled ?? false),
                'cname_target' => $settings->storefront_domain_cname_target ?: $target,
                'instructions' => [
                    'record_type' => 'CNAME',
                    'record_name' => 'shop',
                    'target' => $target,
                    'note' => 'Nakon DNS promjene, pokrenite verifikaciju.',
                ],
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ShopOperationsController -> getDomainSettings');
            return ResponseService::errorResponse('Greška pri dohvatu postavki domene');
        }
    }

    public function updateDomain(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'domain' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            if (! $this->hasDomainColumns()) {
                return ResponseService::errorResponse('Domena nije dostupna dok se ne pokrenu migracije');
            }

            $normalizedDomain = $this->normalizeDomain((string) $request->input('domain'));
            if ($normalizedDomain === null) {
                return ResponseService::validationError('Unesite ispravan domen');
            }

            $conflict = SellerSetting::where('user_id', '!=', $user->id)
                ->where('storefront_domain', $normalizedDomain)
                ->exists();
            if ($conflict) {
                return ResponseService::errorResponse('Uneseni domen je već zauzet');
            }

            $settings = $this->ensureSellerSettings($user->id);
            $target = $this->resolveCnameTarget();

            $settings->storefront_domain = $normalizedDomain;
            $settings->storefront_domain_status = 'pending_dns';
            $settings->storefront_domain_verified_at = null;
            $settings->storefront_domain_error = null;
            $settings->storefront_domain_ssl_enabled = false;
            $settings->storefront_domain_cname_target = $target;
            $settings->save();

            return ResponseService::successResponse('Domena je sačuvana. Potrebna je DNS verifikacija', [
                'domain' => $settings->storefront_domain,
                'status' => $settings->storefront_domain_status,
                'cname_target' => $target,
                'instructions' => [
                    'record_type' => 'CNAME',
                    'target' => $target,
                ],
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ShopOperationsController -> updateDomain');
            return ResponseService::errorResponse('Greška pri snimanju domene');
        }
    }

    public function verifyDomain(Request $request)
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            if (! $this->hasDomainColumns()) {
                return ResponseService::errorResponse('Domena nije dostupna dok se ne pokrenu migracije');
            }

            $settings = $this->ensureSellerSettings($user->id);
            $domain = $settings->storefront_domain;
            if (! $domain) {
                return ResponseService::errorResponse('Prvo unesite domen');
            }

            $target = $settings->storefront_domain_cname_target ?: $this->resolveCnameTarget();
            $targetNormalized = rtrim(strtolower($target), '.');

            $records = @dns_get_record($domain, DNS_CNAME);
            $records = is_array($records) ? $records : [];

            $matched = collect($records)
                ->pluck('target')
                ->filter()
                ->map(fn($value) => rtrim(strtolower((string) $value), '.'))
                ->contains($targetNormalized);

            if ($matched) {
                $settings->storefront_domain_status = 'verified';
                $settings->storefront_domain_verified_at = now();
                $settings->storefront_domain_error = null;
                $settings->storefront_domain_ssl_enabled = true;
            } else {
                $foundTargets = collect($records)
                    ->pluck('target')
                    ->filter()
                    ->values()
                    ->all();
                $settings->storefront_domain_status = 'pending_dns';
                $settings->storefront_domain_error = count($foundTargets) > 0
                    ? 'DNS još ne pokazuje na očekivani CNAME target. Trenutno: ' . implode(', ', $foundTargets)
                    : 'CNAME zapis nije pronađen. Provjerite DNS postavke i pokušajte ponovo.';
                $settings->storefront_domain_ssl_enabled = false;
            }

            $settings->save();

            return ResponseService::successResponse('Verifikacija domene je završena', [
                'domain' => $settings->storefront_domain,
                'status' => $settings->storefront_domain_status,
                'verified_at' => optional($settings->storefront_domain_verified_at)->toIso8601String(),
                'error' => $settings->storefront_domain_error,
                'ssl_enabled' => (bool) ($settings->storefront_domain_ssl_enabled ?? false),
                'cname_target' => $target,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ShopOperationsController -> verifyDomain');
            return ResponseService::errorResponse('Greška pri verifikaciji domene');
        }
    }

    private function ensureSellerSettings(int $userId): SellerSetting
    {
        return SellerSetting::firstOrCreate(
            ['user_id' => $userId],
            [
                'low_stock_threshold' => 3,
                'continue_selling_out_of_stock' => false,
                'storefront_domain_status' => 'none',
                'storefront_domain_ssl_enabled' => false,
                'storefront_domain_cname_target' => $this->resolveCnameTarget(),
            ]
        );
    }

    private function resolveCnameTarget(): string
    {
        return (string) (config('app.shop_cname_target') ?: env('SHOP_CNAME_TARGET', 'shops.lmx.ba'));
    }

    private function normalizeDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('~^https?://~', '', $domain);
        $domain = trim((string) $domain, '/');

        if ($domain === '' || strlen($domain) > 253) {
            return null;
        }

        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return null;
        }

        return $domain;
    }

    private function hasDomainColumns(): bool
    {
        return Schema::hasColumn('seller_settings', 'storefront_domain')
            && Schema::hasColumn('seller_settings', 'storefront_domain_status');
    }
}

