<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('item_offers', function (Blueprint $table) {
            // Promijenili smo 'status' u 'amount'
            $table->json('muted_by')->nullable()->after('amount');
            
            // ILI samo ovako (dodaće na kraj tabele):
            // $table->json('muted_by')->nullable(); 
        });
    }
    
    public function down()
    {
        Schema::table('item_offers', function (Blueprint $table) {
            $table->dropColumn('muted_by');
        });
    }
};
