<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_offers', function (Blueprint $table) {
            $table->json('deleted_at_by')->nullable()->after('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::table('item_offers', function (Blueprint $table) {
            $table->dropColumn('deleted_at_by');
        });
    }
};