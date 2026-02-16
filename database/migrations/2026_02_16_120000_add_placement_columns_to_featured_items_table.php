<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('featured_items', 'placement')) {
            Schema::table('featured_items', function (Blueprint $table) {
                $table
                    ->enum('placement', ['category', 'home', 'category_home'])
                    ->default('category_home')
                    ->after('package_id');
            });
        }

        if (! Schema::hasColumn('featured_items', 'positions')) {
            Schema::table('featured_items', function (Blueprint $table) {
                $table->string('positions', 64)->nullable()->after('placement');
            });
        }

        if (! Schema::hasColumn('featured_items', 'duration_days')) {
            Schema::table('featured_items', function (Blueprint $table) {
                $table->unsignedInteger('duration_days')->nullable()->after('end_date');
            });
        }

        if (Schema::hasColumn('featured_items', 'placement')) {
            DB::table('featured_items')
                ->whereNull('placement')
                ->update(['placement' => 'category_home']);
        }

        if (Schema::hasColumn('featured_items', 'positions')) {
            DB::table('featured_items')
                ->whereNull('positions')
                ->update(['positions' => 'category_home']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('featured_items', 'duration_days')) {
            Schema::table('featured_items', function (Blueprint $table) {
                $table->dropColumn('duration_days');
            });
        }

        if (Schema::hasColumn('featured_items', 'positions')) {
            Schema::table('featured_items', function (Blueprint $table) {
                $table->dropColumn('positions');
            });
        }

        if (Schema::hasColumn('featured_items', 'placement')) {
            Schema::table('featured_items', function (Blueprint $table) {
                $table->dropColumn('placement');
            });
        }
    }
};

