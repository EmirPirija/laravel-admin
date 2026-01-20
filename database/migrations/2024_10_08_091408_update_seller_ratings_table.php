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
        Schema::table('seller_ratings', function (Blueprint $table) {
            $table->softDeletes();
            $table->enum('report_status', ['reported','rejected','approved'])->nullable();
            $table->string('report_reason')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_ratings', function (Blueprint $table) {
            $table->dropColumn('report_status');
            $table->dropColumn('report_reason');

        });
    }
};
