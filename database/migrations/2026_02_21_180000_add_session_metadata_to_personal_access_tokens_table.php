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
        if (!Schema::hasColumn('personal_access_tokens', 'device_name')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('device_name')->nullable()->after('name');
            });
        }

        if (!Schema::hasColumn('personal_access_tokens', 'platform')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('platform', 40)->nullable()->after('device_name');
            });
        }

        if (!Schema::hasColumn('personal_access_tokens', 'ip_address')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('ip_address', 45)->nullable()->after('platform');
            });
        }

        if (!Schema::hasColumn('personal_access_tokens', 'user_agent')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->text('user_agent')->nullable()->after('ip_address');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'user_agent')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('user_agent');
            });
        }

        if (Schema::hasColumn('personal_access_tokens', 'ip_address')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('ip_address');
            });
        }

        if (Schema::hasColumn('personal_access_tokens', 'platform')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('platform');
            });
        }

        if (Schema::hasColumn('personal_access_tokens', 'device_name')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('device_name');
            });
        }
    }
};

