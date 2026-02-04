<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('platform'); // facebook, instagram
            $table->string('platform_user_id')->nullable();
            $table->string('account_name')->nullable();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->string('page_id')->nullable(); // Facebook Page ID
            $table->text('page_access_token')->nullable();

            $table->string('instagram_account_id')->nullable(); // Instagram Business Account ID

            $table->boolean('has_shop_access')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable(); // Additional platform-specific data

            $table->timestamps();

            $table->unique(['user_id', 'platform']);
            $table->index(['platform', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
