<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seller_settings', function (Blueprint $table) {
            $table->json('card_preferences')->nullable()->after('vacation_auto_activate'); 
            // ^ "after" možeš promijeniti ili maknuti ako ne znaš tačno gdje želiš
        });
    }

    public function down(): void
    {
        Schema::table('seller_settings', function (Blueprint $table) {
            $table->dropColumn('card_preferences');
        });
    }
};

