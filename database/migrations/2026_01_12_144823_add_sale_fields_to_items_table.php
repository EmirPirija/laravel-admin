<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Akcija/Sale polja
            $table->boolean('is_on_sale')->default(false)->after('price');
            $table->decimal('old_price', 10, 2)->nullable()->after('is_on_sale');
            
            // Historija cijena (JSON polje)
            $table->json('price_history')->nullable()->after('old_price');
        });
    }
 
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['is_on_sale', 'old_price', 'price_history']);
        });
    }
};
