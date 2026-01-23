<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->integer('inventory_count')->nullable()->default(null)->after('price');
            $table->enum('reservation_status', ['none', 'reserved'])->default('none')->after('status');
            $table->unsignedBigInteger('reserved_for_user_id')->nullable()->after('reservation_status');
            $table->timestamp('reserved_at')->nullable()->after('reserved_for_user_id');
            $table->string('reservation_note')->nullable()->after('reserved_at');
            
            $table->foreign('reserved_for_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }
 
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['reserved_for_user_id']);
            $table->dropColumn([
                'inventory_count',
                'reservation_status',
                'reserved_for_user_id',
                'reserved_at',
                'reservation_note'
            ]);
        });
    }
};