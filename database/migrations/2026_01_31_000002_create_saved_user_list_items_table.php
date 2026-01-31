<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('saved_user_list_items', function (Blueprint $table) {
      $table->id();
      $table->foreignId('list_id')->constrained('saved_user_lists')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // owner for faster querying
      $table->foreignId('saved_user_id')->constrained('users')->cascadeOnDelete();
      $table->text('note')->nullable(); // privatna bilješka
      $table->timestamp('last_viewed_at')->nullable();
      $table->timestamps();

      $table->unique(['list_id', 'saved_user_id']);
      $table->index(['user_id', 'saved_user_id']);
      $table->index(['user_id', 'created_at']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('saved_user_list_items');
  }
};
