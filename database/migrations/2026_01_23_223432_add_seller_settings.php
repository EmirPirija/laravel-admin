<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_settings', function (Blueprint $table) {
            $table->boolean('continue_selling_out_of_stock')->default(false)->after('vacation_mode');
            $table->integer('low_stock_threshold')->default(3)->after('continue_selling_out_of_stock');
        });
    }
 
    public function down(): void
    {
        Schema::table('seller_settings', function (Blueprint $table) {
            $table->dropColumn(['continue_selling_out_of_stock', 'low_stock_threshold']);
        });
    }
};