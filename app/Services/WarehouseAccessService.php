<?php

namespace App\Services;

use App\Models\User;
use App\Models\Customer;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

class WarehouseAccessService
{
    public const GLOBAL_OPERATIONAL = 'global_operational';
    public const WAREHOUSE_OPERATIONAL = 'warehouse_operational';
    public const PORTAL_IDENTITY = 'portal_identity';
    public const INVALID_OPERATIONAL = 'invalid_operational';

    public function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function isGlobal(?User $user = null): bool
    {
        $user ??= $this->user();

        return !$user
            || $this->roleGrantsGlobalWarehouseAccess((int) $user->role_id);
    }

    public function portalCustomerId(?User $user = null): ?int
    {
        $user ??= $this->user();
        if (!$user || (int) $user->role_id !== 5) {
            return null;
        }

        return Customer::where('user_id', $user->id)->where('is_active', true)->value('id');
    }

    public function isPortalIdentity(?User $user = null): bool
    {
        return (bool) $this->portalCustomerId($user);
    }

    public function isWarehouseOperational(?User $user = null): bool
    {
        $user ??= $this->user();
        return $user && !$this->isGlobal($user) && (int) $user->role_id !== 5;
    }

    public function hasValidWarehouseAssignment(?User $user = null): bool
    {
        $user ??= $this->user();
        return $this->isWarehouseOperational($user)
            && (int) $user->warehouse_id > 0
            && Warehouse::withoutGlobalScope('authorized_warehouse')
                ->whereKey($user->warehouse_id)->where('is_active', true)->exists();
    }

    public function classification(?User $user = null): string
    {
        $user ??= $this->user();
        if ($this->isGlobal($user)) return self::GLOBAL_OPERATIONAL;
        if ($this->isPortalIdentity($user)) return self::PORTAL_IDENTITY;
        if ($this->hasValidWarehouseAssignment($user)) return self::WAREHOUSE_OPERATIONAL;
        return self::INVALID_OPERATIONAL;
    }

    public function isRestricted(?User $user = null): bool
    {
        $user ??= $this->user();

        return $user && $this->isWarehouseOperational($user);
    }

    public function warehouseId(?User $user = null): ?int
    {
        $user ??= $this->user();

        return $this->hasValidWarehouseAssignment($user)
            ? (int) $user->warehouse_id
            : null;
    }

    public function scope(Builder|QueryBuilder $query, string $column = 'warehouse_id'): Builder|QueryBuilder
    {
        if ($this->isPortalIdentity()) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->isRestricted()) {
            $warehouseId = $this->warehouseId();
            $warehouseId
                ? $query->where($column, $warehouseId)
                : $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function authorizeWarehouse(?int $warehouseId, ?User $user = null): void
    {
        $user ??= $this->user();
        if (!$this->isRestricted($user)) {
            return;
        }

        $allowed = $this->warehouseId($user);
        abort_if(!$allowed || (int) $warehouseId !== $allowed, 403, 'Warehouse access denied.');
    }

    public function assertValidUserAssignment(int $roleId, mixed $warehouseId, bool $portalWillBeLinked = false, ?User $existing = null): void
    {
        $targetRole = Role::whereKey($roleId)->where('is_active', true)->first();
        if (!$targetRole) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'role_id' => 'The selected role is invalid or inactive.',
            ]);
        }

        if ($this->roleGrantsGlobalWarehouseAccess($targetRole)) {
            if (!$this->isGlobal()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'role_id' => 'Only a global administrator may assign an unrestricted role.',
                ]);
            }
            return;
        }

        if ($roleId === 5) {
            if ($portalWillBeLinked || ($existing && $this->isPortalIdentity($existing))) return;
            throw \Illuminate\Validation\ValidationException::withMessages([
                'role_id' => 'A Customer role requires a valid linked customer record.',
            ]);
        }

        if (!(int) $warehouseId || !Warehouse::withoutGlobalScope('authorized_warehouse')->whereKey((int) $warehouseId)->where('is_active', true)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'warehouse_id' => 'An active warehouse is required for operational users.',
            ]);
        }
    }

    public function roleGrantsGlobalWarehouseAccess(Role|int $role): bool
    {
        $roleId = $role instanceof Role ? (int) $role->id : (int) $role;

        // Only the two reserved positive role IDs are global by system policy.
        // A missing/invalid role must never inherit unrestricted access.
        if ($roleId >= 1 && $roleId <= 2) {
            return true;
        }

        $roleModel = $role instanceof Role ? $role : Role::find($roleId);

        return $roleModel !== null && $roleModel->permissions->contains('name', 'all-warehouses');
    }

    public function canAssignRole(Role|int $role): bool
    {
        return $this->isGlobal() || !$this->roleGrantsGlobalWarehouseAccess($role);
    }
}
