<?php
// database/migrations/xxxx_create_item_shares_table.php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('platform', 30);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
 
            $table->index(['item_id', 'created_at']);
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('item_shares');
    }
};