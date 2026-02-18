<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('items', 'add_video_to_story')) {
            Schema::table('items', function (Blueprint $table) {
                $table->boolean('add_video_to_story')
                    ->default(false)
                    ->after('publish_to_instagram');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('items', 'add_video_to_story')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('add_video_to_story');
            });
        }
    }
};
