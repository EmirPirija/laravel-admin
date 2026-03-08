<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_settings', 'canonical_url')) {
                $table->string('canonical_url', 1024)->nullable()->after('image');
            }

            if (!Schema::hasColumn('seo_settings', 'site_name')) {
                $table->string('site_name')->nullable()->after('canonical_url');
            }

            if (!Schema::hasColumn('seo_settings', 'search_path')) {
                $table->string('search_path', 255)->nullable()->after('site_name');
            }

            if (!Schema::hasColumn('seo_settings', 'knowledge_graph_type')) {
                $table->string('knowledge_graph_type', 80)->nullable()->after('search_path');
            }

            if (!Schema::hasColumn('seo_settings', 'organization_name')) {
                $table->string('organization_name')->nullable()->after('knowledge_graph_type');
            }

            if (!Schema::hasColumn('seo_settings', 'organization_logo')) {
                $table->string('organization_logo', 1024)->nullable()->after('organization_name');
            }

            if (!Schema::hasColumn('seo_settings', 'organization_phone')) {
                $table->string('organization_phone', 120)->nullable()->after('organization_logo');
            }

            if (!Schema::hasColumn('seo_settings', 'organization_email')) {
                $table->string('organization_email')->nullable()->after('organization_phone');
            }

            if (!Schema::hasColumn('seo_settings', 'organization_address')) {
                $table->string('organization_address', 1024)->nullable()->after('organization_email');
            }

            if (!Schema::hasColumn('seo_settings', 'social_profiles_json')) {
                $table->longText('social_profiles_json')->nullable()->after('organization_address');
            }

            if (!Schema::hasColumn('seo_settings', 'og_title')) {
                $table->string('og_title')->nullable()->after('social_profiles_json');
            }

            if (!Schema::hasColumn('seo_settings', 'og_description')) {
                $table->text('og_description')->nullable()->after('og_title');
            }

            if (!Schema::hasColumn('seo_settings', 'og_image')) {
                $table->string('og_image', 1024)->nullable()->after('og_description');
            }

            if (!Schema::hasColumn('seo_settings', 'og_type')) {
                $table->string('og_type', 80)->nullable()->after('og_image');
            }

            if (!Schema::hasColumn('seo_settings', 'twitter_title')) {
                $table->string('twitter_title')->nullable()->after('og_type');
            }

            if (!Schema::hasColumn('seo_settings', 'twitter_description')) {
                $table->text('twitter_description')->nullable()->after('twitter_title');
            }

            if (!Schema::hasColumn('seo_settings', 'twitter_image')) {
                $table->string('twitter_image', 1024)->nullable()->after('twitter_description');
            }

            if (!Schema::hasColumn('seo_settings', 'twitter_card')) {
                $table->string('twitter_card', 80)->nullable()->after('twitter_image');
            }

            if (!Schema::hasColumn('seo_settings', 'robots_index')) {
                $table->boolean('robots_index')->default(true)->after('twitter_card');
            }

            if (!Schema::hasColumn('seo_settings', 'robots_follow')) {
                $table->boolean('robots_follow')->default(true)->after('robots_index');
            }

            if (!Schema::hasColumn('seo_settings', 'robots_noarchive')) {
                $table->boolean('robots_noarchive')->default(false)->after('robots_follow');
            }

            if (!Schema::hasColumn('seo_settings', 'robots_nosnippet')) {
                $table->boolean('robots_nosnippet')->default(false)->after('robots_noarchive');
            }

            if (!Schema::hasColumn('seo_settings', 'schema_json')) {
                $table->longText('schema_json')->nullable()->after('robots_nosnippet');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seo_settings', function (Blueprint $table) {
            $columns = [
                'canonical_url',
                'site_name',
                'search_path',
                'knowledge_graph_type',
                'organization_name',
                'organization_logo',
                'organization_phone',
                'organization_email',
                'organization_address',
                'social_profiles_json',
                'og_title',
                'og_description',
                'og_image',
                'og_type',
                'twitter_title',
                'twitter_description',
                'twitter_image',
                'twitter_card',
                'robots_index',
                'robots_follow',
                'robots_noarchive',
                'robots_nosnippet',
                'schema_json',
            ];

            $existingColumns = array_values(array_filter($columns, static fn ($column) => Schema::hasColumn('seo_settings', $column)));

            if (!empty($existingColumns)) {
                $table->dropColumn($existingColumns);
            }
        });
    }
};

