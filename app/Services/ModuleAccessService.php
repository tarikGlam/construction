<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nwidart\Modules\Facades\Module;

class ModuleAccessService
{
    /** @deprecated use ModuleRegistry::accessPermissions() */
    public const PERMISSIONS = [
        'ecommerce' => 'module.ecommerce.access',
        'woocommerce' => 'module.woocommerce.access',
        'repair' => 'module.repair.access',
    ];

    public function normalize(string $module): string
    {
        $slug = strtolower(trim($module));
        if (!ModuleRegistry::get($slug)) {
            throw new InvalidArgumentException("Unknown bundled module [{$module}].");
        }
        return $slug;
    }

    public function definition(string $module): array
    {
        return ModuleRegistry::get($this->normalize($module));
    }

    public function isInstalled(string $module): bool
    {
        $definition = $this->definition($module);
        $path = base_path('Modules/'.$definition['module_name']);

        return is_dir($path) && Module::find($definition['module_name']) !== null;
    }

    public function isCodeEnabled(string $module): bool
    {
        $definition = $this->definition($module);
        if (! $this->isInstalled($module)) {
            return false;
        }
        $discovered = Module::find($definition['module_name']);

        return $discovered !== null && $discovered->isEnabled();
    }

    /** Package / licence availability. Existing general_settings.modules semantics are preserved for SaaS. */
    public function tenantCanAccess(string $module): bool
    {
        $slug = $this->normalize($module);
        if (!config('database.connections.saleprosaas_landlord')) {
            return true;
        }
        if (!Schema::hasTable('general_settings') || !Schema::hasColumn('general_settings', 'modules')) {
            return false;
        }
        $modules = DB::table('general_settings')->latest('id')->value('modules');
        return in_array($slug, $this->csv((string) $modules), true);
    }

    /** Business-level master switch. Defaults ON so upgrades never disable existing modules. */
    public function businessEnabled(string $module): bool
    {
        $slug = $this->normalize($module);
        if (!$this->isInstalled($slug) || !$this->isCodeEnabled($slug) || !$this->tenantCanAccess($slug)) {
            return false;
        }
        if (!Schema::hasTable('general_settings') || !Schema::hasColumn('general_settings', 'disabled_modules')) {
            return true;
        }
        $disabled = DB::table('general_settings')->latest('id')->value('disabled_modules');
        return !in_array($slug, $this->csv((string) $disabled), true);
    }

    public function availableModules(): array
    {
        return array_keys(array_filter(ModuleRegistry::all(), fn ($definition, $slug) =>
            $this->isInstalled($slug) && $this->isCodeEnabled($slug) && $this->tenantCanAccess($slug), ARRAY_FILTER_USE_BOTH));
    }

    /** Initialize the business switch once without overwriting an explicit choice. */
    public function initializeBusinessModulesIfUnconfigured(): bool
    {
        if (!Schema::hasTable('general_settings') || !Schema::hasColumn('general_settings', 'disabled_modules')) {
            return false;
        }

        $settings = DB::table('general_settings')->latest('id')->first();
        if (!$settings || $settings->disabled_modules !== null) {
            return false;
        }

        $this->setBusinessEnabledModules($this->availableModules());

        return true;
    }

    public function setBusinessEnabledModules(array $enabledModules): void
    {
        $available = $this->availableModules();
        $enabled = array_values(array_intersect($available, array_map(fn ($v) => $this->normalize((string) $v), $enabledModules)));
        $disabled = array_values(array_diff($available, $enabled));
        DB::table('general_settings')->where('id', DB::table('general_settings')->max('id'))->update([
            'disabled_modules' => implode(',', $disabled),
            'updated_at' => now(),
        ]);
        cache()->forget('general_setting');
    }

    public function roleCanAccess(object|int|null $role, string $module): bool
    {
        $slug = $this->normalize($module);
        $definition = $this->definition($slug);
        $role = is_int($role) ? DB::table('roles')->find($role) : $role;
        if (!$role || !(bool) ($role->is_active ?? false)) return false;
        $roleId = (int) ($role->id ?? 0);
        if ($roleId === 5) return false;
        if (in_array($roleId, [1, 2], true)) return true;

        $accessPermission = $definition['access_permission'];
        $permissionExists = DB::table('permissions')->where('name', $accessPermission)->exists();
        if ($permissionExists) {
            return $this->roleHasPermission($roleId, $accessPermission);
        }

        // Upgrade safety: before the new master permission is seeded, preserve legacy access.
        foreach (ModuleRegistry::legacyPermissionNames($slug) as $legacyPermission) {
            if ($this->roleHasPermission($roleId, $legacyPermission)) return true;
        }
        return false;
    }

    public function userCanAccess(?User $user, string $module): bool
    {
        if (!$user || !(bool) $user->is_active || (bool) $user->is_deleted) return false;
        return $this->businessEnabled($module)
            && $this->roleCanAccess(DB::table('roles')->find($user->role_id), $module);
    }

    public function can(?User $user, string $module, string $permission): bool
    {
        if (!$this->userCanAccess($user, $module)) return false;
        if (in_array((int) $user->role_id, [1, 2], true)) return true;
        return $this->roleHasPermission((int) $user->role_id, $permission);
    }

    public function isOperational(string $module): bool
    {
        $slug = $this->normalize($module);
        if (!$this->businessEnabled($slug)) return false;
        if ($slug === 'ecommerce') {
            return Schema::hasTable('ecommerce_settings')
                && Schema::hasColumn('ecommerce_settings', 'storefront_enabled')
                && (bool) DB::table('ecommerce_settings')->latest('id')->value('storefront_enabled');
        }
        if ($slug === 'woocommerce') {
            return Schema::hasTable('woocommerce_settings')
                && Schema::hasColumn('woocommerce_settings', 'sync_enabled')
                && (bool) DB::table('woocommerce_settings')->latest('id')->value('sync_enabled');
        }
        return true;
    }

    public function assertUserCanAccess(?User $user, string $module): void
    {
        if (!$this->userCanAccess($user, $module)) {
            throw new AuthorizationException(__('db.module_access_denied'));
        }
    }

    public function assertBusinessEnabled(string $module): void
    {
        if (!$this->businessEnabled($module)) {
            throw new AuthorizationException(__('db.module_access_denied'));
        }
    }

    private function roleHasPermission(int $roleId, string $permission): bool
    {
        return DB::table('permissions')
            ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)
            ->where('permissions.name', $permission)
            ->where(fn ($query) => $query->where('permissions.guard_name', 'web')->orWhereNull('permissions.guard_name'))
            ->exists();
    }

    private function csv(string $value): array
    {
        return array_values(array_filter(array_map(fn ($item) => strtolower(trim($item)), explode(',', $value))));
    }
}
