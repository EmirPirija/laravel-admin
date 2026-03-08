<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'auto_watermark_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('auto_watermark_enabled');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'auto_watermark_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('auto_watermark_enabled')
                    ->default(true)
                    ->after('auto_approve_item');
            });
        }
    }
};

