<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ItemContactEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id', 'user_id', 'visitor_id',
        'contact_type', 'device_type', 'source',
    ];

    // ═══════════════════════════════════════════
    // TIPOVI KONTAKTA
    // ═══════════════════════════════════════════

    const TYPE_PHONE_REVEAL = 'phone_reveal';      // Prikaži broj
    const TYPE_PHONE_CLICK = 'phone_click';        // Klik na pozovi
    const TYPE_PHONE_CALL = 'phone_call';          // Direktan poziv (tel: link)
    const TYPE_WHATSAPP = 'whatsapp';
    const TYPE_VIBER = 'viber';
    const TYPE_TELEGRAM = 'telegram';
    const TYPE_EMAIL = 'email';
    const TYPE_MESSAGE = 'message';                // Chat poruka
    const TYPE_OFFER = 'offer';                    // Ponuda

    public static function getAllTypes(): array
    {
        return [
            self::TYPE_PHONE_REVEAL,
            self::TYPE_PHONE_CLICK,
            self::TYPE_PHONE_CALL,
            self::TYPE_WHATSAPP,
            self::TYPE_VIBER,
            self::TYPE_TELEGRAM,
            self::TYPE_EMAIL,
            self::TYPE_MESSAGE,
            self::TYPE_OFFER,
        ];
    }

    public static function getTypeLabels(): array
    {
        return [
            self::TYPE_PHONE_REVEAL => 'Prikaži telefon',
            self::TYPE_PHONE_CLICK => 'Klik na poziv',
            self::TYPE_PHONE_CALL => 'Direktan poziv',
            self::TYPE_WHATSAPP => 'WhatsApp',
            self::TYPE_VIBER => 'Viber',
            self::TYPE_TELEGRAM => 'Telegram',
            self::TYPE_EMAIL => 'Email',
            self::TYPE_MESSAGE => 'Poruka',
            self::TYPE_OFFER => 'Ponuda',
        ];
    }

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
    // BILJEŽENJE KONTAKTA
    // ═══════════════════════════════════════════

    /**
     * Zabilježi kontakt event
     */
    public static function record(int $itemId, string $contactType, array $data = []): self
    {
        $event = self::create([
            'item_id' => $itemId,
            'user_id' => $data['user_id'] ?? auth()->id(),
            'visitor_id' => $data['visitor_id'] ?? null,
            'contact_type' => $contactType,
            'device_type' => $data['device_type'] ?? null,
            'source' => $data['source'] ?? null,
        ]);

        // Ažuriraj dnevnu statistiku
        $statMap = [
            self::TYPE_PHONE_REVEAL => 'phone_reveals',
            self::TYPE_PHONE_CLICK => 'phone_clicks',
            self::TYPE_WHATSAPP => 'whatsapp_clicks',
            self::TYPE_VIBER => 'viber_clicks',
            self::TYPE_EMAIL => 'email_clicks',
            self::TYPE_MESSAGE => 'messages_started',
            self::TYPE_OFFER => 'offers_received',
        ];

        if (isset($statMap[$contactType])) {
            ItemStatistic::incrementStat($itemId, $statMap[$contactType]);
        }

        // Ažuriraj items tabelu
        $itemColumnMap = [
            self::TYPE_PHONE_REVEAL => 'total_phone_clicks',
            self::TYPE_PHONE_CLICK => 'total_phone_clicks',
            self::TYPE_WHATSAPP => 'total_whatsapp_clicks',
            self::TYPE_MESSAGE => 'total_messages',
            self::TYPE_OFFER => 'total_offers_received',
        ];

        if (isset($itemColumnMap[$contactType])) {
            Item::where('id', $itemId)->increment($itemColumnMap[$contactType]);
        }

        return $event;
    }

    // ═══════════════════════════════════════════
    // CONVENIENCE METODE
    // ═══════════════════════════════════════════

    public static function recordPhoneReveal(int $itemId, array $data = []): self
    {
        return self::record($itemId, self::TYPE_PHONE_REVEAL, $data);
    }

    public static function recordPhoneClick(int $itemId, array $data = []): self
    {
        return self::record($itemId, self::TYPE_PHONE_CLICK, $data);
    }

    public static function recordWhatsApp(int $itemId, array $data = []): self
    {
        return self::record($itemId, self::TYPE_WHATSAPP, $data);
    }

    public static function recordViber(int $itemId, array $data = []): self
    {
        return self::record($itemId, self::TYPE_VIBER, $data);
    }

    public static function recordMessage(int $itemId, array $data = []): self
    {
        return self::record($itemId, self::TYPE_MESSAGE, $data);
    }

    public static function recordOffer(int $itemId, array $data = []): self
    {
        return self::record($itemId, self::TYPE_OFFER, $data);
    }

    // ═══════════════════════════════════════════
    // STATISTIKA
    // ═══════════════════════════════════════════

    /**
     * Dohvati statistiku kontakata za oglas
     */
    public static function getContactStats(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        // Po tipovima
        $byType = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('contact_type, COUNT(*) as count')
            ->groupBy('contact_type')
            ->pluck('count', 'contact_type')
            ->toArray();

        $types = [];
        $total = 0;
        foreach (self::getTypeLabels() as $key => $label) {
            $count = (int)($byType[$key] ?? 0);
            $total += $count;
            $types[] = [
                'type' => $key,
                'label' => $label,
                'count' => $count,
            ];
        }

        // Dodaj procente
        foreach ($types as &$type) {
            $type['percent'] = $total > 0 ? round(($type['count'] / $total) * 100, 1) : 0;
        }

        // Sortiraj po broju
        usort($types, fn($a, $b) => $b['count'] <=> $a['count']);

        // Po danima
        $daily = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as contacts')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('contacts', 'date')
            ->toArray();

        // Po satima (za heat map)
        $hourly = self::where('item_id', $itemId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as contacts')
            ->groupBy('hour')
            ->pluck('contacts', 'hour')
            ->toArray();

        // Conversion rate (kontakti / pregledi)
        $item = Item::find($itemId);
        $views = $item->clicks ?? 1;
        $conversionRate = round(($total / max($views, 1)) * 100, 2);

        return [
            'total_contacts' => $total,
            'by_type' => $types,
            'daily' => $daily,
            'hourly' => $hourly,
            'conversion_rate' => $conversionRate,
            'most_used' => collect($types)->first(),
            'avg_per_day' => $days > 0 ? round($total / $days, 1) : 0,
        ];
    }

    /**
     * Dohvati funnel statistiku (pregledi -> kontakti -> konverzija)
     */
    public static function getFunnelStats(int $itemId, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        $item = Item::with('favourites')->find($itemId);
        
        // Dohvati statistike
        $periodStats = ItemStatistic::where('item_id', $itemId)
            ->where('date', '>=', $startDate)
            ->selectRaw('
                SUM(views) as views,
                SUM(unique_views) as unique_views,
                SUM(phone_reveals) as phone_reveals,
                SUM(phone_clicks) as phone_clicks,
                SUM(messages_started) as messages,
                SUM(offers_received) as offers
            ')
            ->first();

        $views = (int)($periodStats->views ?? 0);
        $uniqueViews = (int)($periodStats->unique_views ?? 0);
        $phoneReveals = (int)($periodStats->phone_reveals ?? 0);
        $phoneClicks = (int)($periodStats->phone_clicks ?? 0);
        $messages = (int)($periodStats->messages ?? 0);
        $offers = (int)($periodStats->offers ?? 0);

        $totalContacts = $phoneClicks + $messages + $offers;

        return [
            'funnel' => [
                [
                    'stage' => 'Pregledi',
                    'value' => $views,
                    'percent' => 100,
                ],
                [
                    'stage' => 'Jedinstveni posjetioci',
                    'value' => $uniqueViews,
                    'percent' => $views > 0 ? round(($uniqueViews / $views) * 100, 1) : 0,
                ],
                [
                    'stage' => 'Prikazali telefon',
                    'value' => $phoneReveals,
                    'percent' => $uniqueViews > 0 ? round(($phoneReveals / $uniqueViews) * 100, 1) : 0,
                ],
                [
                    'stage' => 'Kontaktirali',
                    'value' => $totalContacts,
                    'percent' => $uniqueViews > 0 ? round(($totalContacts / $uniqueViews) * 100, 1) : 0,
                ],
            ],
            'conversion_rate' => $views > 0 ? round(($totalContacts / $views) * 100, 2) : 0,
            'contact_breakdown' => [
                'phone' => $phoneClicks,
                'messages' => $messages,
                'offers' => $offers,
            ],
        ];
    }
}
