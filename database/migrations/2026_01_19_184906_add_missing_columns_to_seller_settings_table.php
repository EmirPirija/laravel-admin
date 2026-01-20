<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_settings', function (Blueprint $table) {
            // Dodaj samo kolone koje fale!
            if (!Schema::hasColumn('seller_settings', 'business_hours')) {
                $table->json('business_hours')->nullable();
            }
            if (!Schema::hasColumn('seller_settings', 'social_facebook')) {
                $table->string('social_facebook')->nullable();
            }
            if (!Schema::hasColumn('seller_settings', 'social_instagram')) {
                $table->string('social_instagram')->nullable();
            }
            if (!Schema::hasColumn('seller_settings', 'social_tiktok')) {
                $table->string('social_tiktok')->nullable();
            }
            if (!Schema::hasColumn('seller_settings', 'social_youtube')) {
                $table->string('social_youtube')->nullable();
            }
            if (!Schema::hasColumn('seller_settings', 'social_website')) {
                $table->string('social_website')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_settings', function (Blueprint $table) {
            $table->dropColumn([
                'business_hours',
                'social_facebook', 
                'social_instagram',
                'social_tiktok',
                'social_youtube',
                'social_website'
            ]);
        });
    }
};
