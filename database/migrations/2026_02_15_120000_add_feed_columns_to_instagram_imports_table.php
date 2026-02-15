<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instagram_imports')) {
            return;
        }

        Schema::table('instagram_imports', function (Blueprint $table) {
            if (! Schema::hasColumn('instagram_imports', 'source_url')) {
                $table->string('source_url', 1000)->nullable()->after('category_id');
            }

            if (! Schema::hasColumn('instagram_imports', 'source_urls_json')) {
                $table->longText('source_urls_json')->nullable()->after('source_url');
            }

            if (! Schema::hasColumn('instagram_imports', 'feed_format')) {
                $table->string('feed_format', 20)->default('api')->after('source_urls_json');
            }

            if (! Schema::hasColumn('instagram_imports', 'status')) {
                $table->string('status', 40)->default('queued')->after('feed_format');
            }

            if (! Schema::hasColumn('instagram_imports', 'message')) {
                $table->text('message')->nullable()->after('status');
            }

            if (! Schema::hasColumn('instagram_imports', 'meta')) {
                $table->json('meta')->nullable()->after('message');
            }

            if (! Schema::hasColumn('instagram_imports', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('meta');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('instagram_imports')) {
            return;
        }

        Schema::table('instagram_imports', function (Blueprint $table) {
            $columnsToDrop = [];

            foreach ([
                'source_url',
                'source_urls_json',
                'feed_format',
                'status',
                'message',
                'meta',
                'processed_at',
            ] as $column) {
                if (Schema::hasColumn('instagram_imports', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

