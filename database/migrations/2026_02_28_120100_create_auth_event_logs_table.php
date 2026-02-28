<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auth_event_logs', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 120)->index();
            $table->string('endpoint', 191)->nullable()->index();
            $table->string('ip_address', 64)->nullable()->index();
            $table->string('identifier', 191)->nullable()->index();
            $table->string('status', 40)->default('info')->index();
            $table->json('meta')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_event_logs');
    }
};
