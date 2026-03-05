<?php

use App\Services\ListingCampaignBadgeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['name' => ListingCampaignBadgeService::SETTINGS_KEY_ENABLED],
            [
                'value' => '0',
                'type' => 'boolean',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('settings')->updateOrInsert(
            ['name' => ListingCampaignBadgeService::SETTINGS_KEY_OPTIONS],
            [
                'value' => json_encode([
                    ['key' => 'valentinovo', 'label' => 'Valentinovo'],
                    ['key' => '8-mart', 'label' => '8. mart'],
                    ['key' => 'ramazan', 'label' => 'Ramazan'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type' => 'string',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn('name', ListingCampaignBadgeService::settingsKeys())
            ->delete();
    }
};
