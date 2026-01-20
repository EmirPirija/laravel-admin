<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ItemVisitorSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id', 'visitor_id', 'user_id', 'session_id',
        'started_at', 'ended_at', 'duration_seconds',
        // Izvor
        'source', 'source_detail', 'referrer_url', 'referrer_domain',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        // Uređaj
        'device_type', 'device_os', 'device_browser', 'is_app', 'app_version',
        // Lokacija
        'country_code', 'city', 'latitude', 'longitude',
        // Interakcije
        'page_views', 'viewed_gallery', 'viewed_video', 'clicked_phone',
        'clicked_message', 'clicked_share', 'added_favorite', 'made_offer',
        'clicked_map', 'clicked_seller', 'actions_log',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_app' => 'boolean',
        'viewed_gallery' => 'boolean',
        'viewed_video' => 'boolean',
        'clicked_phone' => 'boolean',
        'clicked_message' => 'boolean',
        'clicked_share' => 'boolean',
        'added_favorite' => 'boolean',
        'made_offer' => 'boolean',
        'clicked_map' => 'boolean',
        'clicked_seller' => 'boolean',
        'actions_log' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    // ═══════════════════════════════════════════
    // RELACIJE
    // ═══════════════════════════════════════════

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ═══════════════════════════════════════════
    // KREIRANJE SESIJE
    // ═══════════════════════════════════════════

    /**
     * Kreiraj ili nastavi sesiju
     */
    public static function startOrContinue(int $itemId, array $data): self
    {
        $visitorId = $data['visitor_id'] ?? self::generateVisitorId($data);
        $sessionId = $data['session_id'] ?? session()->getId() ?? Str::random(32);

        // Provjeri postoji li aktivna sesija (u zadnjih 30 minuta)
        $existingSession = self::where('item_id', $itemId)
            ->where('visitor_id', $visitorId)
            ->where('started_at', '>=', Carbon::now()->subMinutes(30))
            ->latest('started_at')
            ->first();

        if ($existingSession) {
            // Nastavi postojeću sesiju
            $existingSession->increment('page_views');
            $existingSession->touch();
            return $existingSession;
        }

        // Kreiraj novu sesiju
        return self::create([
            'item_id' => $itemId,
            'visitor_id' => $visitorId,
            'user_id' => $data['user_id'] ?? auth()->id(),
            'session_id' => $sessionId,
            'started_at' => Carbon::now(),
            'source' => $data['source'] ?? self::detectSource($data),
            'source_detail' => $data['source_detail'] ?? null,
            'referrer_url' => $data['referrer_url'] ?? null,
            'referrer_domain' => $data['referrer_domain'] ?? self::extractDomain($data['referrer_url'] ?? null),
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_content' => $data['utm_content'] ?? null,
            'device_type' => $data['device_type'] ?? self::detectDeviceType($data['user_agent'] ?? null),
            'device_os' => $data['device_os'] ?? self::detectOS($data['user_agent'] ?? null),
            'device_browser' => $data['device_browser'] ?? self::detectBrowser($data['user_agent'] ?? null),
            'is_app' => $data['is_app'] ?? false,
            'app_version' => $data['app_version'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'city' => $data['city'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'page_views' => 1,
            'actions_log' => [
                ['action' => 'view', 'timestamp' => Carbon::now()->toISOString()]
            ],
        ]);
    }

    /**
     * Završi sesiju
     */
    public function endSession(): void
    {
        $this->update([
            'ended_at' => Carbon::now(),
            'duration_seconds' => $this->started_at->diffInSeconds(Carbon::now()),
        ]);
    }

    /**
     * Zabilježi akciju u sesiji
     */
    public function logAction(string $action, array $extra = []): void
    {
        $log = $this->actions_log ?? [];
        $log[] = array_merge([
            'action' => $action,
            'timestamp' => Carbon::now()->toISOString(),
        ], $extra);

        $this->update(['actions_log' => $log]);

        // Ažuriraj boolean flagove
        $actionMap = [
            'gallery_open' => 'viewed_gallery',
            'video_play' => 'viewed_video',
            'phone_click' => 'clicked_phone',
            'message_send' => 'clicked_message',
            'share' => 'clicked_share',
            'favorite_add' => 'added_favorite',
            'offer_make' => 'made_offer',
            'map_open' => 'clicked_map',
            'seller_click' => 'clicked_seller',
        ];

        if (isset($actionMap[$action])) {
            $this->update([$actionMap[$action] => true]);
        }
    }

    // ═══════════════════════════════════════════
    // HELPER METODE
    // ═══════════════════════════════════════════

    /**
     * Generiši visitor ID
     */
    public static function generateVisitorId(array $data): string
    {
        $ip = $data['ip'] ?? request()->ip();
        $userAgent = $data['user_agent'] ?? request()->userAgent();
        return md5($ip . '_' . $userAgent);
    }

    /**
     * Detektuj izvor prometa
     */
    public static function detectSource(array $data): string
    {
        $referrer = $data['referrer_url'] ?? null;
        $utmSource = $data['utm_source'] ?? null;

        if ($utmSource) {
            return match(strtolower($utmSource)) {
                'google' => 'google_ads',
                'facebook', 'fb' => 'facebook',
                'instagram', 'ig' => 'instagram',
                default => 'external_campaign',
            };
        }

        if (!$referrer) {
            return 'direct';
        }

        $domain = self::extractDomain($referrer);
        
        // Interni izvori
        if (str_contains($domain, config('app.url')) || str_contains($domain, 'lmx.ba')) {
            if (str_contains($referrer, '/search') || str_contains($referrer, '?search=')) {
                return 'internal_search';
            }
            if (str_contains($referrer, '/category') || str_contains($referrer, '/ads/')) {
                return 'category_browse';
            }
            if (str_contains($referrer, '/seller') || str_contains($referrer, '/user/')) {
                return 'seller_profile';
            }
            if (str_contains($referrer, '/favorites')) {
                return 'favorites';
            }
            return 'internal_other';
        }

        // Eksterni izvori
        return match(true) {
            str_contains($domain, 'google') => 'google_organic',
            str_contains($domain, 'facebook') || str_contains($domain, 'fb.com') => 'facebook',
            str_contains($domain, 'instagram') => 'instagram',
            str_contains($domain, 't.co') || str_contains($domain, 'twitter') => 'twitter',
            str_contains($domain, 'viber') => 'viber',
            str_contains($domain, 'whatsapp') || str_contains($domain, 'wa.me') => 'whatsapp',
            str_contains($domain, 'linkedin') => 'linkedin',
            str_contains($domain, 'tiktok') => 'tiktok',
            str_contains($domain, 'youtube') => 'youtube',
            str_contains($domain, 't.me') || str_contains($domain, 'telegram') => 'telegram',
            default => 'other_external',
        };
    }

    /**
     * Ekstraktuj domenu iz URL-a
     */
    public static function extractDomain(?string $url): ?string
    {
        if (!$url) return null;
        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }

    /**
     * Detektuj tip uređaja
     */
    public static function detectDeviceType(?string $userAgent): string
    {
        if (!$userAgent) return 'unknown';
        $userAgent = strtolower($userAgent);

        if (str_contains($userAgent, 'tablet') || str_contains($userAgent, 'ipad')) {
            return 'tablet';
        }
        if (str_contains($userAgent, 'mobile') || str_contains($userAgent, 'android') || str_contains($userAgent, 'iphone')) {
            return 'mobile';
        }
        return 'desktop';
    }

    /**
     * Detektuj OS
     */
    public static function detectOS(?string $userAgent): ?string
    {
        if (!$userAgent) return null;
        $userAgent = strtolower($userAgent);

        return match(true) {
            str_contains($userAgent, 'windows') => 'Windows',
            str_contains($userAgent, 'macintosh') || str_contains($userAgent, 'mac os') => 'macOS',
            str_contains($userAgent, 'iphone') || str_contains($userAgent, 'ipad') => 'iOS',
            str_contains($userAgent, 'android') => 'Android',
            str_contains($userAgent, 'linux') => 'Linux',
            default => null,
        };
    }

    /**
     * Detektuj browser
     */
    public static function detectBrowser(?string $userAgent): ?string
    {
        if (!$userAgent) return null;
        $userAgent = strtolower($userAgent);

        return match(true) {
            str_contains($userAgent, 'edg/') => 'Edge',
            str_contains($userAgent, 'chrome') && !str_contains($userAgent, 'edg/') => 'Chrome',
            str_contains($userAgent, 'safari') && !str_contains($userAgent, 'chrome') => 'Safari',
            str_contains($userAgent, 'firefox') => 'Firefox',
            str_contains($userAgent, 'opera') || str_contains($userAgent, 'opr/') => 'Opera',
            default => null,
        };
    }

    // ═══════════════════════════════════════════
    // STATISTIKA
    // ═══════════════════════════════════════════

    /**
     * Dohvati engagement statistiku za oglas
     */
    public static function getEngagementStats(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        $stats = self::where('item_id', $itemId)
            ->where('started_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_sessions,
                COUNT(DISTINCT visitor_id) as unique_visitors,
                AVG(duration_seconds) as avg_duration,
                AVG(page_views) as avg_page_views,
                SUM(CASE WHEN viewed_gallery THEN 1 ELSE 0 END) as gallery_views,
                SUM(CASE WHEN viewed_video THEN 1 ELSE 0 END) as video_views,
                SUM(CASE WHEN clicked_phone THEN 1 ELSE 0 END) as phone_clicks,
                SUM(CASE WHEN clicked_message THEN 1 ELSE 0 END) as message_clicks,
                SUM(CASE WHEN clicked_share THEN 1 ELSE 0 END) as share_clicks,
                SUM(CASE WHEN added_favorite THEN 1 ELSE 0 END) as favorite_adds,
                SUM(CASE WHEN made_offer THEN 1 ELSE 0 END) as offers_made,
                SUM(CASE WHEN clicked_map THEN 1 ELSE 0 END) as map_clicks,
                SUM(CASE WHEN clicked_seller THEN 1 ELSE 0 END) as seller_clicks
            ')
            ->first();

        $totalSessions = (int)($stats->total_sessions ?? 0);

        return [
            'total_sessions' => $totalSessions,
            'unique_visitors' => (int)($stats->unique_visitors ?? 0),
            'avg_duration_seconds' => (int)($stats->avg_duration ?? 0),
            'avg_duration_formatted' => gmdate('i:s', (int)($stats->avg_duration ?? 0)),
            'avg_page_views' => round($stats->avg_page_views ?? 0, 1),
            'engagement_rates' => [
                'gallery' => $totalSessions > 0 ? round(($stats->gallery_views / $totalSessions) * 100, 1) : 0,
                'video' => $totalSessions > 0 ? round(($stats->video_views / $totalSessions) * 100, 1) : 0,
                'phone' => $totalSessions > 0 ? round(($stats->phone_clicks / $totalSessions) * 100, 1) : 0,
                'message' => $totalSessions > 0 ? round(($stats->message_clicks / $totalSessions) * 100, 1) : 0,
                'share' => $totalSessions > 0 ? round(($stats->share_clicks / $totalSessions) * 100, 1) : 0,
                'favorite' => $totalSessions > 0 ? round(($stats->favorite_adds / $totalSessions) * 100, 1) : 0,
                'offer' => $totalSessions > 0 ? round(($stats->offers_made / $totalSessions) * 100, 1) : 0,
            ],
        ];
    }
}
