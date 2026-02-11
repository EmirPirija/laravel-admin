<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'available_now')) {
                $table->boolean('available_now')->default(false)->after('is_on_sale');
            }

            if (!Schema::hasColumn('items', 'exchange_possible')) {
                if (Schema::hasColumn('items', 'available_now')) {
                    $table->boolean('exchange_possible')->default(false)->after('available_now');
                } else {
                    $table->boolean('exchange_possible')->default(false);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'exchange_possible')) {
                $table->dropColumn('exchange_possible');
            }
        });
    }
};
