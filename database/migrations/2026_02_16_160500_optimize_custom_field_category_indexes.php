<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Ukloni duplikate prije unique indeksa
        DB::statement(
            'DELETE c1 FROM custom_field_categories c1 '
            . 'INNER JOIN custom_field_categories c2 '
            . 'ON c1.id > c2.id '
            . 'AND c1.category_id = c2.category_id '
            . 'AND c1.custom_field_id = c2.custom_field_id'
        );

        Schema::table('custom_field_categories', function (Blueprint $table) {
            if (! $this->hasIndex('custom_field_categories', 'cfc_category_custom_unique')) {
                $table->unique(['category_id', 'custom_field_id'], 'cfc_category_custom_unique');
            }
            if (! $this->hasIndex('custom_field_categories', 'cfc_custom_category_idx')) {
                $table->index(['custom_field_id', 'category_id'], 'cfc_custom_category_idx');
            }
        });

        Schema::table('custom_fields', function (Blueprint $table) {
            if (! $this->hasIndex('custom_fields', 'cf_priority_id_idx')) {
                $table->index(['priority', 'id'], 'cf_priority_id_idx');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('custom_field_categories', function (Blueprint $table) {
            if ($this->hasIndex('custom_field_categories', 'cfc_custom_category_idx')) {
                $table->dropIndex('cfc_custom_category_idx');
            }
            if ($this->hasIndex('custom_field_categories', 'cfc_category_custom_unique')) {
                $table->dropUnique('cfc_category_custom_unique');
            }
        });

        Schema::table('custom_fields', function (Blueprint $table) {
            if ($this->hasIndex('custom_fields', 'cf_priority_id_idx')) {
                $table->dropIndex('cf_priority_id_idx');
            }
        });
    }
};
