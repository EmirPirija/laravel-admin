<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class FrontendControlPlaneService
{
    private const CACHE_KEY = 'frontend_control_plane:v1';
    private const CACHE_TTL_SECONDS = 20;

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $settings = CachingService::getSystemSettings([
                'system_version',
                'maintenance_mode',
                'force_update',
                'show_landing_page',
                'mobile_authentication',
                'google_authentication',
                'email_authentication',
                'apple_authentication',
                'apple_authenticaion',
                'realtime_events_enabled',
                'push_notifications_enabled',
                'live_tracking_enabled',
                'engagement_tracking_enabled',
                'frontend_observability_enabled',
                'company_name',
                'web_theme_color',
            ]);

            $controls = [
                'maintenance_mode' => $this->asInt($settings['maintenance_mode'] ?? 0),
                'force_update' => $this->asInt($settings['force_update'] ?? 0),
                'show_landing_page' => $this->asInt($settings['show_landing_page'] ?? 0),
                'auth' => [
                    'mobile_authentication' => $this->asInt($settings['mobile_authentication'] ?? 1),
                    'google_authentication' => $this->asInt($settings['google_authentication'] ?? 1),
                    'email_authentication' => $this->asInt($settings['email_authentication'] ?? 1),
                    'apple_authentication' => $this->asInt(
                        $settings['apple_authentication']
                            ?? ($settings['apple_authenticaion'] ?? 0)
                    ),
                ],
                'features' => [
                    'realtime_events_enabled' => $this->asInt($settings['realtime_events_enabled'] ?? 1),
                    'push_notifications_enabled' => $this->asInt($settings['push_notifications_enabled'] ?? 1),
                    'live_tracking_enabled' => $this->asInt($settings['live_tracking_enabled'] ?? 1),
                    'engagement_tracking_enabled' => $this->asInt($settings['engagement_tracking_enabled'] ?? 1),
                    'frontend_observability_enabled' => $this->asInt($settings['frontend_observability_enabled'] ?? 1),
                ],
                'branding' => [
                    'company_name' => (string) ($settings['company_name'] ?? ''),
                    'web_theme_color' => (string) ($settings['web_theme_color'] ?? ''),
                ],
            ];

            return [
                'version' => substr(hash('sha256', json_encode($controls)), 0, 16),
                'generated_at' => now()->toIso8601String(),
                'system_version' => (string) ($settings['system_version'] ?? ''),
                'controls' => $controls,
            ];
        });
    }

    private function asInt($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int) ((int) $value === 1);
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}

