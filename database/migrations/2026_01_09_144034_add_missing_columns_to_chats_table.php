<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            if (!Schema::hasColumn('chats', 'message_type')) {
                $table->string('message_type')->default('text')->after('message');
            }
            if (!Schema::hasColumn('chats', 'status')) {
                $table->string('status')->default('sent')->after('is_read');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['message_type', 'status']);
        });
    }
};