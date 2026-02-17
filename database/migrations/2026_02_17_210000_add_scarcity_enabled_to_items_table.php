<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('items', 'scarcity_enabled')) {
            Schema::table('items', function (Blueprint $table) {
                $table->boolean('scarcity_enabled')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('items', 'scarcity_enabled')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('scarcity_enabled');
            });
        }
    }
};

