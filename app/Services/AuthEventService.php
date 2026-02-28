<?php

namespace App\Services;

use App\Models\AuthEventLog;
use Illuminate\Support\Facades\Auth;

class AuthEventService
{
    public static function log(string $eventType, array $meta = [], string $status = 'info', ?string $identifier = null, ?int $userId = null): void
    {
        try {
            AuthEventLog::create([
                'user_id' => $userId ?? Auth::id(),
                'event_type' => $eventType,
                'endpoint' => request()?->path(),
                'ip_address' => request()?->ip(),
                'identifier' => $identifier,
                'status' => $status,
                'meta' => $meta,
                'user_agent' => request()?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            logger()->warning('Auth event log write failed', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
