<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Kontakt opcije
            $table->boolean('show_phone')->default(true);
            $table->boolean('show_email')->default(true);
            $table->boolean('show_whatsapp')->default(false);
            $table->boolean('show_viber')->default(false);
            $table->string('whatsapp_number')->nullable();
            $table->string('viber_number')->nullable();
            $table->string('preferred_contact_method')->default('message');

            // Radno vrijeme (JSON)
            $table->json('business_hours')->nullable();

            // Vrijeme odgovora
            $table->string('response_time')->default('few_hours');

            // Ponude
            $table->boolean('accepts_offers')->default(true);

            // Auto-reply
            $table->boolean('auto_reply_enabled')->default(false);
            $table->text('auto_reply_message')->nullable();

            // Vacation mode
            $table->boolean('vacation_mode')->default(false);
            $table->text('vacation_message')->nullable();
            $table->timestamp('vacation_start')->nullable();
            $table->timestamp('vacation_end')->nullable();

            // Poslovne informacije
            $table->text('business_description')->nullable();
            $table->text('return_policy')->nullable();
            $table->text('shipping_info')->nullable();

            // Društvene mreže
            $table->string('social_facebook')->nullable();
            $table->string('social_instagram')->nullable();
            $table->string('social_tiktok')->nullable();
            $table->string('social_youtube')->nullable();
            $table->string('social_website')->nullable();

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settings');
    }
};
