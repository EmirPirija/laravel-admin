<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('item_offers', 'status')) {
                $table->enum('status', ['pending', 'accepted', 'rejected', 'countered'])
                    ->default('pending')
                    ->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_offers', function (Blueprint $table) {
            if (Schema::hasColumn('item_offers', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
