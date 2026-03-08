<?php

namespace App\Http\Controllers;

use App\Models\RuntimeAnnouncement;
use App\Models\RuntimeFeatureFlag;
use App\Models\RuntimePlanLimit;
use App\Models\RuntimeUserLimitOverride;
use App\Services\AuditLogService;
use App\Services\ResponseService;
use App\Services\RuntimeControlService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class RuntimeControlController extends Controller
{
    public function index(RuntimeControlService $runtimeControlService)
    {
        ResponseService::noPermissionThenRedirect('settings-update');

        $settingsMap = $runtimeControlService->getRuntimeSettingsMap();

        $featureFlags = RuntimeFeatureFlag::query()
            ->orderByDesc('priority')
            ->orderBy('key')
            ->get();
        $announcements = RuntimeAnnouncement::query()
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();
        $planLimits = RuntimePlanLimit::query()
            ->orderBy('plan_key')
            ->orderBy('resource_key')
            ->orderBy('period')
            ->get();
        $userOverrides = RuntimeUserLimitOverride::query()
            ->orderBy('user_id')
            ->orderBy('resource_key')
            ->orderBy('period')
            ->get();

        $viewData = [
            'version' => $runtimeControlService->getCurrentVersion(),
            'maintenance_json' => $this->toPrettyJson($settingsMap['maintenance'] ?? []),
            'services_json' => $this->toPrettyJson($settingsMap['services'] ?? []),
            'ad_controls_json' => $this->toPrettyJson($settingsMap['ad_controls'] ?? []),
            'client_defaults_json' => $this->toPrettyJson($settingsMap['client_defaults'] ?? []),
            'feature_flags_json' => $this->toPrettyJson($featureFlags->map(fn ($row) => [
                'key' => $row->key,
                'name' => $row->name,
                'description' => $row->description,
                'is_enabled' => (bool) $row->is_enabled,
                'rollout_mode' => $row->rollout_mode,
                'rollout_percentage' => $row->rollout_percentage,
                'roles' => $row->roles ?? [],
                'user_ids' => $row->user_ids ?? [],
                'variant' => $row->variant,
                'priority' => $row->priority,
                'starts_at' => optional($row->starts_at)->toIso8601String(),
                'ends_at' => optional($row->ends_at)->toIso8601String(),
                'payload' => $row->payload ?? [],
                'conditions' => $row->conditions ?? [],
            ])->values()->all()),
            'announcements_json' => $this->toPrettyJson($announcements->map(fn ($row) => [
                'key' => $row->key,
                'title' => $row->title,
                'message' => $row->message,
                'level' => $row->level,
                'placement' => $row->placement,
                'channel' => $row->channel,
                'is_active' => (bool) $row->is_active,
                'is_dismissible' => (bool) $row->is_dismissible,
                'roles' => $row->roles ?? [],
                'user_ids' => $row->user_ids ?? [],
                'rollout_percentage' => $row->rollout_percentage,
                'starts_at' => optional($row->starts_at)->toIso8601String(),
                'ends_at' => optional($row->ends_at)->toIso8601String(),
                'priority' => $row->priority,
                'action_label' => $row->action_label,
                'action_url' => $row->action_url,
                'metadata' => $row->metadata ?? [],
            ])->values()->all()),
            'plan_limits_json' => $this->toPrettyJson($planLimits->map(fn ($row) => [
                'plan_key' => $row->plan_key,
                'resource_key' => $row->resource_key,
                'limit_value' => $row->limit_value,
                'period' => $row->period,
                'is_hard_limit' => (bool) $row->is_hard_limit,
                'is_active' => (bool) $row->is_active,
                'starts_at' => optional($row->starts_at)->toIso8601String(),
                'ends_at' => optional($row->ends_at)->toIso8601String(),
                'metadata' => $row->metadata ?? [],
            ])->values()->all()),
            'user_overrides_json' => $this->toPrettyJson($userOverrides->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'resource_key' => $row->resource_key,
                'limit_value' => $row->limit_value,
                'period' => $row->period,
                'is_hard_limit' => (bool) $row->is_hard_limit,
                'is_active' => (bool) $row->is_active,
                'reason' => $row->reason,
                'starts_at' => optional($row->starts_at)->toIso8601String(),
                'ends_at' => optional($row->ends_at)->toIso8601String(),
                'metadata' => $row->metadata ?? [],
            ])->values()->all()),
            'runtime_preview_json' => $this->toPrettyJson($runtimeControlService->getRuntimeConfig(null)),
        ];

        return view('settings.runtime-control', $viewData);
    }

    public function saveSettings(Request $request, RuntimeControlService $runtimeControlService)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        try {
            $maintenance = $this->decodeJsonInput($request->input('maintenance_json', '{}'), 'maintenance_json');
            $services = $this->decodeJsonInput($request->input('services_json', '{}'), 'services_json');
            $adControls = $this->decodeJsonInput($request->input('ad_controls_json', '{}'), 'ad_controls_json');
            $clientDefaults = $this->decodeJsonInput($request->input('client_defaults_json', '{}'), 'client_defaults_json');

            if (!is_array($maintenance) || !is_array($services) || !is_array($adControls) || !is_array($clientDefaults)) {
                ResponseService::validationError('Runtime settings JSON mora biti validan objekat.');
            }

            DB::transaction(function () use ($runtimeControlService, $maintenance, $services, $adControls, $clientDefaults) {
                $runtimeControlService->putRuntimeSettings([
                    'maintenance' => $maintenance,
                    'services' => $services,
                    'ad_controls' => $adControls,
                    'client_defaults' => $clientDefaults,
                ], Auth::id());

                $runtimeControlService->bumpVersion(Auth::id());
            });

            AuditLogService::log('runtime_settings_updated', null, null, [
                'updated_by' => Auth::id(),
            ]);

            return redirect()->route('settings.runtime-control')->with('success', __('Runtime settings updated successfully.'));
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'RuntimeControlController -> saveSettings', 'Error Occurred', false);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function saveFeatureFlags(Request $request, RuntimeControlService $runtimeControlService)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        try {
            $rows = $this->decodeJsonInput($request->input('feature_flags_json', '[]'), 'feature_flags_json');
            if (!is_array($rows)) {
                ResponseService::validationError('Feature flags payload mora biti JSON niz.');
            }

            DB::transaction(function () use ($rows, $runtimeControlService) {
                $keys = [];
                foreach ($rows as $index => $row) {
                    if (!is_array($row)) {
                        ResponseService::validationError("Feature flag row #{$index} mora biti JSON objekat.");
                    }

                    $key = trim((string) ($row['key'] ?? ''));
                    if ($key === '') {
                        ResponseService::validationError("Feature flag row #{$index} nema validan key.");
                    }
                    $keys[] = $key;

                    RuntimeFeatureFlag::query()->updateOrCreate(
                        ['key' => $key],
                        [
                            'name' => $this->nullableString($row['name'] ?? null),
                            'description' => $this->nullableString($row['description'] ?? null),
                            'is_enabled' => $this->toBoolean($row['is_enabled'] ?? false),
                            'rollout_mode' => $this->nullableString($row['rollout_mode'] ?? 'global') ?? 'global',
                            'rollout_percentage' => $this->nullableInt($row['rollout_percentage'] ?? null),
                            'roles' => $this->normalizeArray($row['roles'] ?? []),
                            'user_ids' => $this->normalizeNumericArray($row['user_ids'] ?? []),
                            'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : [],
                            'conditions' => is_array($row['conditions'] ?? null) ? $row['conditions'] : [],
                            'variant' => $this->nullableString($row['variant'] ?? null),
                            'priority' => (int) ($row['priority'] ?? 0),
                            'starts_at' => $this->nullableDate($row['starts_at'] ?? null),
                            'ends_at' => $this->nullableDate($row['ends_at'] ?? null),
                            'updated_by' => Auth::id(),
                            'created_by' => Auth::id(),
                        ]
                    );
                }

                $keys = array_unique($keys);
                if (empty($keys)) {
                    RuntimeFeatureFlag::query()->delete();
                } else {
                    RuntimeFeatureFlag::query()->whereNotIn('key', $keys)->delete();
                }
                $runtimeControlService->bumpVersion(Auth::id());
            });

            AuditLogService::log('runtime_feature_flags_updated', RuntimeFeatureFlag::class, null, [
                'updated_by' => Auth::id(),
            ]);

            return redirect()->route('settings.runtime-control')->with('success', __('Feature flags updated successfully.'));
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'RuntimeControlController -> saveFeatureFlags', 'Error Occurred', false);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function saveAnnouncements(Request $request, RuntimeControlService $runtimeControlService)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        try {
            $rows = $this->decodeJsonInput($request->input('announcements_json', '[]'), 'announcements_json');
            if (!is_array($rows)) {
                ResponseService::validationError('Announcements payload mora biti JSON niz.');
            }

            DB::transaction(function () use ($rows, $runtimeControlService) {
                $keys = [];
                foreach ($rows as $index => $row) {
                    if (!is_array($row)) {
                        ResponseService::validationError("Announcement row #{$index} mora biti JSON objekat.");
                    }

                    $key = trim((string) ($row['key'] ?? ''));
                    $title = trim((string) ($row['title'] ?? ''));
                    $message = trim((string) ($row['message'] ?? ''));

                    if ($key === '' || $title === '' || $message === '') {
                        ResponseService::validationError("Announcement row #{$index} mora imati key, title i message.");
                    }

                    $keys[] = $key;

                    RuntimeAnnouncement::query()->updateOrCreate(
                        ['key' => $key],
                        [
                            'title' => $title,
                            'message' => $message,
                            'level' => $this->nullableString($row['level'] ?? 'info') ?? 'info',
                            'placement' => $this->nullableString($row['placement'] ?? 'global_top') ?? 'global_top',
                            'channel' => $this->nullableString($row['channel'] ?? 'web') ?? 'web',
                            'is_active' => $this->toBoolean($row['is_active'] ?? true),
                            'is_dismissible' => $this->toBoolean($row['is_dismissible'] ?? true),
                            'roles' => $this->normalizeArray($row['roles'] ?? []),
                            'user_ids' => $this->normalizeNumericArray($row['user_ids'] ?? []),
                            'rollout_percentage' => $this->nullableInt($row['rollout_percentage'] ?? null),
                            'action_label' => $this->nullableString($row['action_label'] ?? null),
                            'action_url' => $this->nullableString($row['action_url'] ?? null),
                            'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                            'priority' => (int) ($row['priority'] ?? 0),
                            'starts_at' => $this->nullableDate($row['starts_at'] ?? null),
                            'ends_at' => $this->nullableDate($row['ends_at'] ?? null),
                            'updated_by' => Auth::id(),
                            'created_by' => Auth::id(),
                        ]
                    );
                }

                $keys = array_unique($keys);
                if (empty($keys)) {
                    RuntimeAnnouncement::query()->delete();
                } else {
                    RuntimeAnnouncement::query()->whereNotIn('key', $keys)->delete();
                }
                $runtimeControlService->bumpVersion(Auth::id());
            });

            AuditLogService::log('runtime_announcements_updated', RuntimeAnnouncement::class, null, [
                'updated_by' => Auth::id(),
            ]);

            return redirect()->route('settings.runtime-control')->with('success', __('Announcements updated successfully.'));
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'RuntimeControlController -> saveAnnouncements', 'Error Occurred', false);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function savePlanLimits(Request $request, RuntimeControlService $runtimeControlService)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        try {
            $rows = $this->decodeJsonInput($request->input('plan_limits_json', '[]'), 'plan_limits_json');
            if (!is_array($rows)) {
                ResponseService::validationError('Plan limits payload mora biti JSON niz.');
            }

            DB::transaction(function () use ($rows, $runtimeControlService) {
                $keys = [];
                foreach ($rows as $index => $row) {
                    if (!is_array($row)) {
                        ResponseService::validationError("Plan limit row #{$index} mora biti JSON objekat.");
                    }

                    $planKey = trim((string) ($row['plan_key'] ?? ''));
                    $resourceKey = trim((string) ($row['resource_key'] ?? ''));
                    $period = trim((string) ($row['period'] ?? 'month'));

                    if ($planKey === '' || $resourceKey === '') {
                        ResponseService::validationError("Plan limit row #{$index} mora imati plan_key i resource_key.");
                    }

                    $keys[] = "{$planKey}|{$resourceKey}|{$period}";

                    RuntimePlanLimit::query()->updateOrCreate(
                        [
                            'plan_key' => $planKey,
                            'resource_key' => $resourceKey,
                            'period' => $period,
                        ],
                        [
                            'limit_value' => $this->nullableInt($row['limit_value'] ?? null),
                            'is_hard_limit' => $this->toBoolean($row['is_hard_limit'] ?? false),
                            'is_active' => $this->toBoolean($row['is_active'] ?? true),
                            'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                            'starts_at' => $this->nullableDate($row['starts_at'] ?? null),
                            'ends_at' => $this->nullableDate($row['ends_at'] ?? null),
                            'updated_by' => Auth::id(),
                            'created_by' => Auth::id(),
                        ]
                    );
                }

                RuntimePlanLimit::query()->get()->each(function (RuntimePlanLimit $entry) use ($keys): void {
                    $composite = "{$entry->plan_key}|{$entry->resource_key}|{$entry->period}";
                    if (!in_array($composite, $keys, true)) {
                        $entry->delete();
                    }
                });

                $runtimeControlService->bumpVersion(Auth::id());
            });

            AuditLogService::log('runtime_plan_limits_updated', RuntimePlanLimit::class, null, [
                'updated_by' => Auth::id(),
            ]);

            return redirect()->route('settings.runtime-control')->with('success', __('Plan limits updated successfully.'));
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'RuntimeControlController -> savePlanLimits', 'Error Occurred', false);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function saveUserOverrides(Request $request, RuntimeControlService $runtimeControlService)
    {
        ResponseService::noPermissionThenSendJson('settings-update');

        try {
            $rows = $this->decodeJsonInput($request->input('user_overrides_json', '[]'), 'user_overrides_json');
            if (!is_array($rows)) {
                ResponseService::validationError('User overrides payload mora biti JSON niz.');
            }

            DB::transaction(function () use ($rows, $runtimeControlService) {
                $keys = [];

                foreach ($rows as $index => $row) {
                    if (!is_array($row)) {
                        ResponseService::validationError("User override row #{$index} mora biti JSON objekat.");
                    }

                    $userId = (int) ($row['user_id'] ?? 0);
                    $resourceKey = trim((string) ($row['resource_key'] ?? ''));
                    $period = trim((string) ($row['period'] ?? 'month'));

                    if ($userId <= 0 || $resourceKey === '') {
                        ResponseService::validationError("User override row #{$index} mora imati validan user_id i resource_key.");
                    }

                    $keys[] = "{$userId}|{$resourceKey}|{$period}";

                    RuntimeUserLimitOverride::query()->updateOrCreate(
                        [
                            'user_id' => $userId,
                            'resource_key' => $resourceKey,
                            'period' => $period,
                        ],
                        [
                            'limit_value' => $this->nullableInt($row['limit_value'] ?? null),
                            'is_hard_limit' => $this->toBoolean($row['is_hard_limit'] ?? false),
                            'is_active' => $this->toBoolean($row['is_active'] ?? true),
                            'reason' => $this->nullableString($row['reason'] ?? null),
                            'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                            'starts_at' => $this->nullableDate($row['starts_at'] ?? null),
                            'ends_at' => $this->nullableDate($row['ends_at'] ?? null),
                            'updated_by' => Auth::id(),
                            'created_by' => Auth::id(),
                        ]
                    );
                }

                RuntimeUserLimitOverride::query()->get()->each(function (RuntimeUserLimitOverride $entry) use ($keys): void {
                    $composite = "{$entry->user_id}|{$entry->resource_key}|{$entry->period}";
                    if (!in_array($composite, $keys, true)) {
                        $entry->delete();
                    }
                });

                $runtimeControlService->bumpVersion(Auth::id());
            });

            AuditLogService::log('runtime_user_overrides_updated', RuntimeUserLimitOverride::class, null, [
                'updated_by' => Auth::id(),
            ]);

            return redirect()->route('settings.runtime-control')->with('success', __('User overrides updated successfully.'));
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'RuntimeControlController -> saveUserOverrides', 'Error Occurred', false);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    private function decodeJsonInput(string $payload, string $field): mixed
    {
        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            ResponseService::validationError("Polje {$field} nema validan JSON format.");
        }
    }

    private function toPrettyJson(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    private function nullableDate(mixed $value): ?string
    {
        $str = $this->nullableString($value);
        if ($str === null) {
            return null;
        }

        try {
            return Carbon::parse($str)->toDateTimeString();
        } catch (Throwable) {
            ResponseService::validationError('Jedan od datuma nije validan (očekivan je ISO format).');
        }
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s*,\s*/', $value) ?: [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($entry) => trim((string) $entry),
            $value
        )));
    }

    private function normalizeNumericArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s*,\s*/', $value) ?: [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $entry) {
            if (!is_numeric($entry)) {
                continue;
            }
            $id = (int) $entry;
            if ($id > 0) {
                $values[] = $id;
            }
        }

        return array_values(array_unique($values));
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
