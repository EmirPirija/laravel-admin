<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE payment_transactions MODIFY COLUMN payment_status ENUM(
            'failed',
            'succeed',
            'pending',
            'under review',
            'rejected'
        ) NOT NULL");
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('payment_receipt')->nullable();
        });

        Schema::table('areas', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable()->after('country_id');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
        });
        Schema::create('number_otps', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('otp');
            $table->timestamp('expire_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
        });

        Schema::table('faqs', function (Blueprint $table) {
            $table->longText('answer')->change();
        });
        $updates = [
            'item-listing-package'     => 'advertisement-listing-package',
            'advertisement-package'    => 'featured-advertisement-package',
            'item'                     => 'advertisement',
        ];

        Permission::chunk(100, function ($permissions) use ($updates) {
            $updatedPermissions = [];

            foreach ($permissions as $permission) {
                foreach ($updates as $oldPrefix => $newPrefix) {
                    if (str_starts_with($permission->name, $oldPrefix . '-')) {
                        $newName = str_replace($oldPrefix, $newPrefix, $permission->name);

                        // Prevent duplicate names
                        if (!Permission::where('name', $newName)
                            ->where('guard_name', $permission->guard_name)
                            ->exists()) {
                            $updatedPermissions[] = [
                                'id'   => $permission->id,
                                'name' => $newName,
                            ];
                        }

                        break;
                    }
                }
            }

            if (!empty($updatedPermissions)) {
                Permission::upsert($updatedPermissions, ['id'], ['name']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE payment_transactions MODIFY COLUMN payment_status ENUM(
            'failed',
            'succeed',
            'pending'
        ) NOT NULL");
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('payment_receipt');
        });
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
        Schema::dropIfExists('number_otps');

        Schema::table('faqs', function (Blueprint $table) {
            $table->string('answer')->change(); // revert back if needed
        });
    }
};
