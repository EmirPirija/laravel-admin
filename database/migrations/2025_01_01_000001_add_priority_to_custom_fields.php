<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Dodaje 'priority' kolonu u custom_fields tabelu
     * Niži broj = viši prioritet (1 = prikaži prvi)
     */
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->integer('priority')->default(100)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};