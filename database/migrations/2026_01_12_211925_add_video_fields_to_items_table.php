<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Dodaje polja za video upload:
     * - video: Path do uploadovanog videa
     * - video_thumbnail: Auto-generisani thumbnail (prvi frame)
     * - video_duration: Trajanje videa u sekundama
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Video file path
            if (!Schema::hasColumn('items', 'video')) {
                $table->string('video')->nullable()->after('image');
            }
            
            // Auto-generated thumbnail
            if (!Schema::hasColumn('items', 'video_thumbnail')) {
                $table->string('video_thumbnail')->nullable()->after('video');
            }
            
            // Video duration in seconds (za prikaz)
            if (!Schema::hasColumn('items', 'video_duration')) {
                $table->unsignedSmallInteger('video_duration')->nullable()->after('video_thumbnail')
                    ->comment('Trajanje videa u sekundama');
            }
        });
    }
 
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $columns = ['video', 'video_thumbnail', 'video_duration'];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};