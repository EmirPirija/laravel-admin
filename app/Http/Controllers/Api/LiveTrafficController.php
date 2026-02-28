<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteLiveSession;
use App\Models\SitePageEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class LiveTrafficController extends Controller
{
    public function track(Request $request)
    {
        $validated = $request->validate([
            'visitor_id' => 'nullable|string|max:100',
            'session_id' => 'nullable|string|max:100',
            'event_type' => 'nullable|in:view,heartbeat',
            'page_path' => 'required|string|max:500',
            'page_url' => 'nullable|string|max:1200',
            'page_title' => 'nullable|string|max:255',
            'referrer_url' => 'nullable|string|max:1200',
            'device_type' => 'nullable|string|max:30',
            'meta' => 'nullable|array',
        ]);

        try {
            $visitorId = $this->resolveVisitorId($request, $validated['visitor_id'] ?? null);
            $sessionId = $this->resolveSessionId($request, $validated['session_id'] ?? null, $visitorId);
            $eventType = $validated['event_type'] ?? 'heartbeat';
            $now = now();

            $userId = optional($request->user('sanctum'))->id
                ?: optional($request->user())->id;

            $session = SiteLiveSession::firstOrNew([
                'visitor_id' => $visitorId,
                'session_id' => $sessionId,
            ]);

            if (empty($session->first_seen_at)) {
                $session->first_seen_at = $now;
            }

            $session->fill([
                'user_id' => $userId,
                'page_path' => $validated['page_path'],
                'page_url' => $validated['page_url'] ?? null,
                'page_title' => $validated['page_title'] ?? null,
                'referrer_url' => $validated['referrer_url'] ?? null,
                'device_type' => $validated['device_type'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'last_seen_at' => $now,
                'meta' => $validated['meta'] ?? null,
            ]);

            $session->heartbeat_count = (int) ($session->heartbeat_count ?? 0) + 1;
            $session->save();

            if ($eventType === 'view') {
                SitePageEvent::create([
                    'visitor_id' => $visitorId,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'event_type' => $eventType,
                    'page_path' => $validated['page_path'],
                    'page_url' => $validated['page_url'] ?? null,
                    'page_title' => $validated['page_title'] ?? null,
                    'referrer_url' => $validated['referrer_url'] ?? null,
                    'device_type' => $validated['device_type'] ?? null,
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                    'meta' => $validated['meta'] ?? null,
                    'occurred_at' => $now,
                ]);
            }

            $onlineNow = SiteLiveSession::query()
                ->where('last_seen_at', '>=', now()->subMinutes(2))
                ->count();

            return response()->json([
                'error' => false,
                'message' => __('Live session tracked'),
                'data' => [
                    'online_now' => $onlineNow,
                ],
            ]);
        } catch (Throwable $th) {
            report($th);
            return response()->json([
                'error' => true,
                'message' => __('Greška prilikom praćenja live sesije.'),
            ], 500);
        }
    }

    private function resolveVisitorId(Request $request, ?string $visitorId): string
    {
        if (!empty($visitorId)) {
            return Str::limit(trim($visitorId), 100, '');
        }

        $seed = implode('|', [
            'fallback-visitor',
            (string) $request->ip(),
            (string) $request->userAgent(),
        ]);

        return 'vf_' . substr(hash('sha256', $seed), 0, 48);
    }

    private function resolveSessionId(Request $request, ?string $sessionId, string $visitorId): string
    {
        if (!empty($sessionId)) {
            return Str::limit(trim($sessionId), 100, '');
        }

        $seed = implode('|', [
            'fallback-session',
            $visitorId,
            now()->format('YmdHi'),
        ]);

        return 'sf_' . substr(hash('sha256', $seed), 0, 48);
    }
}

