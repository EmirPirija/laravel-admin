<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('question');
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->onDelete('set null');
            $table->unsignedInteger('likes_count')->default(0);
            $table->boolean('is_reported')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->index(['item_id', 'created_at']);
            $table->index(['user_id']);
        });

        Schema::create('item_question_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('item_questions')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['question_id', 'user_id']);
        });

        Schema::create('item_question_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('item_questions')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['question_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_question_reports');
        Schema::dropIfExists('item_question_likes');
        Schema::dropIfExists('item_questions');
    }
};
