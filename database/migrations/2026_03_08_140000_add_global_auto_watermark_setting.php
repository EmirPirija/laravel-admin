<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')
            ->where('name', 'global_auto_watermark_enabled')
            ->exists();

        if (!$exists) {
            DB::table('settings')->insert([
                'name' => 'global_auto_watermark_enabled',
                'value' => '1',
                'type' => 'boolean',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('name', 'global_auto_watermark_enabled')
            ->delete();
    }
};

