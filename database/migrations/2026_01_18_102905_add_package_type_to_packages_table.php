<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
{
    Schema::table('packages', function (Blueprint $table) {
        $table->enum('package_type', ['item_listing', 'advertisement', 'membership'])->default('item_listing')->after('type');
    });
}

};
