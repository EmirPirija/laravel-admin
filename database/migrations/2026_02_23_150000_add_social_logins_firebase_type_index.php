<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            $rows = DB::select(
                'SELECT COUNT(1) as aggregate FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
                [$database, $table, $indexName]
            );
            return (int) ($rows[0]->aggregate ?? 0) > 0;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('social_logins')) {
            return;
        }

        if ($this->hasIndex('social_logins', 'social_logins_firebase_type_idx')) {
            return;
        }

        Schema::table('social_logins', function (Blueprint $table) {
            $table->index(['firebase_id', 'type'], 'social_logins_firebase_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('social_logins')) {
            return;
        }

        if (! $this->hasIndex('social_logins', 'social_logins_firebase_type_idx')) {
            return;
        }

        Schema::table('social_logins', function (Blueprint $table) {
            $table->dropIndex('social_logins_firebase_type_idx');
        });
    }
};
