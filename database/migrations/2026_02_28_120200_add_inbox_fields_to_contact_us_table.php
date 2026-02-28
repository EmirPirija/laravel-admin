<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_us', static function (Blueprint $table) {
            if (!Schema::hasColumn('contact_us', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }

            if (!Schema::hasColumn('contact_us', 'status')) {
                $table->string('status', 32)->default('new')->after('message')->index();
            }

            if (!Schema::hasColumn('contact_us', 'assigned_to')) {
                $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('contact_us', 'admin_note')) {
                $table->text('admin_note')->nullable()->after('assigned_to');
            }

            if (!Schema::hasColumn('contact_us', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('admin_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contact_us', static function (Blueprint $table) {
            $dropColumns = [];
            foreach (['resolved_at', 'admin_note', 'assigned_to', 'status', 'phone'] as $column) {
                if (Schema::hasColumn('contact_us', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if (in_array('assigned_to', $dropColumns, true)) {
                try {
                    $table->dropConstrainedForeignId('assigned_to');
                } catch (\Throwable) {
                    // no-op
                }
                $dropColumns = array_values(array_filter($dropColumns, fn ($value) => $value !== 'assigned_to'));
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
