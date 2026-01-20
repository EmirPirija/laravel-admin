<?php

use App\Models\User;
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
        Schema::table('users', function (Blueprint $table) {
                 $table->boolean('auto_approve_item')->default(0)->after('is_verified');
        });
        Schema::table('chats', function (Blueprint $table) {
            $table->boolean('is_read')->nullable();
        });
        Schema::table('languages', function (Blueprint $table) {
            $table->string('country_code')->nullable();
        });
        User::chunk(100, static function ($users) {
            $tempUsers = [];
            foreach ($users as $user) {
                if ($user->is_verified == 1) {
                    $tempUsers[] = [
                        'id' => $user->id,
                        'auto_approve_item' => 1,
                    ];
                }
            }
            if (count($tempUsers) > 0) {
                User::upsert($tempUsers, ['id'], ['auto_approve_item']);
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auto_approve_item');
        });
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn('is_read');
        });
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
