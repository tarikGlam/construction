<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'business_reset', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(\App\Services\PermissionService::class)->clearRolePermissionsCache();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'business_reset')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(\App\Services\PermissionService::class)->clearRolePermissionsCache();
    }
};
