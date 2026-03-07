<?php

namespace App\Services;

use App\Models\FeaturedItems;
use App\Models\Item;
use App\Models\Package;
use App\Models\UserPurchasedPackage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FeaturedAdService
{
    private const DEFAULT_PLACEMENT = 'category_home';
    private const DEFAULT_DURATION_DAYS = 30;
    private const MAX_DURATION_DAYS = 365;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function assign(Item $item, int $sellerId, array $options = []): array
    {
        if ($item->trashed()) {
            return [
                'success' => false,
                'message' => 'Paused advertisement cannot be featured.',
            ];
        }

        $itemStatus = strtolower((string) ($item->getAttributes()['status'] ?? $item->status ?? ''));
        if (! in_array($itemStatus, ['approved', 'active', 'featured'], true)) {
            return [
                'success' => false,
                'message' => 'Only active advertisement can be featured.',
            ];
        }

        $placement = $this->normalizePlacement($options['placement'] ?? $options['positions'] ?? null);
        $durationDays = $this->normalizeDurationDays($options['duration_days'] ?? null);
        $preferredPackage = $options['preferred_package'] ?? null;
        if ($preferredPackage !== null && ! ($preferredPackage instanceof Package)) {
            $preferredPackage = null;
        }

        $userPackage = $this->resolveActiveUserPackage($sellerId, $preferredPackage);
        if (! $userPackage) {
            $fallbackPackage = $this->resolveFallbackAdvertisementPackage($preferredPackage);
            if (! $fallbackPackage) {
                return [
                    'success' => false,
                    'message' => 'Unable to feature this ad right now. Please try again later.',
                ];
            }

            $userPackage = UserPurchasedPackage::create([
                'user_id' => $sellerId,
                'package_id' => $fallbackPackage->id,
                'start_date' => Carbon::today()->toDateString(),
                'end_date' => null,
                'total_limit' => null,
                'used_limit' => 0,
            ]);
        }

        $startDate = Carbon::today();
        $requestedEndDate = Carbon::today()->addDays($durationDays);
        $packageEndDate = ! empty($userPackage->end_date) ? Carbon::parse($userPackage->end_date) : null;
        $endDate = $packageEndDate ? $requestedEndDate->min($packageEndDate) : $requestedEndDate;

        $featuredRow = FeaturedItems::query()
            ->where('item_id', $item->id)
            ->where('package_id', $userPackage->package_id)
            ->orderByDesc('id')
            ->first();

        if (! $featuredRow) {
            $featuredRow = FeaturedItems::query()
                ->where('item_id', $item->id)
                ->orderByDesc('id')
                ->first();
        }

        $creatingNewFeaturedRow = ! $featuredRow;
        if ($creatingNewFeaturedRow && $userPackage->total_limit !== null && (int) $userPackage->used_limit >= (int) $userPackage->total_limit) {
            return [
                'success' => false,
                'message' => 'Featured limit has been reached for the selected package.',
            ];
        }

        DB::beginTransaction();
        try {
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
                $featuredRow = FeaturedItems::create([
                    'item_id' => $item->id,
                    'package_id' => $userPackage->package_id,
                    'user_purchased_package_id' => $userPackage->id,
                    'placement' => $placement,
                    'positions' => $placement,
                    'duration_days' => $durationDays,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ]);
            }

            // Keep exactly one active featured row per item.
            $this->deactivateOtherActiveRows($item->id, (int) $featuredRow->id);

            if ($creatingNewFeaturedRow) {
                $userPackage->used_limit = (int) $userPackage->used_limit + 1;
                $userPackage->save();
            }

            DB::commit();

            $featuredExpiresAt = $endDate->copy()->endOfDay();
            $featuredSecondsLeft = max(0, Carbon::now()->diffInSeconds($featuredExpiresAt, false));
            $featuredDaysLeft = (int) ceil($featuredSecondsLeft / 86400);

            return [
                'success' => true,
                'message' => $creatingNewFeaturedRow
                    ? 'Featured advertisement created successfully.'
                    : 'Featured advertisement updated successfully.',
                'meta' => [
                    'placement' => $placement,
                    'positions' => $placement,
                    'duration_days' => $durationDays,
                    'featured_until' => $endDate->toDateString(),
                    'featured_expires_at' => $featuredExpiresAt->toIso8601String(),
                    'featured_seconds_left' => $featuredSecondsLeft,
                    'featured_days_left' => $featuredDaysLeft,
                    'package_id' => (int) $userPackage->package_id,
                    'user_purchased_package_id' => (int) $userPackage->id,
                    'featured_item_id' => (int) $featuredRow->id,
                ],
            ];
        } catch (Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function unfeature(Item $item): array
    {
        $today = Carbon::today();
        $activeRows = FeaturedItems::query()
            ->where('item_id', $item->id)
            ->whereDate('start_date', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today);
            })
            ->get();

        if ($activeRows->isEmpty()) {
            return [
                'success' => true,
                'message' => 'Advertisement is already premium.',
                'meta' => [
                    'deactivated_rows' => 0,
                ],
            ];
        }

        $endDate = $today->copy()->subDay()->toDateString();

        DB::beginTransaction();
        try {
            $affectedRows = 0;
            foreach ($activeRows as $row) {
                $affectedRows += FeaturedItems::query()
                    ->where('id', $row->id)
                    ->update([
                        'end_date' => $endDate,
                        'updated_at' => now(),
                    ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Featured status removed successfully.',
                'meta' => [
                    'deactivated_rows' => $affectedRows,
                ],
            ];
        } catch (Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    private function normalizePlacement(mixed $placement): string
    {
        $normalized = strtolower(trim((string) $placement));

        return in_array($normalized, ['category', 'home', 'category_home'], true)
            ? $normalized
            : self::DEFAULT_PLACEMENT;
    }

    private function normalizeDurationDays(mixed $durationDays): int
    {
        $days = (int) ($durationDays ?: self::DEFAULT_DURATION_DAYS);

        return max(1, min($days, self::MAX_DURATION_DAYS));
    }

    private function resolveActiveUserPackage(int $sellerId, ?Package $preferredPackage = null): ?UserPurchasedPackage
    {
        $today = Carbon::today()->toDateString();

        $query = UserPurchasedPackage::query()
            ->where('user_id', $sellerId)
            ->whereDate('start_date', '<=', $today)
            ->where(function ($scope) use ($today) {
                $scope->whereDate('end_date', '>', $today)
                    ->orWhereNull('end_date');
            })
            ->where(function ($scope) {
                $scope->whereColumn('used_limit', '<', 'total_limit')
                    ->orWhereNull('total_limit');
            })
            ->whereHas('package', function ($packageQuery) {
                $packageQuery->where(function ($scope) {
                    $scope->where('type', 'advertisement');
                    if (Schema::hasColumn('packages', 'package_type')) {
                        $scope->orWhere('package_type', 'advertisement');
                    }
                });
            });

        if ($preferredPackage) {
            return (clone $query)
                ->where('package_id', $preferredPackage->id)
                ->orderBy('end_date')
                ->orderBy('id')
                ->first();
        }

        return (clone $query)
            ->orderBy('end_date')
            ->orderBy('id')
            ->first();
    }

    private function resolveFallbackAdvertisementPackage(?Package $preferredPackage = null): ?Package
    {
        if ($preferredPackage && $this->isAdvertisementPackage($preferredPackage)) {
            return $preferredPackage;
        }

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

        if ($fallbackPackage) {
            return $fallbackPackage;
        }

        $payload = [
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
            $payload['package_type'] = 'advertisement';
        }

        return Package::create($payload);
    }

    private function isAdvertisementPackage(Package $package): bool
    {
        if (strtolower((string) $package->type) === 'advertisement') {
            return true;
        }

        if (Schema::hasColumn('packages', 'package_type')) {
            return strtolower((string) ($package->getAttribute('package_type') ?? '')) === 'advertisement';
        }

        return false;
    }

    private function deactivateOtherActiveRows(int $itemId, int $excludeFeaturedId): void
    {
        $today = Carbon::today();

        FeaturedItems::query()
            ->where('item_id', $itemId)
            ->where('id', '!=', $excludeFeaturedId)
            ->whereDate('start_date', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today);
            })
            ->update([
                'end_date' => $today->copy()->subDay()->toDateString(),
                'updated_at' => now(),
            ]);
    }
}
