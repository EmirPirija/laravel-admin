<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
         Schema::table('users', function (Blueprint $table) {
            $table->string('region_code')->nullable()->after('country_code');
        });

          Schema::table('items', function (Blueprint $table) {
            $table->string('region_code')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('region_code');
        });
         Schema::table('items', function (Blueprint $table) {
             $table->dropColumn('region_code');
        });
    }
};
