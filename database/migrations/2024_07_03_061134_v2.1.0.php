<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {


        Schema::table('users', static function (Blueprint $table) {
            if (!Schema::hasColumn('users','show_personal_details')) {
                $table->boolean('show_personal_details')->default(0);
            }

            if (!Schema::hasColumn('users','is_verified')) {
                $table->boolean('is_verified')->default(0);
            }
        });
        Schema::table('block_users', static function (Blueprint $table) {
            $table->dropForeign('block_users_user_id_foreign');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');

            $table->dropForeign('block_users_blocked_user_id_foreign');
            $table->foreign('blocked_user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');
        });

        Schema::table('cities', static function (Blueprint $table) {
            $table->unique(['name', 'state_id', 'country_id']);
        });

        Schema::table('item_offers', static function (Blueprint $table) {
            $table->float('amount')->nullable()->change();
        });

        Schema::table('items', static function (Blueprint $table) {
            $table->foreignId('sold_to')->after('user_id')->nullable()->references('id')->on('users')->onDelete('cascade');
            $table->date('expiry_date')->after('all_category_ids')->nullable();
        });

        Schema::create('seller_ratings', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('buyer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('item_id')->references('id')->on('items')->onDelete('cascade');
            $table->string('review')->nullable();
            $table->float('ratings');
            $table->timestamps();
            $table->unique(['item_id', 'buyer_id']);
        });

        Schema::create('verification_fields', static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->text('values')->nullable();
            $table->integer('min_length')->nullable();
            $table->integer('max_length')->nullable();
            $table->enum('status', ['review', 'approved', 'rejected', 'sold out', 'featured']);
            $table->boolean('is_required')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('verification_requests', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');
            $table->enum('status', ['pending', 'approved', 'rejected', 'resubmitted']);
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('verification_field_values', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_field_id')->references('id')->on('verification_fields')->onDelete('cascade');
            $table->text('value')->nullable();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('verification_request_id')->references('id')->on('verification_requests')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
            $table->unique(['user_id', 'verification_field_id']);
        });

        Schema::create('seo_settings', static function (Blueprint $table) {
            $table->id();
            $table->string('page')->nullable();
            $table->string('title')->nullable();
            $table->string('description')->nullable();
            $table->string('keywords')->nullable();
            $table->string('image', 512)->nullable();
            $table->timestamps();
        });

        Schema::table('feature_sections', static function (Blueprint $table) {
            $table->string('description')->nullable();
        });

        Schema::table('user_fcm_tokens', static function (Blueprint $table) {
            $table->enum('platform_type', ['Android', 'iOS'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('users', static function (Blueprint $table) {
            $table->dropColumn('show_personal_details');
        });

        Schema::table('cities', static function (Blueprint $table) {
            $table->dropColumn('name', 'state_id', 'country_id');
        });

        Schema::table('item_offers', static function (Blueprint $table) {
            $table->float('amount')->nullable()->change();
        });

        Schema::table('items', static function (Blueprint $table) {
            $table->dropColumn('sold_to');
            $table->dropColumn('expiry_date');
        });

        Schema::table('users', static function (Blueprint $table) {
            $table->dropColumn('is_verified');
        });

        Schema::table('verification_requests', static function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });

        Schema::table('feature_sections', static function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('user_fcm_tokens', static function (Blueprint $table) {
            $table->dropColumn('platform_type');
        });

        Schema::dropIfExists('seller_ratings');
        Schema::dropIfExists('verification_fields');
        Schema::dropIfExists('verification_requests');
        Schema::dropIfExists('verification_field_values');
        Schema::dropIfExists('seo_settings');
    }
};
