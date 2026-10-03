<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    private const PERMISSIONS = [
        'import_batch-index',
        'import_batch-add',
        'import_batch-edit',
        'import_batch-delete',
        'import_batch-landed-cost',
        'import_batch-profit-report',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Give permissions to Admin and Owner roles
        $roles = Role::whereIn('id', [1, 2])->get();
        foreach ($roles as $role) {
            $role->givePermissionTo(self::PERMISSIONS);
        }
    }

    public function down(): void
    {
        $permissions = Permission::whereIn('name', self::PERMISSIONS)->get();
        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
