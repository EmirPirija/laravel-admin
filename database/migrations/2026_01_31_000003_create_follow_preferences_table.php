<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('follow_preferences', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // follower
      $table->foreignId('followed_user_id')->constrained('users')->cascadeOnDelete(); // seller
      $table->enum('frequency', ['instant', 'daily', 'weekly'])->default('daily');
      $table->boolean('enabled')->default(true);
      $table->timestamp('last_checked_at')->nullable();
      $table->timestamp('last_notified_at')->nullable();
      $table->timestamps();

      $table->unique(['user_id', 'followed_user_id']);
      $table->index(['user_id', 'enabled']);
      $table->index(['followed_user_id', 'enabled']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('follow_preferences');
  }
};
