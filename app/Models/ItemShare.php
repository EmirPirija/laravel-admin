<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ItemShare extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id', 'user_id', 'visitor_id', 'platform', 'share_url',
        'share_token', 'clicks_from_share', 'first_click_at', 'last_click_at',
        'device_type', 'country_code',
    ];

    protected $casts = [
        'first_click_at' => 'datetime',
        'last_click_at' => 'datetime',
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
    // PLATFORME
    // ═══════════════════════════════════════════

    const PLATFORM_FACEBOOK = 'facebook';
    const PLATFORM_MESSENGER = 'messenger';
    const PLATFORM_INSTAGRAM = 'instagram';
    const PLATFORM_WHATSAPP = 'whatsapp';
    const PLATFORM_VIBER = 'viber';
    const PLATFORM_TELEGRAM = 'telegram';
    const PLATFORM_TWITTER = 'twitter';
    const PLATFORM_LINKEDIN = 'linkedin';
    const PLATFORM_EMAIL = 'email';
    const PLATFORM_SMS = 'sms';
    const PLATFORM_COPY_LINK = 'copy_link';
    const PLATFORM_QR_CODE = 'qr_code';
    const PLATFORM_PRINT = 'print';
    const PLATFORM_NATIVE = 'native'; // Web Share API

    public static function getAllPlatforms(): array
    {
        return [
            self::PLATFORM_FACEBOOK,
            self::PLATFORM_MESSENGER,
            self::PLATFORM_INSTAGRAM,
            self::PLATFORM_WHATSAPP,
            self::PLATFORM_VIBER,
            self::PLATFORM_TELEGRAM,
            self::PLATFORM_TWITTER,
            self::PLATFORM_LINKEDIN,
            self::PLATFORM_EMAIL,
            self::PLATFORM_SMS,
            self::PLATFORM_COPY_LINK,
            self::PLATFORM_QR_CODE,
            self::PLATFORM_PRINT,
            self::PLATFORM_NATIVE,
        ];
    }

    public static function getPlatformLabels(): array
    {
        return [
            self::PLATFORM_FACEBOOK => 'Facebook',
            self::PLATFORM_MESSENGER => 'Messenger',
            self::PLATFORM_INSTAGRAM => 'Instagram',
            self::PLATFORM_WHATSAPP => 'WhatsApp',
            self::PLATFORM_VIBER => 'Viber',
            self::PLATFORM_TELEGRAM => 'Telegram',
            self::PLATFORM_TWITTER => 'Twitter/X',
            self::PLATFORM_LINKEDIN => 'LinkedIn',
            self::PLATFORM_EMAIL => 'Email',
            self::PLATFORM_SMS => 'SMS',
            self::PLATFORM_COPY_LINK => 'Kopiraj link',
            self::PLATFORM_QR_CODE => 'QR Kod',
            self::PLATFORM_PRINT => 'Print',
            self::PLATFORM_NATIVE => 'Dijeli',
        ];
    }

    // ═══════════════════════════════════════════
    // KREIRANJE DIJELJENJA
    // ═══════════════════════════════════════════

    /**
     * Zabilježi dijeljenje
     */
    public static function recordShare(int $itemId, string $platform, array $data = []): self
    {
        $share = self::create([
            'item_id' => $itemId,
            'user_id' => $data['user_id'] ?? auth()->id(),
            'visitor_id' => $data['visitor_id'] ?? null,
            'platform' => $platform,
            'share_url' => $data['share_url'] ?? null,
            'share_token' => self::generateShareToken(),
            'device_type' => $data['device_type'] ?? null,
            'country_code' => $data['country_code'] ?? null,
        ]);

        // Ažuriraj dnevnu statistiku
        ItemStatistic::incrementStat($itemId, 'shares_total');
        ItemStatistic::incrementStat($itemId, 'share_' . $platform);

        // Ažuriraj ukupan broj na items tabeli
        Item::where('id', $itemId)->increment('total_shares');

        return $share;
    }

    /**
     * Generiši jedinstveni token za praćenje
     */
    public static function generateShareToken(): string
    {
        do {
            $token = Str::random(32);
        } while (self::where('share_token', $token)->exists());

        return $token;
    }

    /**
     * Zabilježi klik sa dijeljenog linka
     */
    public function recordClick(): void
    {
        $now = Carbon::now();

        $this->increment('clicks_from_share');
        
        $updates = ['last_click_at' => $now];
        if (!$this->first_click_at) {
            $updates['first_click_at'] = $now;
        }
        
        $this->update($updates);
    }

    /**
     * Dohvati dijeljenje po tokenu
     */
    public static function findByToken(string $token): ?self
    {
        return self::where('share_token', $token)->first();
    }

    // ═══════════════════════════════════════════
    // STATISTIKA
    // ═══════════════════════════════════════════

    /**
     * Dohvati statistiku dijeljenja za oglas
     */
    public static function getShareStats(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        // Po platformama
        $byPlatform = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('platform, COUNT(*) as shares, SUM(clicks_from_share) as clicks')
            ->groupBy('platform')
            ->get()
            ->keyBy('platform');

        $platforms = [];
        foreach (self::getPlatformLabels() as $key => $label) {
            $data = $byPlatform->get($key);
            $shares = (int)($data->shares ?? 0);
            $clicks = (int)($data->clicks ?? 0);
            
            $platforms[] = [
                'platform' => $key,
                'label' => $label,
                'shares' => $shares,
                'clicks' => $clicks,
                'ctr' => $shares > 0 ? round(($clicks / $shares) * 100, 1) : 0,
            ];
        }

        // Sortiraj po broju dijeljenja
        usort($platforms, fn($a, $b) => $b['shares'] <=> $a['shares']);

        // Ukupno
        $totals = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('COUNT(*) as total_shares, SUM(clicks_from_share) as total_clicks')
            ->first();

        $totalShares = (int)($totals->total_shares ?? 0);
        $totalClicks = (int)($totals->total_clicks ?? 0);

        // Po danima
        $daily = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as shares')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('shares', 'date')
            ->toArray();

        return [
            'total_shares' => $totalShares,
            'total_clicks_from_shares' => $totalClicks,
            'overall_ctr' => $totalShares > 0 ? round(($totalClicks / $totalShares) * 100, 1) : 0,
            'by_platform' => $platforms,
            'daily' => $daily,
            'most_effective' => collect($platforms)->sortByDesc('ctr')->first(),
            'most_shared' => collect($platforms)->sortByDesc('shares')->first(),
        ];
    }

    /**
     * Dohvati viralnost (koliko su dijeljeni linkovi generirale novih pregleda)
     */
    public static function getViralityScore(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        $stats = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_shares,
                SUM(clicks_from_share) as total_clicks,
                COUNT(CASE WHEN clicks_from_share > 0 THEN 1 END) as effective_shares
            ')
            ->first();

        $totalShares = (int)($stats->total_shares ?? 0);
        $totalClicks = (int)($stats->total_clicks ?? 0);
        $effectiveShares = (int)($stats->effective_shares ?? 0);

        // Virality score: kombinacija CTR-a i efektivnosti
        $ctr = $totalShares > 0 ? ($totalClicks / $totalShares) : 0;
        $effectiveness = $totalShares > 0 ? ($effectiveShares / $totalShares) : 0;
        $avgClicksPerShare = $totalShares > 0 ? ($totalClicks / $totalShares) : 0;

        // Score od 0-100
        $viralityScore = min(100, round(($ctr * 50) + ($effectiveness * 30) + (min($avgClicksPerShare, 5) * 4)));

        return [
            'score' => $viralityScore,
            'label' => self::getViralityLabel($viralityScore),
            'total_shares' => $totalShares,
            'effective_shares' => $effectiveShares,
            'total_reach' => $totalClicks,
            'avg_reach_per_share' => round($avgClicksPerShare, 2),
        ];
    }

    private static function getViralityLabel(int $score): string
    {
        return match(true) {
            $score >= 80 => 'Viralan 🔥',
            $score >= 60 => 'Odličan',
            $score >= 40 => 'Dobar',
            $score >= 20 => 'Prosječan',
            default => 'Nizak',
        };
    }
}
