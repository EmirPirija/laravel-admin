<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'auto_watermark_enabled')) {
                $table->boolean('auto_watermark_enabled')
                    ->default(true)
                    ->after('auto_approve_item');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'auto_watermark_enabled')) {
                $table->dropColumn('auto_watermark_enabled');
            }
        });
    }
};

