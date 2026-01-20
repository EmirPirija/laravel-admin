<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_offers', function (Blueprint $table) {
            $table->json('archived_by')->nullable();
            $table->json('deleted_by')->nullable();
            $table->json('pinned_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('item_offers', function (Blueprint $table) {
            $table->dropColumn(['archived_by', 'deleted_by', 'pinned_by']);
        });
    }
};