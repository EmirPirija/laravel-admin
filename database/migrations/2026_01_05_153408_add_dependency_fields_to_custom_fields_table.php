<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->unsignedBigInteger('dependent_on')->nullable()->after('max_length');
            $table->json('parent_mapping')->nullable()->after('dependent_on');
        });
    }

    public function down()
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn(['dependent_on', 'parent_mapping']);
        });
    }
};