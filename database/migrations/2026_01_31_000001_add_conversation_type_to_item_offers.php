<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_offers', function (Blueprint $table) {
            if (!Schema::hasColumn('item_offers', 'conversation_type')) {
                $table->string('conversation_type', 20)->default('item')->index(); // item | direct
            }
        });

        // item_id mora biti nullable da direct chat bude moguć
        // (radi na MySQL; ako je druga baza, prilagodi)
        try {
            DB::statement('ALTER TABLE item_offers MODIFY item_id BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
            // ako već jest nullable ili baza drugačija, ignoriraj
        }

        // Unique za direct i item
        // (ako imaš postojeći unique koji smeta, ukloni ga ručno prije)
        Schema::table('item_offers', function (Blueprint $table) {
            $table->index(['conversation_type', 'item_id', 'buyer_id', 'seller_id'], 'idx_conversation_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('item_offers', function (Blueprint $table) {
            if (Schema::hasColumn('item_offers', 'conversation_type')) {
                $table->dropColumn('conversation_type');
            }
            $table->dropIndex('idx_conversation_lookup');
        });

        // vraćanje item_id NOT NULL je rizično ako već postoje direct rows,
        // zato ga ovdje namjerno ne vraćam nazad.
    }
};

