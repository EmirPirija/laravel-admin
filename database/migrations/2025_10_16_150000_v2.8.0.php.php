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
         Schema::table('items', function (Blueprint $table) {
            $table->foreignId('package_id')
                ->nullable()
                ->constrained('packages')
                ->onDelete('set null'); // if package deleted → set null
        });
        
        $updates = [
            'item-listing-package'  => 'advertisement-listing-package',
            'advertisement-package' => 'featured-advertisement-package',
            'item'                  => 'advertisement',
        ];

        Permission::chunk(100, function ($permissions) use ($updates) {
            $updatedPermissions = [];

            foreach ($permissions as $permission) {
                $oldName = $permission->name;
                $newName = $oldName;

                foreach ($updates as $oldPrefix => $newPrefix) {
                    // Rename if permission name starts with this prefix
                    if (str_starts_with($oldName, $oldPrefix . '-')) {
                        $newName = preg_replace(
                            '/^' . preg_quote($oldPrefix, '/') . '-/',
                            $newPrefix . '-',
                            $oldName
                        );
                        break;
                    }
                }

                // If name changed and doesn’t already exist, schedule for update
                if ($newName !== $oldName &&
                    !Permission::where('name', $newName)
                        ->where('guard_name', $permission->guard_name)
                        ->exists()) {
                    $updatedPermissions[] = [
                        'id'   => $permission->id,
                        'name' => $newName,
                    ];
                }
            }

            if (!empty($updatedPermissions)) {
                Permission::upsert($updatedPermissions, ['id'], ['name']);
            }
        });

         Schema::table('payment_configurations', function (Blueprint $table) {
            $table->string('username')->nullable();
            $table->string('password')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
          Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn('package_id');
        });

         $reverses = [
            'advertisement-listing-package'  => 'item-listing-package',
            'featured-advertisement-package' => 'advertisement-package',
            'advertisement'                  => 'item',
        ];

        Permission::chunk(100, function ($permissions) use ($reverses) {
            $revertedPermissions = [];

            foreach ($permissions as $permission) {
                $oldName = $permission->name;
                $newName = $oldName;

                foreach ($reverses as $oldPrefix => $newPrefix) {
                    if (str_starts_with($oldName, $oldPrefix . '-')) {
                        $newName = preg_replace(
                            '/^' . preg_quote($oldPrefix, '/') . '-/',
                            $newPrefix . '-',
                            $oldName
                        );
                        break;
                    }
                }

                if ($newName !== $oldName &&
                    !Permission::where('name', $newName)
                        ->where('guard_name', $permission->guard_name)
                        ->exists()) {
                    $revertedPermissions[] = [
                        'id'   => $permission->id,
                        'name' => $newName,
                    ];
                }
            }

            if (!empty($revertedPermissions)) {
                Permission::upsert($revertedPermissions, ['id'], ['name']);
            }
        });

        Schema::table('payment_configurations', function (Blueprint $table) {
            $table->dropColumn(['username', 'password']);
        });
    }
};
