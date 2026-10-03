<?php

use App\Services\ModuleAccessService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (ModuleAccessService::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['updated_at' => now(), 'created_at' => now()]
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_values(ModuleAccessService::PERMISSIONS))
            ->pluck('id');
        $reservedRoleIds = DB::table('roles')->whereIn('id', [1, 2])->pluck('id');
        foreach ($reservedRoleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
        app('cache')->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        throw new RuntimeException('Commerce module access permissions require an explicit downgrade review.');
    }
};
