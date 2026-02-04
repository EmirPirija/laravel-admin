<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_settings', 'vacation_start_date')) {
                $table->date('vacation_start_date')->nullable()->after('vacation_message');
            }
            if (!Schema::hasColumn('seller_settings', 'vacation_end_date')) {
                $table->date('vacation_end_date')->nullable()->after('vacation_start_date');
            }
            if (!Schema::hasColumn('seller_settings', 'vacation_auto_activate')) {
                $table->boolean('vacation_auto_activate')->default(false)->after('vacation_end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_settings', function (Blueprint $table) {
            $table->dropColumn([
                'vacation_start_date',
                'vacation_end_date',
                'vacation_auto_activate',
            ]);
        });
    }
};
