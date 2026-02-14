<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'publish_to_instagram')) {
                $table->boolean('publish_to_instagram')->default(false)->after('video_link');
            }

            if (! Schema::hasColumn('items', 'instagram_source_url')) {
                $table->string('instagram_source_url', 1000)->nullable()->after('publish_to_instagram');
            }

            if (! Schema::hasColumn('items', 'price_per_unit')) {
                $table->decimal('price_per_unit', 12, 2)->nullable()->after('price');
            }

            if (! Schema::hasColumn('items', 'minimum_order_quantity')) {
                $table->unsignedInteger('minimum_order_quantity')->default(1)->after('price_per_unit');
            }

            if (! Schema::hasColumn('items', 'stock_alert_threshold')) {
                $table->unsignedInteger('stock_alert_threshold')->nullable()->after('inventory_count');
            }
        });

        Schema::table('seller_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('seller_settings', 'continue_selling_out_of_stock')) {
                $table->boolean('continue_selling_out_of_stock')->default(false)->after('vacation_mode');
            }

            if (! Schema::hasColumn('seller_settings', 'low_stock_threshold')) {
                $table->unsignedInteger('low_stock_threshold')->default(3)->after('continue_selling_out_of_stock');
            }

            if (! Schema::hasColumn('seller_settings', 'storefront_domain')) {
                $table->string('storefront_domain')->nullable()->unique()->after('social_website');
            }

            if (! Schema::hasColumn('seller_settings', 'storefront_domain_status')) {
                $table->string('storefront_domain_status', 40)->default('none')->after('storefront_domain');
            }

            if (! Schema::hasColumn('seller_settings', 'storefront_domain_verified_at')) {
                $table->timestamp('storefront_domain_verified_at')->nullable()->after('storefront_domain_status');
            }

            if (! Schema::hasColumn('seller_settings', 'storefront_domain_error')) {
                $table->text('storefront_domain_error')->nullable()->after('storefront_domain_verified_at');
            }

            if (! Schema::hasColumn('seller_settings', 'storefront_domain_ssl_enabled')) {
                $table->boolean('storefront_domain_ssl_enabled')->default(false)->after('storefront_domain_error');
            }

            if (! Schema::hasColumn('seller_settings', 'storefront_domain_cname_target')) {
                $table->string('storefront_domain_cname_target')->nullable()->after('storefront_domain_ssl_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $dropColumns = [];
            foreach ([
                'publish_to_instagram',
                'instagram_source_url',
                'price_per_unit',
                'minimum_order_quantity',
                'stock_alert_threshold',
            ] as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if (! empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });

        Schema::table('seller_settings', function (Blueprint $table) {
            if (Schema::hasColumn('seller_settings', 'storefront_domain')) {
                $table->dropUnique(['storefront_domain']);
            }

            $dropColumns = [];
            foreach ([
                'continue_selling_out_of_stock',
                'low_stock_threshold',
                'storefront_domain',
                'storefront_domain_status',
                'storefront_domain_verified_at',
                'storefront_domain_error',
                'storefront_domain_ssl_enabled',
                'storefront_domain_cname_target',
            ] as $column) {
                if (Schema::hasColumn('seller_settings', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if (! empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};

