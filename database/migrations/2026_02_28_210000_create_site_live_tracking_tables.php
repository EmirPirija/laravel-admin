<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('site_live_sessions')) {
            Schema::create('site_live_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('visitor_id', 100)->index();
                $table->string('session_id', 100)->index();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('page_path', 500)->index();
                $table->string('page_url', 1200)->nullable();
                $table->string('page_title', 255)->nullable();
                $table->string('referrer_url', 1200)->nullable();
                $table->string('device_type', 30)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->unsignedInteger('heartbeat_count')->default(0);
                $table->timestamp('first_seen_at')->nullable()->index();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['visitor_id', 'session_id'], 'site_live_sessions_unique_session');
                $table->index(['last_seen_at', 'page_path'], 'site_live_sessions_last_seen_page_idx');
            });
        }

        if (!Schema::hasTable('site_page_events')) {
            Schema::create('site_page_events', function (Blueprint $table) {
                $table->id();
                $table->string('visitor_id', 100)->index();
                $table->string('session_id', 100)->index();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_type', 20)->default('view')->index();
                $table->string('page_path', 500)->index();
                $table->string('page_url', 1200)->nullable();
                $table->string('page_title', 255)->nullable();
                $table->string('referrer_url', 1200)->nullable();
                $table->string('device_type', 30)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('occurred_at')->nullable()->index();
                $table->timestamps();

                $table->index(['occurred_at', 'event_type'], 'site_page_events_occurred_event_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_page_events');
        Schema::dropIfExists('site_live_sessions');
    }
};

