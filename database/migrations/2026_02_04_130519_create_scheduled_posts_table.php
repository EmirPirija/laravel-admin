<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('item_id')->constrained()->onDelete('cascade');

            $table->json('platforms'); // ['facebook', 'instagram']
            $table->text('caption')->nullable();
            $table->string('hashtags')->nullable();

            $table->timestamp('scheduled_at');
            $table->timestamp('published_at')->nullable();

            $table->enum('status', ['pending', 'processing', 'published', 'failed', 'cancelled'])
                ->default('pending');

            $table->text('error_message')->nullable();
            $table->json('platform_post_ids')->nullable(); // IDs of created posts per platform

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};
