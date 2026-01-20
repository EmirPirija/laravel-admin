<?php
// database/migrations/2026_01_19_000003_add_auto_reply_to_chats_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->boolean('is_auto_reply')->default(false)->after('status');
            $table->string('auto_reply_type')->nullable()->after('is_auto_reply'); // standard, vacation
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['is_auto_reply', 'auto_reply_type']);
        });
    }
};
