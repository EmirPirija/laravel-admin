<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Str;

class ListingCampaignBadgeService
{
    public const SETTINGS_KEY_ENABLED = 'listing_campaign_badges_enabled';
    public const SETTINGS_KEY_OPTIONS = 'listing_campaign_badges';

    public static function settingsKeys(): array
    {
        return [
            self::SETTINGS_KEY_ENABLED,
            self::SETTINGS_KEY_OPTIONS,
        ];
    }

    public static function getConfig(): array
    {
        $rows = Setting::query()
            ->whereIn('name', self::settingsKeys())
            ->pluck('value', 'name')
            ->toArray();

        $options = self::parseOptions($rows[self::SETTINGS_KEY_OPTIONS] ?? []);
        $optionsByKey = [];

        foreach ($options as $option) {
            $optionsByKey[$option['key']] = $option;
        }

        return [
            'enabled' => self::toBoolean($rows[self::SETTINGS_KEY_ENABLED] ?? false),
            'options' => $options,
            'options_by_key' => $optionsByKey,
        ];
    }

    public static function resolveOptionByKey(?string $key, ?array $config = null): ?array
    {
        $normalizedKey = self::normalizeKey($key);
        if ($normalizedKey === '') {
            return null;
        }

        $resolvedConfig = $config ?? self::getConfig();

        return $resolvedConfig['options_by_key'][$normalizedKey] ?? null;
    }

    public static function parseOptions($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::normalizeOptions($decoded);
            }

            $fallback = preg_split('/[\r\n,;]+/', $raw) ?: [];
            return self::normalizeOptions($fallback);
        }

        if (!is_array($raw)) {
            return [];
        }

        return self::normalizeOptions($raw);
    }

    public static function normalizeKey(?string $value): string
    {
        $normalized = Str::slug((string) $value, '-');
        if ($normalized === '') {
            return '';
        }

        return Str::limit($normalized, 80, '');
    }

    private static function normalizeOptions(array $rawOptions): array
    {
        $options = [];
        $seen = [];

        $append = static function ($candidate, ?string $fallbackKey = null) use (&$options, &$seen): void {
            $key = '';
            $label = '';
            $bgColor = null;
            $textColor = null;

            if (is_string($candidate) || is_numeric($candidate)) {
                $label = trim((string) $candidate);
                $key = self::normalizeKey($fallbackKey ?: $label);
            } elseif (is_array($candidate)) {
                $rawLabel = $candidate['label']
                    ?? $candidate['name']
                    ?? $candidate['title']
                    ?? (is_string($fallbackKey) ? $fallbackKey : '');

                $label = trim((string) $rawLabel);
                $key = self::normalizeKey(
                    $candidate['key']
                        ?? $candidate['id']
                        ?? $fallbackKey
                        ?? $label
                );

                $bgColor = self::normalizeHexColor(
                    $candidate['bg_color']
                        ?? $candidate['background_color']
                        ?? null
                );

                $textColor = self::normalizeHexColor(
                    $candidate['text_color']
                        ?? $candidate['foreground_color']
                        ?? null
                );
            }

            if ($key === '' || $label === '' || isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $option = [
                'key' => $key,
                'label' => $label,
            ];

            if ($bgColor) {
                $option['bg_color'] = $bgColor;
            }
            if ($textColor) {
                $option['text_color'] = $textColor;
            }

            $options[] = $option;
        };

        if (!array_is_list($rawOptions)) {
            foreach ($rawOptions as $mapKey => $mapValue) {
                $append($mapValue, (string) $mapKey);
            }

            return $options;
        }

        foreach ($rawOptions as $entry) {
            $append($entry, null);
        }

        return $options;
    }

    private static function normalizeHexColor($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $trimmed)) {
            return null;
        }

        return strtoupper($trimmed);
    }

    private static function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
