<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'campaign_badge_key')) {
                $table->string('campaign_badge_key', 80)
                    ->nullable()
                    ->after('seller_product_code');
            }

            if (!Schema::hasColumn('items', 'campaign_badge_label')) {
                $table->string('campaign_badge_label', 120)
                    ->nullable()
                    ->after('campaign_badge_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'campaign_badge_label')) {
                $table->dropColumn('campaign_badge_label');
            }

            if (Schema::hasColumn('items', 'campaign_badge_key')) {
                $table->dropColumn('campaign_badge_key');
            }
        });
    }
};
