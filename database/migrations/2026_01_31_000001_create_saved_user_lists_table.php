<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('saved_user_lists', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->string('name', 80);
      $table->boolean('is_default')->default(false);
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();

      $table->unique(['user_id', 'name']);
      $table->index(['user_id', 'sort_order']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('saved_user_lists');
  }
};
