<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->integer('products_requested');
            $table->integer('products_imported')->default(0);
            $table->integer('products_failed')->default(0);

            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');

            $table->timestamps();

            $table->index('user_id');
        });

        // Add instagram_product_id to items table if not exists
        if (!Schema::hasColumn('items', 'instagram_product_id')) {
            Schema::table('items', function (Blueprint $table) {
                $table->string('instagram_product_id')->nullable()->after('id');
                $table->timestamp('instagram_synced_at')->nullable()->after('instagram_product_id');
                $table->index('instagram_product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_imports');

        if (Schema::hasColumn('items', 'instagram_product_id')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn(['instagram_product_id', 'instagram_synced_at']);
            });
        }
    }
};
