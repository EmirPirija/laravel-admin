<?php
// database/migrations/xxxx_create_item_search_impressions_table.php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_search_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->onDelete('cascade');
            $table->string('search_query', 200)->nullable();
            $table->unsignedTinyInteger('page')->default(1);
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('visitor_id', 100)->nullable();
            $table->boolean('clicked')->default(false);
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();
 
            $table->index(['item_id', 'created_at']);
            $table->index('search_query');
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('item_search_impressions');
    }
};