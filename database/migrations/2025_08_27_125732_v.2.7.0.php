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
         Schema::table('payment_configurations', function (Blueprint $table) {
            $table->string('additional_data_1')->nullable();
            $table->string('additional_data_2')->nullable();
            $table->string('payment_mode')->nullable();
        });

        Schema::create('item_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->foreignId('language_id')->constrained('languages')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('address')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->string('admin_edit_reason')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'language_id']);
        });

        Schema::create('custom_fields_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained('custom_fields')->onDelete('cascade');
            $table->foreignId('language_id')->constrained('languages')->onDelete('cascade');
            $table->string('name');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_id', 'language_id']);
        });
        Schema::table('item_custom_field_values', function (Blueprint $table) {
           $table->foreignId('language_id')->nullable()->references('id')->on('languages')->onDelete('cascade');
        });
         Schema::table('item_custom_field_values', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropForeign(['custom_field_id']);
            $table->dropUnique('item_custom_field_values_item_id_custom_field_id_unique');
            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
            $table->foreign('custom_field_id')->references('id')->on('custom_fields')->onDelete('cascade');
            $table->unique(['item_id', 'custom_field_id', 'language_id'], 'item_field_language_unique');
        });
        Schema::create('package_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->onDelete('cascade');
            $table->foreignId('language_id')->constrained()->onDelete('cascade');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'language_id']); // prevent duplicate translations
        });
         Schema::create('setting_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained()->onDelete('cascade');
            $table->foreignId('language_id')->constrained()->onDelete('cascade');
            $table->text('translated_value')->nullable();
            $table->timestamps();

            $table->unique(['setting_id', 'language_id']); // prevent duplicate translations
        });
         Schema::create('feature_section_translations', function (Blueprint $table) {
             $table->id();
            $table->foreignId('feature_section_id')->constrained()->onDelete('cascade');
            $table->foreignId('language_id')->constrained()->onDelete('cascade');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();


            $table->unique(['feature_section_id', 'language_id'], 'feature_section_language_unique'); // prevent duplicate translations
        });
         Schema::create('country_translations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('country_id')->constrained()->onDelete('cascade');
                $table->foreignId('language_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->timestamps();

                $table->unique(['country_id', 'language_id']);
            });

            Schema::create('state_translations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('state_id')->constrained()->onDelete('cascade');
                $table->foreignId('language_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->timestamps();

                $table->unique(['state_id', 'language_id']);
            });

            Schema::create('city_translations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('city_id')->constrained()->onDelete('cascade');
                $table->foreignId('language_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->timestamps();

                $table->unique(['city_id', 'language_id']);
            });
             Schema::create('area_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->onDelete('cascade');
            $table->foreignId('language_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->timestamps();

            $table->unique(['area_id', 'language_id']);
        });
         Schema::create('report_reason_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_reason_id')->constrained()->onDelete('cascade');
            $table->foreignId('language_id')->constrained()->onDelete('cascade');
            $table->string('reason');
            $table->timestamps();

            $table->unique(['report_reason_id', 'language_id']);
        });
        Schema::create('blog_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->onDelete('cascade');
            $table->foreignId('language_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
           $table->string('tags',1000)->nullable();
            $table->timestamps();
        });
         Schema::create('verification_fields_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_field_id')->constrained('verification_fields')->onDelete('cascade');
            $table->foreignId('language_id')->constrained('languages')->onDelete('cascade');
            $table->string('name');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['verification_field_id', 'language_id'],'verification_language_unique');
        });
         Schema::table('verification_field_values', function (Blueprint $table) {
                    $table->dropForeign(['user_id']);
                    $table->dropForeign(['verification_field_id']);

                    $table->dropUnique('verification_field_values_user_id_verification_field_id_unique');

                    if (!Schema::hasColumn('verification_field_values', 'language_id')) {
                        $table->foreignId('language_id')->nullable()->after('verification_request_id');
                    }

                    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                    $table->foreign('verification_field_id')->references('id')->on('verification_fields')->onDelete('cascade');
                    $table->foreign('language_id')->references('id')->on('languages')->onDelete('cascade');
                    $table->unique(['user_id', 'verification_field_id', 'language_id'], 'user_field_language_unique');
        });
        Schema::create('faq_translations', function (Blueprint $table) {
                 $table->id();
                $table->string('question');
                $table->text('answer');
                $table->timestamps();
                $table->foreignId('faq_id')->constrained('faqs')->onDelete('cascade');
                $table->foreignId('language_id')->constrained('languages')->onDelete('cascade');
                $table->unique(['faq_id', 'language_id'],'faq_language_unique');
        });
        Schema::create('seo_settings_translations', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('description')->nullable();
            $table->string('keywords')->nullable();
            $table->foreignId('seo_setting_id')->constrained('seo_settings')->onDelete('cascade');
            $table->foreignId('language_id')->constrained('languages')->onDelete('cascade');

            $table->timestamps();
            $table->unique(['seo_setting_id', 'language_id'], 'seo_setting_language_unique');
        });
        Schema::table('category_translations', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
         Schema::table('payment_transactions', function (Blueprint $table) {
            // Add package_id column as nullable first
            $table->unsignedBigInteger('package_id')->nullable()->after('user_id');
        });

        // Optionally: Fill existing rows with a default package_id if needed
        // DB::table('payment_transactions')->update(['package_id' => 1]); // Example

        Schema::table('payment_transactions', function (Blueprint $table) {
            // Add foreign key constraint
            $table->foreign('package_id')
                  ->references('id')
                  ->on('packages')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('payment_configurations', function (Blueprint $table) {
            $table->dropColumn('additional_data_1');
            $table->dropColumn('additional_data_1');
            $table->dropColumn('payment_mode');
        });
        Schema::dropIfExists('item_translations');
        Schema::dropIfExists('custom_fields_translations');
        Schema::table('item_custom_field_values', function (Blueprint $table) {
              $table->dropColumn('language_id');
        });
        Schema::table('item_custom_field_values', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropForeign(['custom_field_id']);
            $table->dropUnique('item_field_language_unique');
            $table->unique(['item_id', 'custom_field_id'], 'item_custom_field_values_item_id_custom_field_id_unique');
            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
            $table->foreign('custom_field_id')->references('id')->on('custom_fields')->onDelete('cascade');
        });
         Schema::dropIfExists('package_translations');
          Schema::dropIfExists('setting_translations');
           Schema::dropIfExists('feature_section_translations');
            Schema::dropIfExists('country_translations');
        Schema::dropIfExists('state_translations');
        Schema::dropIfExists('city_translations');
        Schema::dropIfExists('area_translations');
        Schema::dropIfExists('report_reason_translations');
         Schema::dropIfExists('blog_translations');
         Schema::dropIfExists('verification_fields_translations');
          Schema::table('verification_field_values', function (Blueprint $table) {
            $table->dropUnique('user_field_language_unique');
            $table->unique(['user_id', 'verification_field_id']);
            $table->dropForeign(['language_id']);
            $table->dropColumn('language_id');
        });
         Schema::dropIfExists('faq_translations');
           Schema::dropIfExists('seo_settings_translations');
             Schema::table('category_translations', function (Blueprint $table) {
            $table->dropColumn('description');
        });
         Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn('package_id');
        });
    }
};
