<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Must be idempotent
        $permissions = [
            'pending_collections-create',
            'pending_collections-index',
            'pending_collections-handover',
            'pending_collections-approve',
            'pending_collections-reject',
            'pending_collections-reverse',
            'pending_collections-bypass',
            'pending_collections-self_approve',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Apply to Admin and Owner roles
        $adminRoles = Role::whereIn('name', ['Admin', 'Owner'])->orWhereIn('id', [1, 2])->get();
        foreach ($adminRoles as $role) {
            $role->givePermissionTo('pending_collections-bypass');
            // Ensure not self_approve
            if ($role->hasPermissionTo('pending_collections-self_approve')) {
                $role->revokePermissionTo('pending_collections-self_approve');
            }
        }
    }

    public function down(): void
    {
        // Permissions are generally not deleted to prevent dangling foreign key issues,
        // but can be manually removed if necessary.
    }
};
