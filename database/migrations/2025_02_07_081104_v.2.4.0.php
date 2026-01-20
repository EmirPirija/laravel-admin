<?php

use App\Models\Item;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE items MODIFY COLUMN status ENUM(
            'review',
            'approved',
            'soft rejected',
            'permanent rejected',
            'sold out',
            'featured',
            'resubmitted'
        ) NOT NULL");

        Item::chunk(100, static function ($items) {
            $tempUsers = [];
            foreach ($items as $item) {
                if (!empty($item->rejected_reason) && empty($item->status)) {
                    $tempUsers[] = [
                        'id' => $item->id,
                        'status' => 'permanent rejected',
                    ];
                }
            }
            if (!empty($tempUsers)) {
                Item::upsert($tempUsers, ['id'], ['status']);
            }
        });
        Item::chunk(100, static function ($items) {
            $tempUsers = [];
            foreach ($items as $item) {
                if (empty($item->status)) {
                    $tempUsers[] = [
                        'id' => $item->id,
                        'status' => 'permanent rejected',
                    ];
                }
            }
            if (!empty($tempUsers)) {
                Item::upsert($tempUsers, ['id'], ['status']);
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE items MODIFY COLUMN status ENUM(
            'review',
            'approved',
            'rejected',
            'sold out',
            'featured'
        ) NOT NULL");

        DB::table('items')
        ->where('status', 'permanent rejected')
        ->update(['status' => 'rejected']);
    }
};
