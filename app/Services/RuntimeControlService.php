<?php

namespace App\Services;

use App\Models\RuntimeAnnouncement;
use App\Models\RuntimeAnnouncementRead;
use App\Models\RuntimeConfigVersion;
use App\Models\RuntimeFeatureFlag;
use App\Models\RuntimePlanLimit;
use App\Models\RuntimeSetting;
use App\Models\RuntimeUserLimitOverride;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserMembership;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RuntimeControlService
{
    private const CACHE_TTL_SECONDS = 30;

    private const DEFAULT_SERVICES = [
        'listings' => ['enabled' => true, 'message' => 'Objava oglasa je trenutno privremeno nedostupna.'],
        'chat' => ['enabled' => true, 'message' => 'Razmjena poruka je trenutno privremeno nedostupna.'],
        'payments' => ['enabled' => true, 'message' => 'Plaćanja su trenutno privremeno nedostupna.'],
        'questions' => ['enabled' => true, 'message' => 'Javna pitanja su trenutno privremeno nedostupna.'],
        'maps' => ['enabled' => true, 'message' => 'Mapa je trenutno privremeno nedostupna.'],
        'media_uploads' => ['enabled' => true, 'message' => 'Upload medija je trenutno privremeno nedostupan.'],
    ];

    private const DEFAULT_AD_CONTROLS = [
        'create_enabled' => true,
        'edit_enabled' => true,
        'delete_enabled' => true,
        'feature_enabled' => true,
        'renew_enabled' => true,
    ];

    private const DEFAULT_MAINTENANCE = [
        'enabled' => false,
        'message' => 'Sistem je trenutno u režimu održavanja. Molimo pokušajte kasnije.',
        'allow_roles' => [],
        'allow_user_ids' => [],
        'starts_at' => null,
        'ends_at' => null,
    ];

    private const RUNTIME_SETTINGS_KEYS = [
        'maintenance',
        'services',
        'ad_controls',
        'promo_banners',
        'client_defaults',
    ];

    public function getRuntimeConfig(?User $user = null): array
    {
        $version = $this->getCurrentVersion();
        $cacheKey = $this->buildCacheKey($version, $user);

        return Cache::remember($cacheKey, now()->addSeconds(self::CACHE_TTL_SECONDS), function () use ($user, $version) {
            return $this->buildRuntimeConfig($user, $version);
        });
    }

    public function guardAction(string $action, ?User $user = null): array
    {
        $config = $this->getRuntimeConfig($user);

        $maintenance = Arr::get($config, 'maintenance', []);
        if ((bool) ($maintenance['enabled'] ?? false)) {
            return [
                'allowed' => false,
                'reason' => 'maintenance_mode',
                'message' => (string) ($maintenance['message'] ?? self::DEFAULT_MAINTENANCE['message']),
            ];
        }

        $actionMap = [
            'ads.create' => ['service' => 'listings', 'ad_control' => 'create_enabled', 'fallback' => 'Objava oglasa je trenutno privremeno onemogućena.'],
            'ads.edit' => ['service' => 'listings', 'ad_control' => 'edit_enabled', 'fallback' => 'Uređivanje oglasa je trenutno privremeno onemogućeno.'],
            'ads.delete' => ['service' => 'listings', 'ad_control' => 'delete_enabled', 'fallback' => 'Brisanje oglasa je trenutno privremeno onemogućeno.'],
            'ads.feature' => ['service' => 'listings', 'ad_control' => 'feature_enabled', 'fallback' => 'Izdvajanje oglasa je trenutno privremeno onemogućeno.'],
            'ads.renew' => ['service' => 'listings', 'ad_control' => 'renew_enabled', 'fallback' => 'Obnova oglasa je trenutno privremeno onemogućena.'],
            'chat.send' => ['service' => 'chat', 'fallback' => 'Poruke su trenutno privremeno onemogućene.'],
            'offers.create' => ['service' => 'chat', 'fallback' => 'Ponude su trenutno privremeno onemogućene.'],
            'payments.create' => ['service' => 'payments', 'fallback' => 'Plaćanje je trenutno privremeno onemogućeno.'],
        ];

        $rule = $actionMap[$action] ?? null;
        if (!$rule) {
            return ['allowed' => true, 'reason' => null, 'message' => null];
        }

        $serviceKey = $rule['service'] ?? null;
        if ($serviceKey) {
            $service = Arr::get($config, "services.{$serviceKey}", null);
            if (is_array($service) && array_key_exists('enabled', $service) && !$service['enabled']) {
                return [
                    'allowed' => false,
                    'reason' => "service_disabled:{$serviceKey}",
                    'message' => (string) ($service['message'] ?? $rule['fallback']),
                ];
            }
        }

        $adControlKey = $rule['ad_control'] ?? null;
        if ($adControlKey) {
            $adControlEnabled = (bool) Arr::get($config, "ad_controls.{$adControlKey}", true);
            if (!$adControlEnabled) {
                return [
                    'allowed' => false,
                    'reason' => "ad_control_disabled:{$adControlKey}",
                    'message' => (string) $rule['fallback'],
                ];
            }
        }

        return ['allowed' => true, 'reason' => null, 'message' => null];
    }

    public function markAnnouncementRead(int $announcementId, int $userId): void
    {
        RuntimeAnnouncementRead::updateOrCreate(
            [
                'announcement_id' => $announcementId,
                'user_id' => $userId,
            ],
            [
                'read_at' => now(),
            ]
        );
    }

    public function getCurrentVersion(): int
    {
        $versionRow = RuntimeConfigVersion::query()->find(1);
        if (!$versionRow) {
            $versionRow = RuntimeConfigVersion::query()->create([
                'id' => 1,
                'version' => 1,
            ]);
        }

        return max(1, (int) ($versionRow->version ?? 1));
    }

    public function bumpVersion(?int $updatedBy = null, ?string $lastHash = null): int
    {
        return DB::transaction(function () use ($updatedBy, $lastHash) {
            $row = RuntimeConfigVersion::query()->lockForUpdate()->find(1);

            if (!$row) {
                $row = RuntimeConfigVersion::query()->create([
                    'id' => 1,
                    'version' => 1,
                    'updated_by' => $updatedBy,
                    'last_hash' => $lastHash,
                ]);
            } else {
                $row->version = (int) $row->version + 1;
                $row->updated_by = $updatedBy;
                if ($lastHash !== null) {
                    $row->last_hash = $lastHash;
                }
                $row->save();
            }

            return (int) $row->version;
        });
    }

    public function getRuntimeSettingsMap(): array
    {
        $rows = RuntimeSetting::query()
            ->whereIn('key', self::RUNTIME_SETTINGS_KEYS)
            ->get(['key', 'value', 'value_type']);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->key] = $this->decodeStoredValue($row->value);
        }

        return $result;
    }

    public function putRuntimeSettings(array $settings, ?int $updatedBy = null): void
    {
        $upsertRows = [];
        foreach ($settings as $key => $value) {
            if (!in_array($key, self::RUNTIME_SETTINGS_KEYS, true)) {
                continue;
            }

            $upsertRows[] = [
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'value_type' => 'json',
                'updated_by' => $updatedBy,
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        if (!empty($upsertRows)) {
            RuntimeSetting::query()->upsert(
                $upsertRows,
                ['key'],
                ['value', 'value_type', 'updated_by', 'updated_at']
            );
        }
    }

    private function buildRuntimeConfig(?User $user, int $version): array
    {
        $settingsMap = $this->getRuntimeSettingsMap();
        $maintenance = $this->resolveMaintenance($settingsMap, $user);
        $services = $this->resolveServices($settingsMap);
        $adControls = $this->resolveAdControls($settingsMap);
        $promoBanners = $this->resolvePromoBanners($settingsMap, $user);
        $featureFlags = $this->resolveFeatureFlags($user);
        $announcements = $this->resolveAnnouncements($user);
        $limits = $this->resolveLimits($user);

        return [
            'version' => $version,
            'generated_at' => now()->toIso8601String(),
            'maintenance' => $maintenance,
            'services' => $services,
            'ad_controls' => $adControls,
            'promo_banners' => $promoBanners,
            'feature_flags' => $featureFlags,
            'announcements' => $announcements,
            'limits' => $limits,
            'client_defaults' => is_array($settingsMap['client_defaults'] ?? null)
                ? $settingsMap['client_defaults']
                : [],
        ];
    }

    private function resolveMaintenance(array $settingsMap, ?User $user): array
    {
        $settingBasedMaintenance = $this->toBoolean(Setting::query()->where('name', 'maintenance_mode')->value('value'));
        $rawMaintenance = $settingsMap['maintenance'] ?? [];
        if (!is_array($rawMaintenance)) {
            $rawMaintenance = [];
        }

        $maintenance = array_merge(self::DEFAULT_MAINTENANCE, $rawMaintenance);

        $allowRoles = $this->normalizeStringArray($maintenance['allow_roles'] ?? []);
        $allowUserIds = $this->normalizeIntArray($maintenance['allow_user_ids'] ?? []);

        $withinWindow = $this->isWithinWindow(
            $maintenance['starts_at'] ?? null,
            $maintenance['ends_at'] ?? null,
        );

        $enabled = $this->toBoolean($maintenance['enabled']) || $settingBasedMaintenance;
        $enabled = $enabled && $withinWindow;

        $bypass = false;
        if ($enabled && $user) {
            if (!empty($allowUserIds) && in_array((int) $user->id, $allowUserIds, true)) {
                $bypass = true;
            }

            if (!$bypass && !empty($allowRoles) && method_exists($user, 'hasAnyRole')) {
                $bypass = $user->hasAnyRole($allowRoles);
            }
        }

        return [
            'enabled' => $enabled && !$bypass,
            'message' => (string) ($maintenance['message'] ?? self::DEFAULT_MAINTENANCE['message']),
            'allow_roles' => $allowRoles,
            'allow_user_ids' => $allowUserIds,
            'starts_at' => $this->normalizeDateOutput($maintenance['starts_at'] ?? null),
            'ends_at' => $this->normalizeDateOutput($maintenance['ends_at'] ?? null),
            'bypass' => $bypass,
        ];
    }

    private function resolveServices(array $settingsMap): array
    {
        $raw = $settingsMap['services'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $services = self::DEFAULT_SERVICES;

        foreach ($raw as $key => $entry) {
            $normalizedKey = Str::of((string) $key)->trim()->lower()->toString();
            if ($normalizedKey === '') {
                continue;
            }

            $current = $services[$normalizedKey] ?? ['enabled' => true, 'message' => null];

            if (is_bool($entry) || is_numeric($entry) || is_string($entry)) {
                $current['enabled'] = $this->toBoolean($entry);
            } elseif (is_array($entry)) {
                if (array_key_exists('enabled', $entry)) {
                    $current['enabled'] = $this->toBoolean($entry['enabled']);
                }
                if (array_key_exists('message', $entry)) {
                    $current['message'] = (string) ($entry['message'] ?? '');
                }
                if (!empty($entry['meta']) && is_array($entry['meta'])) {
                    $current['meta'] = $entry['meta'];
                }
            }

            $services[$normalizedKey] = $current;
        }

        return $services;
    }

    private function resolveAdControls(array $settingsMap): array
    {
        $raw = $settingsMap['ad_controls'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $controls = self::DEFAULT_AD_CONTROLS;
        foreach (self::DEFAULT_AD_CONTROLS as $key => $defaultValue) {
            if (array_key_exists($key, $raw)) {
                $controls[$key] = $this->toBoolean($raw[$key]);
            }
        }

        foreach ($raw as $key => $value) {
            if (array_key_exists($key, $controls)) {
                continue;
            }

            if (is_array($value)) {
                $controls[$key] = $value;
                continue;
            }

            if (is_bool($value) || is_numeric($value) || is_string($value)) {
                $controls[$key] = $this->toBoolean($value);
            }
        }

        return $controls;
    }

    private function resolveFeatureFlags(?User $user): array
    {
        $now = now();

        $flags = RuntimeFeatureFlag::query()
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $resolved = [];

        foreach ($flags as $flag) {
            $activeWindow = $this->isWithinWindow($flag->starts_at, $flag->ends_at, $now);
            $enabled = false;

            if ($flag->is_enabled && $activeWindow) {
                $enabled = $this->passesRolloutRule(
                    (string) $flag->key,
                    (string) ($flag->rollout_mode ?? 'global'),
                    $flag->rollout_percentage,
                    $this->normalizeStringArray($flag->roles ?? []),
                    $this->normalizeIntArray($flag->user_ids ?? []),
                    $user,
                );

                if ($enabled) {
                    $enabled = $this->passesAdditionalConditions($flag->conditions ?? [], $user);
                }
            }

            $resolved[$flag->key] = [
                'enabled' => $enabled,
                'variant' => $flag->variant,
                'payload' => is_array($flag->payload) ? $flag->payload : [],
                'rollout_mode' => $flag->rollout_mode,
                'rollout_percentage' => $flag->rollout_percentage,
                'priority' => (int) $flag->priority,
            ];
        }

        return $resolved;
    }

    private function resolveAnnouncements(?User $user): array
    {
        $now = now();

        $rows = RuntimeAnnouncement::query()
            ->where('is_active', true)
            ->whereIn('channel', ['web', 'global', 'all'])
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        $readMap = [];
        if ($user) {
            $readMap = RuntimeAnnouncementRead::query()
                ->where('user_id', $user->id)
                ->pluck('read_at', 'announcement_id')
                ->toArray();
        }

        $result = [];

        foreach ($rows as $row) {
            if (!$this->isWithinWindow($row->starts_at, $row->ends_at, $now)) {
                continue;
            }

            $matchesRollout = $this->passesRolloutRule(
                (string) $row->key,
                'hybrid',
                $row->rollout_percentage,
                $this->normalizeStringArray($row->roles ?? []),
                $this->normalizeIntArray($row->user_ids ?? []),
                $user,
            );

            $hasTargeting = !empty($row->roles) || !empty($row->user_ids) || $row->rollout_percentage !== null;
            if ($hasTargeting && !$matchesRollout) {
                continue;
            }

            if ($row->is_dismissible && $user && array_key_exists($row->id, $readMap)) {
                continue;
            }

            $result[] = [
                'id' => (int) $row->id,
                'key' => (string) $row->key,
                'title' => (string) $row->title,
                'message' => (string) $row->message,
                'level' => (string) $row->level,
                'placement' => (string) $row->placement,
                'is_dismissible' => (bool) $row->is_dismissible,
                'action_label' => $row->action_label,
                'action_url' => $row->action_url,
                'starts_at' => optional($row->starts_at)->toIso8601String(),
                'ends_at' => optional($row->ends_at)->toIso8601String(),
                'priority' => (int) $row->priority,
                'metadata' => is_array($row->metadata) ? $row->metadata : [],
            ];
        }

        return $result;
    }

    private function resolvePromoBanners(array $settingsMap, ?User $user): array
    {
        $rows = $settingsMap['promo_banners'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $now = now();
        $result = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                $key = "promo_banner_{$index}";
            }

            $title = trim((string) ($row['title'] ?? ''));
            $message = trim((string) ($row['message'] ?? ''));
            if ($title === '' && $message === '') {
                continue;
            }

            $isActive = array_key_exists('is_active', $row)
                ? $this->toBoolean($row['is_active'])
                : true;
            if (!$isActive) {
                continue;
            }

            if (!$this->isWithinWindow($row['starts_at'] ?? null, $row['ends_at'] ?? null, $now)) {
                continue;
            }

            $roles = $this->normalizeStringArray($row['roles'] ?? []);
            $userIds = $this->normalizeIntArray($row['user_ids'] ?? []);
            $rolloutPercentage = array_key_exists('rollout_percentage', $row)
                ? (is_numeric($row['rollout_percentage']) ? (int) $row['rollout_percentage'] : null)
                : null;

            $matchesRollout = $this->passesRolloutRule(
                "promo-banner:{$key}",
                'hybrid',
                $rolloutPercentage,
                $roles,
                $userIds,
                $user,
            );

            $hasTargeting = !empty($roles) || !empty($userIds) || $rolloutPercentage !== null;
            if ($hasTargeting && !$matchesRollout) {
                continue;
            }

            $slot = trim((string) ($row['slot'] ?? $row['placement'] ?? 'home_top'));
            if ($slot === '') {
                $slot = 'home_top';
            }

            $level = trim((string) ($row['level'] ?? 'info'));
            if ($level === '') {
                $level = 'info';
            }

            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $priority = is_numeric($row['priority'] ?? null) ? (int) $row['priority'] : 0;

            $result[] = [
                'key' => $key,
                'title' => $title,
                'message' => $message,
                'slot' => $slot,
                'level' => $level,
                'cta_label' => $this->nullableTrimmedString($row['cta_label'] ?? null),
                'cta_url' => $this->nullableTrimmedString($row['cta_url'] ?? null),
                'is_dismissible' => array_key_exists('is_dismissible', $row)
                    ? $this->toBoolean($row['is_dismissible'])
                    : false,
                'priority' => $priority,
                'starts_at' => $this->normalizeDateOutput($row['starts_at'] ?? null),
                'ends_at' => $this->normalizeDateOutput($row['ends_at'] ?? null),
                'metadata' => $metadata,
            ];
        }

        usort($result, function (array $a, array $b): int {
            $priorityCompare = ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
        });

        return $result;
    }

    private function resolveLimits(?User $user): array
    {
        $now = now();
        $planLimitsRows = RuntimePlanLimit::query()
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('plan_key')
            ->orderBy('resource_key')
            ->get();

        $planLimits = [];
        foreach ($planLimitsRows as $row) {
            $planLimits[$row->plan_key][$row->resource_key] = [
                'limit_value' => $row->limit_value,
                'period' => $row->period,
                'is_hard_limit' => (bool) $row->is_hard_limit,
                'metadata' => is_array($row->metadata) ? $row->metadata : [],
            ];
        }

        $activePlanKey = $this->resolveUserPlanKey($user);
        $resolved = [];

        if (isset($planLimits['default']) && is_array($planLimits['default'])) {
            $resolved = array_merge($resolved, $planLimits['default']);
        }

        if (isset($planLimits['free']) && is_array($planLimits['free']) && $activePlanKey !== 'free') {
            $resolved = array_merge($resolved, $planLimits['free']);
        }

        if (isset($planLimits[$activePlanKey]) && is_array($planLimits[$activePlanKey])) {
            $resolved = array_merge($resolved, $planLimits[$activePlanKey]);
        }

        $overrideMap = [];
        if ($user) {
            $overrideRows = RuntimeUserLimitOverride::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->where(function ($query) use ($now) {
                    $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($query) use ($now) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                })
                ->get();

            foreach ($overrideRows as $row) {
                $overrideMap[$row->resource_key] = [
                    'limit_value' => $row->limit_value,
                    'period' => $row->period,
                    'is_hard_limit' => (bool) $row->is_hard_limit,
                    'reason' => $row->reason,
                    'metadata' => is_array($row->metadata) ? $row->metadata : [],
                ];
            }

            if (!empty($overrideMap)) {
                $resolved = array_merge($resolved, $overrideMap);
            }
        }

        return [
            'active_plan' => $activePlanKey,
            'plans' => $planLimits,
            'user_overrides' => $overrideMap,
            'resolved' => $resolved,
        ];
    }

    private function resolveUserPlanKey(?User $user): string
    {
        if (!$user) {
            return 'guest';
        }

        $membership = UserMembership::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->latest('id')
            ->first();

        if (!$membership) {
            return 'free';
        }

        $planSource = $membership->tier ?? $membership->tier_name ?? null;
        if (!empty($membership->tier_id)) {
            $tierSlug = DB::table('membership_tiers')->where('id', $membership->tier_id)->value('slug');
            $planSource = $tierSlug ?: $planSource;
        }

        $planKey = Str::slug((string) ($planSource ?: 'free'), '_');
        return $planKey !== '' ? $planKey : 'free';
    }

    private function passesAdditionalConditions(mixed $conditions, ?User $user): bool
    {
        if (!is_array($conditions) || empty($conditions)) {
            return true;
        }

        if (!empty($conditions['requires_authenticated']) && !$user) {
            return false;
        }

        if (!empty($conditions['requires_verified_user'])) {
            if (!$user || !(bool) ($user->is_verified ?? false)) {
                return false;
            }
        }

        if (!empty($conditions['requires_phone_verified'])) {
            if (!$user || empty($user->phone_verified_at)) {
                return false;
            }
        }

        if (!empty($conditions['requires_email_verified'])) {
            if (!$user || empty($user->email_verified_at)) {
                return false;
            }
        }

        $excludedRoles = $this->normalizeStringArray($conditions['excluded_roles'] ?? []);
        if ($user && !empty($excludedRoles) && method_exists($user, 'hasAnyRole') && $user->hasAnyRole($excludedRoles)) {
            return false;
        }

        return true;
    }

    private function passesRolloutRule(
        string $seed,
        string $rolloutMode,
        ?int $rolloutPercentage,
        array $roles,
        array $userIds,
        ?User $user
    ): bool {
        $mode = strtolower(trim($rolloutMode));
        $mode = $mode !== '' ? $mode : 'global';

        $hasRoleTarget = !empty($roles);
        $hasUserTarget = !empty($userIds);
        $hasPercentageTarget = $rolloutPercentage !== null;

        $roleMatch = $hasRoleTarget && $user && method_exists($user, 'hasAnyRole')
            ? (bool) $user->hasAnyRole($roles)
            : false;
        $userMatch = $hasUserTarget && $user ? in_array((int) $user->id, $userIds, true) : false;

        $percentage = $rolloutPercentage === null
            ? null
            : max(0, min(100, (int) $rolloutPercentage));
        $percentageMatch = false;
        if ($percentage !== null && $user) {
            $bucket = $this->resolveUserBucket($seed, (int) $user->id);
            $percentageMatch = $bucket < $percentage;
        }

        return match ($mode) {
            'global' => true,
            'role', 'roles' => $roleMatch,
            'user', 'users' => $userMatch,
            'percentage', 'percent' => $percentageMatch,
            'hybrid' => (!$hasRoleTarget && !$hasUserTarget && !$hasPercentageTarget)
                || $roleMatch
                || $userMatch
                || $percentageMatch,
            default => true,
        };
    }

    private function buildCacheKey(int $version, ?User $user): string
    {
        if (!$user) {
            return "runtime-config:v{$version}:guest";
        }

        $roles = method_exists($user, 'getRoleNames')
            ? implode(',', $user->getRoleNames()->sort()->values()->all())
            : '';

        return "runtime-config:v{$version}:user:{$user->id}:roles:" . md5($roles);
    }

    private function decodeStoredValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $value;
        }
    }

    private function normalizeStringArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s*,\s*/', $value) ?: [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($entry) => trim((string) $entry),
            $value
        ))));
    }

    private function normalizeIntArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s*,\s*/', $value) ?: [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if ($entry === null || $entry === '') {
                continue;
            }
            $id = (int) $entry;
            if ($id > 0) {
                $normalized[] = $id;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
                return false;
            }
        }

        return false;
    }

    private function normalizeDateOutput(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isWithinWindow(mixed $startsAt, mixed $endsAt, ?Carbon $ref = null): bool
    {
        $now = $ref ?: now();

        $start = $this->resolveDate($startsAt);
        if ($start && $now->lt($start)) {
            return false;
        }

        $end = $this->resolveDate($endsAt);
        if ($end && $now->gt($end)) {
            return false;
        }

        return true;
    }

    private function resolveDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveUserBucket(string $seed, int $userId): int
    {
        $hash = sprintf('%u', crc32(strtolower(trim($seed)) . '|' . $userId));
        return ((int) $hash) % 100;
    }
}
