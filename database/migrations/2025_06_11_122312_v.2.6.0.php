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
         Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->references('id')->on('items')->onDelete('cascade');
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('recruiter_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('full_name');
            $table->string('email');
            $table->string('mobile');
            $table->string('resume')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamps();
            $table->unique(['user_id', 'item_id'], 'unique_user_item');
        });
        Schema::table('items', function (Blueprint $table) {
            $table->double('price')->nullable()->change();
            $table->double('min_salary')->nullable()->after('price');
            $table->double('max_salary')->nullable()->after('min_salary');
            $table->boolean('is_edited_by_admin')->default(0);
            $table->string('admin_edit_reason')->nullable()->after('is_edited_by_admin');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_job_category')->default(0);
            $table->boolean('price_optional')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::dropIfExists('job_applications');
        Schema::table('items', function (Blueprint $table) {
            $table->double('price')->nullable(false)->change();
            $table->dropColumn(['min_salary', 'max_salary']);
            $table->dropColumn(['is_edited_by_admin', 'admin_edit_reason']);
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_job_category');
             $table->dropColumn('price_optional');
        });
    }
};
