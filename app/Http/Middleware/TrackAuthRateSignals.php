<?php

namespace App\Http\Middleware;

use App\Services\AuthEventService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class TrackAuthRateSignals
{
    public function handle(Request $request, Closure $next, int $maxAttempts = 20, int $decayMinutes = 1, string $eventKey = 'auth')
    {
        $identifier = (string) ($request->input('identifier')
            ?? $request->input('email')
            ?? $request->input('mobile')
            ?? $request->input('number')
            ?? '');

        $signature = implode('|', [
            $eventKey,
            $request->ip(),
            strtolower(trim($identifier)),
        ]);

        $tooManyAttempts = RateLimiter::tooManyAttempts($signature, $maxAttempts);
        $remaining = $tooManyAttempts ? 0 : RateLimiter::remaining($signature, $maxAttempts);

        if ($tooManyAttempts) {
            AuthEventService::log('rate_limit_hit', [
                'event_key' => $eventKey,
                'max_attempts' => $maxAttempts,
                'decay_minutes' => $decayMinutes,
            ], 'warning', $identifier);
        }

        RateLimiter::hit($signature, $decayMinutes * 60);

        $response = $next($request);
        $response->headers->set('X-RateSignal-Remaining', (string) $remaining);

        return $response;
    }
}
