<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;
use App\Services\AuthEventService;
use App\Services\ResponseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

trait HandlesAuthIdentity
{
    private function normalizePhoneDigits($value): string
    {
        return preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
    }

    private function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) ($email ?? '')));
    }

    private function normalizePhoneInput(?string $countryCode, ?string $mobile): array
    {
        $normalizedCountry = $this->normalizePhoneDigits($countryCode);
        $normalizedMobile = $this->normalizePhoneDigits($mobile);

        if ($normalizedMobile === '') {
            return [
                'country' => $normalizedCountry,
                'mobile' => '',
                'full' => '',
            ];
        }

        $mobilePart = $normalizedMobile;
        if ($normalizedCountry !== '' && Str::startsWith($normalizedMobile, $normalizedCountry)) {
            $mobilePart = substr($normalizedMobile, strlen($normalizedCountry)) ?: $normalizedMobile;
        }

        $full = $normalizedCountry !== ''
            ? (Str::startsWith($normalizedMobile, $normalizedCountry) ? $normalizedMobile : $normalizedCountry.$mobilePart)
            : $normalizedMobile;

        return [
            'country' => $normalizedCountry,
            'mobile' => $mobilePart,
            'full' => $full,
        ];
    }

    private function normalizedUserPhone(User $user): string
    {
        $mobile = $this->normalizePhoneDigits($user->mobile);
        $country = $this->normalizePhoneDigits($user->country_code);

        if ($mobile === '') {
            return '';
        }

        if ($country !== '' && Str::startsWith($mobile, $country)) {
            return $mobile;
        }

        return $country !== '' ? $country.$mobile : $mobile;
    }

    private function findPhoneConflict(
        ?string $countryCode,
        ?string $mobile,
        ?int $excludeUserId = null,
        bool $onlyVerified = false,
        bool $withTrashed = false
    ): ?User {
        $normalized = $this->normalizePhoneInput($countryCode, $mobile);
        if ($normalized['full'] === '') {
            return null;
        }

        $mobileCandidates = array_values(array_unique(array_filter([
            $normalized['mobile'],
            $normalized['full'],
            '+'.$normalized['mobile'],
            '+'.$normalized['full'],
        ])));

        $countryCandidates = array_values(array_unique(array_filter([
            $normalized['country'],
            '+'.$normalized['country'],
        ])));

        $query = User::query();
        if ($withTrashed) {
            $query->withTrashed();
        }

        if ($excludeUserId) {
            $query->where('id', '!=', $excludeUserId);
        }

        if ($onlyVerified) {
            $query->whereNotNull('phone_verified_at');
        }

        $query->where(function ($outer) use ($mobileCandidates, $countryCandidates, $normalized) {
            if (! empty($mobileCandidates)) {
                $outer->whereIn('mobile', $mobileCandidates);
            }

            if ($normalized['mobile'] !== '' && ! empty($countryCandidates)) {
                $outer->orWhere(function ($withCountry) use ($normalized, $countryCandidates) {
                    $withCountry
                        ->where('mobile', $normalized['mobile'])
                        ->whereIn('country_code', $countryCandidates);
                });
            }
        });

        $candidates = $query->get();

        foreach ($candidates as $candidate) {
            if ($this->normalizedUserPhone($candidate) === $normalized['full']) {
                return $candidate;
            }
        }

        return null;
    }

    private function phoneNotRegisteredResponse(): void
    {
        AuthEventService::log('phone_not_registered', [
            'intent' => request()->input('intent', request()->input('auth_intent', 'login')),
            'endpoint' => request()->path(),
        ], 'warning', (string) (request()->input('identifier') ?? request()->input('number') ?? request()->input('mobile')));

        ResponseService::errorResponse(
            __('Broj telefona nije registrovan. Prvo kreirajte račun.'),
            ['reason' => 'phone_not_registered'],
            config('constants.RESPONSE_CODE.PHONE_NOT_REGISTERED'),
            null,
            404
        );
    }

    private function isUniqueConstraintViolation(Throwable $error): bool
    {
        if (!($error instanceof QueryException)) {
            return false;
        }

        $sqlState = (string) ($error->errorInfo[0] ?? '');
        $driverCode = (int) ($error->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062;
    }

    private function throwIdentityConflictFromException(QueryException $error): void
    {
        $raw = Str::lower((string) $error->getMessage());

        $isPhoneConflict = Str::contains($raw, [
            'users_phone_normalized_unique',
            'users_country_code_mobile_unique',
            'phone_normalized',
            'country_code',
            'mobile',
        ]);

        if ($isPhoneConflict) {
            ResponseService::conflictResponse(
                __('Broj telefona je već povezan s drugim računom.'),
                ['reason' => 'phone_already_registered'],
                config('constants.RESPONSE_CODE.CONFLICT')
            );
        }

        ResponseService::conflictResponse(
            __('E-mail adresa je već povezana s drugim računom.'),
            ['reason' => 'email_already_registered'],
            config('constants.RESPONSE_CODE.CONFLICT')
        );
    }
}
