<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'is_on_sale')) {
                $table->boolean('is_on_sale')->default(false)->after('price');
            }
            if (!Schema::hasColumn('items', 'old_price')) {
                $table->decimal('old_price', 10, 2)->nullable()->after('is_on_sale');
            }
            if (!Schema::hasColumn('items', 'price_history')) {
                $table->json('price_history')->nullable()->after('old_price');
            }
        });
    }
 
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['is_on_sale', 'old_price', 'price_history']);
        });
    }
};