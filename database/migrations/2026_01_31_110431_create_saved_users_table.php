<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('saved_users', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();        // ko čuva
      $table->foreignId('saved_user_id')->constrained('users')->cascadeOnDelete(); // koga čuva
      $table->timestamps();

      $table->unique(['user_id', 'saved_user_id']);
      $table->index('saved_user_id');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('saved_users');
  }
};
