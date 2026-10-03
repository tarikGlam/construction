<?php

use App\Services\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('roles') || !Schema::hasTable('role_has_permissions')) {
            return;
        }

        foreach (ModuleRegistry::accessPermissions() as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        $adminRoleIds = DB::table('roles')
            ->whereIn('id', [1, 2])
            ->where('is_active', true)
            ->pluck('id');
        $allBackendRoleIds = DB::table('roles')
            ->where('is_active', true)
            ->where('id', '!=', 5)
            ->pluck('id');

        foreach (ModuleRegistry::all() as $slug => $definition) {
            $accessId = DB::table('permissions')
                ->where('name', $definition['access_permission'])
                ->where('guard_name', 'web')
                ->value('id');
            if (!$accessId) {
                continue;
            }

            $policy = $definition['legacy_access'] ?? 'permissions';
            if ($policy === 'all_roles') {
                $roleIds = $allBackendRoleIds;
            } elseif ($policy === 'admins') {
                $roleIds = $adminRoleIds;
            } else {
                $legacyPermissionIds = DB::table('permissions')
                    ->where('guard_name', 'web')
                    ->whereIn('name', ModuleRegistry::legacyPermissionNames($slug))
                    ->pluck('id');

                $roleIds = $legacyPermissionIds->isEmpty()
                    ? collect()
                    : DB::table('role_has_permissions')
                        ->whereIn('permission_id', $legacyPermissionIds)
                        ->pluck('role_id')
                        ->unique();
                $roleIds = $roleIds->merge($adminRoleIds)->unique();
            }

            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $accessId,
                ]);
            }
        }

        if (Schema::hasTable('general_settings')) {
            $settings = DB::table('general_settings')->latest('id')->first();
            if ($settings) {
                // SaaS historically stored only a subset of addons in `modules`.
                // Existing Admin granular permissions are reliable evidence that
                // a package already included a permission-driven module, so append
                // those slugs without granting any new package entitlement.
                if (config('database.connections.saleprosaas_landlord')
                    && Schema::hasColumn('general_settings', 'modules')) {
                    $packageModules = $this->csv((string) ($settings->modules ?? ''));
                    foreach (ModuleRegistry::all() as $slug => $definition) {
                        if (in_array($slug, $packageModules, true)) {
                            continue;
                        }
                        $legacyNames = ModuleRegistry::legacyPermissionNames($slug);
                        if ($legacyNames === []) {
                            continue;
                        }
                        $hasLegacyAdminPermission = DB::table('permissions')
                            ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                            ->where('role_has_permissions.role_id', 1)
                            ->where('permissions.guard_name', 'web')
                            ->whereIn('permissions.name', $legacyNames)
                            ->exists();
                        if ($hasLegacyAdminPermission) {
                            $packageModules[] = $slug;
                        }
                    }
                    DB::table('general_settings')->where('id', $settings->id)->update([
                        'modules' => implode(',', array_values(array_unique($packageModules))),
                    ]);
                }
            }
        }

        app('cache')->forget('spatie.permission.cache');
        app('cache')->forget('permissions');
        app('cache')->forget('general_setting');
    }

    public function down(): void
    {
        throw new RuntimeException('Extended business module controls require an explicit downgrade review.');
    }

    private function csv(string $value): array
    {
        return array_values(array_filter(array_map(
            fn ($item) => strtolower(trim($item)),
            explode(',', $value)
        )));
    }
};
