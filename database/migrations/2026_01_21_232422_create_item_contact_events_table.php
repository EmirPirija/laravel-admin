<?php
// database/migrations/xxxx_create_item_contact_events_table.php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_contact_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('contact_type', 30); // phone_click, whatsapp, viber, email, message
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
 
            $table->index(['item_id', 'created_at']);
            $table->index('contact_type');
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('item_contact_events');
    }
};