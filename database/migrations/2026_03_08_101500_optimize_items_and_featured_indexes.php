<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function hasIndex(string $table, string $indexName): bool
    {
        $database = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(1) AS aggregate_count FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return (int) ($row->aggregate_count ?? 0) > 0;
    }

    private function addIndexIfMissing(string $table, string $indexName, array|string $columns): void
    {
        $columnList = is_array($columns) ? $columns : [$columns];

        if (!Schema::hasTable($table) || $this->hasIndex($table, $indexName)) {
            return;
        }

        foreach ($columnList as $columnName) {
            if (!Schema::hasColumn($table, $columnName)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName, $columns) {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table) || ! $this->hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }

    public function up(): void
    {
        $this->addIndexIfMissing('items', 'items_status_expiry_deleted_idx', ['status', 'expiry_date', 'deleted_at']);
        $this->addIndexIfMissing('items', 'items_category_status_expiry_idx', ['category_id', 'status', 'expiry_date']);
        $this->addIndexIfMissing('items', 'items_user_status_deleted_idx', ['user_id', 'status', 'deleted_at']);
        $this->addIndexIfMissing('items', 'items_clicks_idx', ['clicks']);
        $this->addIndexIfMissing('items', 'items_lat_lng_idx', ['latitude', 'longitude']);
        $this->addIndexIfMissing('items', 'items_city_idx', ['city']);
        $this->addIndexIfMissing('items', 'items_state_idx', ['state']);
        $this->addIndexIfMissing('items', 'items_country_idx', ['country']);

        $this->addIndexIfMissing('featured_items', 'featured_items_item_window_idx', ['item_id', 'start_date', 'end_date']);
        $this->addIndexIfMissing('featured_items', 'featured_items_placement_idx', ['placement']);
        $this->addIndexIfMissing('featured_items', 'featured_items_positions_idx', ['positions']);
        $this->addIndexIfMissing('featured_items', 'featured_items_placement_window_idx', ['placement', 'start_date', 'end_date']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('items', 'items_status_expiry_deleted_idx');
        $this->dropIndexIfExists('items', 'items_category_status_expiry_idx');
        $this->dropIndexIfExists('items', 'items_user_status_deleted_idx');
        $this->dropIndexIfExists('items', 'items_clicks_idx');
        $this->dropIndexIfExists('items', 'items_lat_lng_idx');
        $this->dropIndexIfExists('items', 'items_city_idx');
        $this->dropIndexIfExists('items', 'items_state_idx');
        $this->dropIndexIfExists('items', 'items_country_idx');

        $this->dropIndexIfExists('featured_items', 'featured_items_item_window_idx');
        $this->dropIndexIfExists('featured_items', 'featured_items_placement_idx');
        $this->dropIndexIfExists('featured_items', 'featured_items_positions_idx');
        $this->dropIndexIfExists('featured_items', 'featured_items_placement_window_idx');
    }
};
