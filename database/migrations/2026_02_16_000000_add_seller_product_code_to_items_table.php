<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'seller_product_code')) {
                $table->string('seller_product_code', 100)
                    ->nullable()
                    ->after('inventory_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'seller_product_code')) {
                $table->dropColumn('seller_product_code');
            }
        });
    }
};

