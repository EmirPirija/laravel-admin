<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Setting;
use App\Services\DefaultSettingService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class SystemUpgradeSeeder extends Seeder {
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run() {
        // * means create,list,update,delete
        $permissionsList = [
            'role'                        => '*',
            'staff'                       => '*',
            'category'                    => '*',
            'custom-field'                => '*',
            'advertisement'                        => [
                'only' => ['list', 'update']
            ],
            'advertisement-listing-package'        => '*',
            'featured-advertisement-package'       => '*',
            'user-package'                => [
                'only' => ['list']
            ],
            'payment-transactions'        => [
                'only' => ['list']
            ],
            'slider'                      => [
                'only' => ['create', 'delete', 'list']
            ],
            'feature-section'             => '*',
            'report-reason'               => '*',
            'user-reports'                => [
                'only' => ['list']
            ],
            'notification'                => '*',
            'customer'                    => [
                'only' => ['list', 'update']
            ],
            'settings'                    => [
                'only' => ['update']
            ],
            'tip'                         => '*',
            'blog'                        => '*',
            'country'                     => '*',
            'state'                       => '*',
            'city'                        => '*',
            'area'                        => '*',
            'faq'                         => '*',
            'seller-verification-field'   => '*',
            'seller-verification-request' => [
                'only' => ['list','update']
            ],
            'user-queries' => [
                'only' => ['list']
            ],
        ];

        $permissionsList = self::generatePermissionList($permissionsList);

        $permissions = array_map(static function ($data) {
            return [
                'name'       => $data,
                'guard_name' => 'web'
            ];
        }, $permissionsList);
        Permission::upsert($permissions, ['name'], ['name']);

//        Role::updateOrCreate(['name' => 'Super Admin']);
//        $superAdminHasAccessTo = [
//            'role-list',
//            'role-create',
//            'role-update',
//            'role-delete',
//        ];
//        $role->syncPermissions($superAdminHasAccessTo);
        /*Create Settings which are new & ignore the old values*/
        Setting::insertOrIgnore(DefaultSettingService::get());
    }

    /**
     * @param array {
     * <pre>
     *  permission_name :array<string> array {
     *      * : string // List , Create , Edit , Delete
     *      only : string // List , Create , Edit , Delete
     *      custom: array { // custom permissions will be prefixed with permission_name eg. permission_name-permission1
     *          permission1: string,
     *          permission2: string,
     *      }
     *  }
     * } $permission
     * @return array
     */
    public static function generatePermissionList($permissions) {
        $permissionList = [];
        foreach ($permissions as $name => $permission) {
            $defaultPermission = [
                $name . "-list",
                $name . "-create",
                $name . "-update",
                $name . "-delete"
            ];
            if (is_array($permission)) {
                // * OR only param either is required
                if (in_array("*", $permission, true)) {
                    $permissionList = array_merge($permissionList ?? [], $defaultPermission);
                } else if (array_key_exists("only", $permission)) {
                    foreach ($permission["only"] as $row) {
                        $permissionList[] = $name . "-" . strtolower($row);
                    }
                }

                if (array_key_exists("custom", $permission)) {
                    foreach ($permission["custom"] as $customPermission) {
                        $permissionList[] = $name . "-" . $customPermission;
                    }
                }
            } else {
                $permissionList = array_merge($permissionList ?? [], $defaultPermission);
            }
        }
        return $permissionList;
    }
}
