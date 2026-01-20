<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Badges tabela
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable(); // URL slike
            $table->string('type')->default('achievement'); // achievement, milestone, special
            $table->json('criteria')->nullable(); // Uslovi za dobijanje bedža
            $table->integer('points')->default(0); // Koliko points daje bedž
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // User badges (many-to-many)
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('badge_id')->constrained()->onDelete('cascade');
            $table->timestamp('earned_at')->useCurrent();
            $table->timestamps();
            
            $table->unique(['user_id', 'badge_id']);
        });

        // Points tabela
        Schema::create('user_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->unique();
            $table->integer('total_points')->default(0);
            $table->integer('level')->default(1);
            $table->string('level_name')->nullable();
            $table->integer('points_to_next_level')->default(100);
            $table->integer('current_level_points')->default(0);
            $table->timestamps();
        });

        // Points history
        Schema::create('points_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('points');
            $table->string('action'); // 'item_sold', 'item_posted', 'review_received', etc.
            $table->text('description')->nullable();
            $table->string('reference_type')->nullable(); // 'item', 'review', etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('points_history');
        Schema::dropIfExists('user_points');
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('badges');
    }
};
