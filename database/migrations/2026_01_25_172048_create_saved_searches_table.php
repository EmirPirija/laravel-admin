<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('context', 50)->default('ads'); // npr. ads, jobs, itd
            $table->string('name', 120);
            $table->text('query_string')->nullable(); // npr. "category=...&min_price=..."
            $table->string('query_hash', 64); // sha256(user_id|context|query_string)

            $table->timestamps();

            $table->index(['user_id', 'context']);
            $table->unique(['user_id', 'context', 'query_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
