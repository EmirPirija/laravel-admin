<?php
// database/migrations/xxxx_create_item_visitor_sessions_table.php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_visitor_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->onDelete('cascade');
            $table->string('visitor_id', 100);
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('device_type', 20)->default('desktop');
            $table->string('source', 50)->default('direct');
            $table->string('source_detail', 100)->nullable();
            $table->string('referrer_url', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
 
            $table->index(['item_id', 'visitor_id', 'created_at']);
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('item_visitor_sessions');
    }
};