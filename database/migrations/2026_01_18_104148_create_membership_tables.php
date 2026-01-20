<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Membership Tiers (Pro, Shop packages)
        Schema::create('membership_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "LMX Pro", "LMX Shop"
            $table->string('slug')->unique(); // "pro", "shop"
            $table->text('description')->nullable();
            $table->string('icon')->nullable(); // URL do ikone
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('duration_days')->default(30); // 30, 90, 365
            $table->json('features')->nullable(); // Lista prednosti
            $table->json('permissions')->nullable(); // Šta tier dozvoljava
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        // User Memberships (ko ima koji tier)
        Schema::create('user_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->unique();
            $table->foreignId('tier_id')->nullable()->constrained('membership_tiers')->onDelete('set null');
            $table->string('tier')->default('free'); // "free", "pro", "shop"
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active'); // active, expired, cancelled
            $table->timestamps();
            
            $table->index(['user_id', 'tier']);
            $table->index(['expires_at', 'status']);
        });

        // Membership Transactions (historija plaćanja)
        Schema::create('membership_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('tier_id')->constrained('membership_tiers')->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method'); // stripe, razorpay, bank_transfer
            $table->string('payment_status')->default('pending'); // pending, completed, failed
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('membership_transactions');
        Schema::dropIfExists('user_memberships');
        Schema::dropIfExists('membership_tiers');
    }
};
