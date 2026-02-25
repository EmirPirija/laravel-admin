<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeaturedItems;
use App\Models\Item;
use App\Models\Package;
use App\Models\Setting;
use App\Models\UserPurchasedPackage;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class BulkAdController extends Controller
{
    private const ACTION_FEATURE = 'feature';
    private const ACTION_RENEW = 'renew';
    private const ACTION_PAUSE = 'pause';
    private const ACTION_RELIST = 'relist';
    private const RENEW_COOLDOWN_DAYS = 15;

    public function apply(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'action' => 'required|in:feature,renew,pause,relist',
                'item_ids' => 'required',
                'package_id' => 'nullable|integer|exists:packages,id',
                'placement' => 'nullable|in:category,home,category_home',
                'duration_days' => 'nullable|integer|min:1|max:365',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse('Neautorizovan pristup', null, 401);
            }

            $action = strtolower(trim((string) $request->input('action')));
            $itemIds = $this->normalizeIds($request->input('item_ids'));
            if (count($itemIds) === 0) {
                return ResponseService::validationError('Pošaljite barem jedan oglas za bulk akciju');
            }

            $itemsById = Item::withTrashed()
                ->where('user_id', $user->id)
                ->whereIn('id', $itemIds)
                ->get()
                ->keyBy('id');

            $freeAdListing = (int) (Setting::where('name', 'free_ad_listing')->value('value') ?? 0);
            $package = null;
            if ($request->filled('package_id')) {
                $package = Package::find($request->input('package_id'));
            }

            $results = [];
            $successCount = 0;
            $failedCount = 0;

            foreach ($itemIds as $itemId) {
                /** @var Item|null $item */
                $item = $itemsById->get($itemId);
                if (! $item) {
                    $failedCount++;
                    $results[] = [
                        'item_id' => (int) $itemId,
                        'status' => 'failed',
                        'message' => 'Oglas nije pronađen ili nemate pristup',
                    ];
                    continue;
                }

                try {
                    $result = match ($action) {
                        self::ACTION_PAUSE => $this->pauseItem($item),
                        self::ACTION_RELIST => $this->relistItem($item),
                        self::ACTION_RENEW => $this->renewItem(
                            $item,
                            $user->id,
                            $freeAdListing,
                            $package
                        ),
                        self::ACTION_FEATURE => $this->featureItem(
                            $item,
                            $user->id,
                            $package,
                            (string) $request->input('placement', 'category_home'),
                            (int) $request->input('duration_days', 30)
                        ),
                        default => ['success' => false, 'message' => 'Nepoznata akcija'],
                    };

                    if (! empty($result['success'])) {
                        $successCount++;
                        $results[] = [
                            'item_id' => (int) $item->id,
                            'status' => 'success',
                            'message' => $result['message'] ?? 'Uspješno',
                            'meta' => $result['meta'] ?? null,
                        ];
                    } else {
                        $failedCount++;
                        $results[] = [
                            'item_id' => (int) $item->id,
                            'status' => 'failed',
                            'message' => $result['message'] ?? 'Akcija nije uspjela',
                            'meta' => $result['meta'] ?? null,
                        ];
                    }
                } catch (Throwable $itemThrowable) {
                    $failedCount++;
                    $results[] = [
                        'item_id' => (int) $item->id,
                        'status' => 'failed',
                        'message' => 'Greška pri obradi oglasa',
                        'meta' => [
                            'details' => $itemThrowable->getMessage(),
                        ],
                    ];
                }
            }

            return ResponseService::successResponse('Bulk akcija je obrađena', [
                'action' => $action,
                'summary' => [
                    'total' => count($itemIds),
                    'success' => $successCount,
                    'failed' => $failedCount,
                ],
                'results' => $results,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'BulkAdController -> apply');
            return ResponseService::errorResponse('Greška pri izvršavanju bulk akcije');
        }
    }

    private function pauseItem(Item $item): array
    {
        if ($item->trashed()) {
            return [
                'success' => true,
                'message' => 'Oglas je već pauziran',
            ];
        }

        $item->delete();

        return [
            'success' => true,
            'message' => 'Oglas je uspješno pauziran',
        ];
    }

    private function relistItem(Item $item): array
    {
        if ($item->trashed()) {
            $item->restore();
            $item->refresh();
        }

        $now = Carbon::now();
        $status = strtolower((string) ($item->getAttributes()['status'] ?? $item->status ?? 'approved'));
        $isExpired = $item->expiry_date ? Carbon::parse($item->expiry_date)->lte($now) : false;

        if ($isExpired) {
            $item->expiry_date = $now->copy()->addDays(30);
        }

        if (in_array($status, ['inactive', 'expired', 'resubmitted', 'sold out', 'scheduled'], true) || $isExpired) {
            $item->status = 'approved';
        }

        $item->save();

        return [
            'success' => true,
            'message' => 'Oglas je ponovo objavljen',
            'meta' => [
                'status' => $item->status,
                'expiry_date' => optional($item->expiry_date)->toDateString(),
            ],
        ];
    }

    private function renewItem(Item $item, int $userId, int $freeAdListing, ?Package $package = null): array
    {
        $now = Carbon::now();
        $hasLastRenewedColumn = Schema::hasColumn('items', 'last_renewed_at');

        $expiryDate = $item->expiry_date ? Carbon::parse($item->expiry_date) : null;
        $isExpired = $expiryDate !== null && $expiryDate->lte($now);

        if (! $isExpired) {
            $isFeatured = FeaturedItems::onlyActive()
                ->where('item_id', $item->id)
                ->exists();
            if ($isFeatured) {
                return [
                    'success' => false,
                    'message' => 'Izdvojeni oglas ne može koristiti obnovu pozicije',
                ];
            }

            $lastRenewedAt = $hasLastRenewedColumn
                ? ($item->getAttribute('last_renewed_at') ?? $item->created_at)
                : ($item->updated_at ?? $item->created_at);

            $nextAllowedAt = Carbon::parse($lastRenewedAt)->addDays(self::RENEW_COOLDOWN_DAYS);
            if ($now->lt($nextAllowedAt)) {
                return [
                    'success' => false,
                    'message' => 'Sljedeća obnova je dostupna ' . $nextAllowedAt->format('d.m.Y H:i'),
                    'meta' => [
                        'next_renewal_at' => $nextAllowedAt->toIso8601String(),
                    ],
                ];
            }

            if ($hasLastRenewedColumn) {
                $item->setAttribute('last_renewed_at', $now);
            }
            $item->save();

            return [
                'success' => true,
                'message' => 'Pozicija oglasa je uspješno obnovljena',
                'meta' => [
                    'renewal_type' => 'position',
                    'next_renewal_at' => $now->copy()->addDays(self::RENEW_COOLDOWN_DAYS)->toIso8601String(),
                ],
            ];
        }

        $userPackage = null;
        if ($package) {
            $userPackage = UserPurchasedPackage::onlyActive()
                ->where('user_id', $userId)
                ->where('package_id', $package->id)
                ->first();

            if (! $userPackage) {
                return [
                    'success' => false,
                    'message' => 'Odabrani paket nije aktivan',
                ];
            }
        }

        if ($freeAdListing === 0 && ! $package) {
            return [
                'success' => false,
                'message' => 'Odaberite paket za obnovu isteklog oglasa',
            ];
        }

        DB::beginTransaction();
        try {
            if ($package && $userPackage) {
                if ($userPackage->total_limit !== null && (int) $userPackage->used_limit >= (int) $userPackage->total_limit) {
                    DB::rollBack();
                    return [
                        'success' => false,
                        'message' => 'Dostignut je limit odabranog paketa',
                    ];
                }

                if ($package->duration === 'unlimited') {
                    $item->expiry_date = null;
                } else {
                    $item->expiry_date = $now->copy()->addDays((int) $package->duration);
                }

                $userPackage->used_limit = (int) $userPackage->used_limit + 1;
                $userPackage->save();
            } else {
                $item->expiry_date = $now->copy()->addDays(30);
            }

            if ($hasLastRenewedColumn) {
                $item->setAttribute('last_renewed_at', $now);
            }

            if ($item->trashed()) {
                $item->restore();
                $item->refresh();
            }

            $item->status = 'approved';
            $item->save();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Istekli oglas je uspješno obnovljen',
                'meta' => [
                    'renewal_type' => 'expiry',
                    'expiry_date' => optional($item->expiry_date)->toDateString(),
                ],
            ];
        } catch (Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    private function featureItem(Item $item, int $userId, ?Package $package, string $placement, int $durationDays): array
    {
        if ($item->trashed()) {
            return [
                'success' => false,
                'message' => 'Pauziran oglas ne može biti izdvojen',
            ];
        }

        $status = strtolower((string) ($item->getAttributes()['status'] ?? $item->status ?? ''));
        if (! in_array($status, ['approved', 'active', 'featured'], true)) {
            return [
                'success' => false,
                'message' => 'Samo aktivan oglas može biti izdvojen',
            ];
        }

        $durationDays = max(1, min($durationDays, 365));
        $placement = in_array($placement, ['category', 'home', 'category_home'], true)
            ? $placement
            : 'category_home';

        $userPackageQuery = UserPurchasedPackage::onlyActive()
            ->where('user_id', $userId)
            ->whereHas('package', function ($q) {
                $q->where('type', 'advertisement');
            });

        if ($package) {
            $userPackageQuery->where('package_id', $package->id);
        }

        $userPackage = $userPackageQuery->orderBy('end_date')->first();
        if (! $userPackage) {
            $fallbackPackage = null;
            if ($package instanceof Package) {
                $fallbackPackage = $package;
            } else {
                $fallbackPackageQuery = Package::query()
                    ->where(function ($query) {
                        $query->where('type', 'advertisement');
                        if (Schema::hasColumn('packages', 'package_type')) {
                            $query->orWhere('package_type', 'advertisement');
                        }
                    });

                $fallbackPackage = (clone $fallbackPackageQuery)
                    ->where(function ($query) {
                        $query->whereNull('status')->orWhere('status', 1);
                    })
                    ->orderByRaw('CASE WHEN final_price = 0 THEN 0 ELSE 1 END')
                    ->orderBy('final_price', 'asc')
                    ->orderBy('id', 'asc')
                    ->first();

                if (! $fallbackPackage) {
                    $fallbackPackage = (clone $fallbackPackageQuery)
                        ->orderByRaw('CASE WHEN final_price = 0 THEN 0 ELSE 1 END')
                        ->orderBy('final_price', 'asc')
                        ->orderBy('id', 'asc')
                        ->first();
                }

                if (! $fallbackPackage) {
                    $fallbackPackage = Package::query()
                        ->where(function ($query) {
                            $query->whereNull('status')->orWhere('status', 1);
                        })
                        ->orderBy('id', 'asc')
                        ->first();
                }

                if (! $fallbackPackage) {
                    $fallbackPayload = [
                        'name' => 'LMX Auto Featured',
                        'description' => 'Auto-generated package used for featured ad fallback.',
                        'price' => 0,
                        'discount_in_percentage' => 0,
                        'final_price' => 0,
                        'duration' => 'unlimited',
                        'item_limit' => 'unlimited',
                        'type' => 'advertisement',
                        'icon' => 'packages/auto-featured.png',
                        'status' => 1,
                    ];
                    if (Schema::hasColumn('packages', 'package_type')) {
                        $fallbackPayload['package_type'] = 'advertisement';
                    }
                    $fallbackPackage = Package::create($fallbackPayload);
                }
            }

            if (! $fallbackPackage) {
                return [
                    'success' => false,
                    'message' => 'Izdvajanje trenutno nije dostupno. Pokušaj ponovo kasnije.',
                ];
            }

            $userPackage = UserPurchasedPackage::create([
                'user_id' => $userId,
                'package_id' => $fallbackPackage->id,
                'start_date' => Carbon::today()->toDateString(),
                'end_date' => null,
                'total_limit' => null,
                'used_limit' => 0,
            ]);
        }

        if ($userPackage->total_limit !== null && (int) $userPackage->used_limit >= (int) $userPackage->total_limit) {
            return [
                'success' => false,
                'message' => 'Dostigli ste limit paketa za izdvajanje',
            ];
        }

        $startDate = Carbon::today();
        $requestedEndDate = Carbon::today()->addDays($durationDays);
        $packageEnd = $userPackage->end_date ? Carbon::parse($userPackage->end_date) : null;
        $endDate = $packageEnd ? $requestedEndDate->min($packageEnd) : $requestedEndDate;

        DB::beginTransaction();
        try {
            $featuredRow = FeaturedItems::where('item_id', $item->id)
                ->where('package_id', $userPackage->package_id)
                ->orderByDesc('id')
                ->first();

            if (! $featuredRow) {
                $featuredRow = FeaturedItems::where('item_id', $item->id)
                    ->orderByDesc('id')
                    ->first();
            }

            $createdFeaturedRow = false;
            if ($featuredRow) {
                $featuredRow->update([
                    'package_id' => $userPackage->package_id,
                    'user_purchased_package_id' => $userPackage->id,
                    'placement' => $placement,
                    'positions' => $placement,
                    'duration_days' => $durationDays,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ]);
            } else {
                FeaturedItems::create([
                    'item_id' => $item->id,
                    'package_id' => $userPackage->package_id,
                    'user_purchased_package_id' => $userPackage->id,
                    'placement' => $placement,
                    'positions' => $placement,
                    'duration_days' => $durationDays,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ]);
                $createdFeaturedRow = true;
            }

            if ($createdFeaturedRow) {
                $userPackage->used_limit = (int) $userPackage->used_limit + 1;
                $userPackage->save();
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Oglas je uspješno izdvojen',
                'meta' => [
                    'placement' => $placement,
                    'duration_days' => $durationDays,
                    'featured_until' => $endDate->toDateString(),
                ],
            ];
        } catch (Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    private function normalizeIds($rawIds): array
    {
        if (is_string($rawIds)) {
            $decoded = json_decode($rawIds, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $rawIds = $decoded;
            } else {
                $rawIds = array_filter(array_map('trim', explode(',', $rawIds)));
            }
        }

        if (! is_array($rawIds)) {
            return [];
        }

        return collect($rawIds)
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
