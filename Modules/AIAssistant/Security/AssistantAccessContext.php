<?php

namespace Modules\AIAssistant\Security;

use App\Models\User;
use App\Services\ModuleAccessService;
use App\Services\WarehouseAccessService;
use Illuminate\Support\Facades\Cache;
use Modules\AIAssistant\DTO\WarehouseScope;
use Nwidart\Modules\Facades\Module;

/**
 * Immutable server-side access and security context for the SalePro AI Assistant.
 *
 * Resolves authorization, warehouse scoping, module enablement, and tenant boundaries
 * directly from authoritative SalePro services. Fail-closed by default.
 */
final readonly class AssistantAccessContext
{
    /**
     * @param User|null $user The authenticated user executing the assistant request
     * @param string|null $tenantId Current tenant identifier if tenancy applies
     * @param string $warehouseClassification Classification from WarehouseAccessService
     * @param bool $isGlobalWarehouseAccess True if user has unrestricted multi-warehouse access
     * @param bool $isRestrictedWarehouseAccess True if restricted to a specific warehouse set
     * @param array<int> $allowedWarehouseIds List of allowed warehouse IDs (empty if no access or global)
     * @param bool $isPortalUser True if user is a customer portal identity (role_id = 5)
     * @param int|null $portalCustomerId Linked customer record ID for portal identity
     * @param int|null $ownUserId Set if staff_access is 'own' and user is not global
     * @param array<string, mixed> $pageContext Validated page/entity context from request
     */
    public function __construct(
        public ?User $user,
        public ?string $tenantId,
        public string $warehouseClassification,
        public bool $isGlobalWarehouseAccess,
        public bool $isRestrictedWarehouseAccess,
        public array $allowedWarehouseIds = [],
        public bool $isPortalUser = false,
        public ?int $portalCustomerId = null,
        public ?int $ownUserId = null,
        public array $pageContext = [],
    ) {}

    /**
     * Build an immutable AssistantAccessContext from authoritative SalePro services.
     */
    public static function fromUser(
        ?User $user,
        array $pageContext = [],
        ?string $tenantId = null,
        ?WarehouseAccessService $warehouseAccess = null,
        ?ModuleAccessService $moduleAccess = null
    ): self {
        $warehouseAccess ??= app(WarehouseAccessService::class);

        $resolvedTenantId = $tenantId;
        if ($resolvedTenantId === null && function_exists('tenant') && tenant('id')) {
            $resolvedTenantId = (string) tenant('id');
        }

        if (!$user) {
            return new self(
                user: null,
                tenantId: $resolvedTenantId,
                warehouseClassification: WarehouseAccessService::INVALID_OPERATIONAL,
                isGlobalWarehouseAccess: false,
                isRestrictedWarehouseAccess: true,
                allowedWarehouseIds: [],
                isPortalUser: false,
                portalCustomerId: null,
                ownUserId: null,
                pageContext: $pageContext
            );
        }

        $classification = $warehouseAccess->classification($user);
        $isGlobal = $warehouseAccess->isGlobal($user);
        $isPortal = $warehouseAccess->isPortalIdentity($user);
        $portalCustomerId = $warehouseAccess->portalCustomerId($user);

        $allowedWarehouseIds = [];
        $isRestricted = false;
        $ownUserId = null;

        if ($isGlobal) {
            $isGlobalWarehouseAccess = true;
            $isRestricted = false;
            $allowedWarehouseIds = [];
        } elseif ($isPortal) {
            $isGlobalWarehouseAccess = false;
            $isRestricted = true;
            $allowedWarehouseIds = []; // Portal identity cannot view warehouse internal operations
        } elseif ($classification === WarehouseAccessService::WAREHOUSE_OPERATIONAL) {
            $isGlobalWarehouseAccess = false;
            $isRestricted = true;
            $whId = $warehouseAccess->warehouseId($user);
            $allowedWarehouseIds = $whId ? [(int) $whId] : [];
        } else {
            // INVALID_OPERATIONAL or unrecognized: fail closed with empty allowed IDs
            $isGlobalWarehouseAccess = false;
            $isRestricted = true;
            $allowedWarehouseIds = [];
        }

        // Check staff_access == 'own' for non-global staff
        if (!$isGlobal && !$isPortal) {
            $generalSetting = function_exists('gen_setting') ? gen_setting() : (Cache::get('general_setting') ?? \Illuminate\Support\Facades\DB::table('general_settings')->latest()->first());
            $staffAccess = optional($generalSetting)->staff_access ?? 'all';
            if ($staffAccess === 'own') {
                $ownUserId = $user->id;
            }
        }

        $trustedPageContext = app(\Modules\AIAssistant\Services\PageContextResolver::class)->resolve(
            $user,
            $isRestricted,
            $allowedWarehouseIds,
            $pageContext
        );

        return new self(
            user: $user,
            tenantId: $resolvedTenantId,
            warehouseClassification: $classification,
            isGlobalWarehouseAccess: $isGlobalWarehouseAccess,
            isRestrictedWarehouseAccess: $isRestricted,
            allowedWarehouseIds: $allowedWarehouseIds,
            isPortalUser: $isPortal,
            portalCustomerId: $portalCustomerId,
            ownUserId: $ownUserId,
            pageContext: $trustedPageContext
        );
    }

    /**
     * Check if the authenticated user has a specific permission. Fail-closed.
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->user) {
            return false;
        }

        // Admin / Owner roles have all permissions by system policy
        if ((int) $this->user->role_id <= 2) {
            return true;
        }

        return $this->user->can($permission);
    }

    /**
     * Check if the authenticated user has ANY of the specified permissions. Fail-closed.
     *
     * @param array<string> $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if (!$this->user) {
            return false;
        }

        if (empty($permissions)) {
            return true;
        }

        if ((int) $this->user->role_id <= 2) {
            return true;
        }

        foreach ($permissions as $perm) {
            if ($this->user->can($perm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the authenticated user has ALL of the specified permissions. Fail-closed.
     *
     * @param array<string> $permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        if (!$this->user) {
            return false;
        }

        if (empty($permissions)) {
            return true;
        }

        if ((int) $this->user->role_id <= 2) {
            return true;
        }

        foreach ($permissions as $perm) {
            if (!$this->user->can($perm)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether a specific SalePro module is installed and operational.
     */
    public function isModuleAvailable(string $module): bool
    {
        $slug = strtolower(trim($module));

        // Bundled modules managed by ModuleAccessService
        if (in_array($slug, ['ecommerce', 'woocommerce'], true)) {
            /** @var ModuleAccessService $moduleAccess */
            $moduleAccess = app(ModuleAccessService::class);
            return $moduleAccess->isOperational($slug);
        }

        // Other Nwidart modules (Manufacturing, Restaurant, Repair, etc.)
        $discovered = Module::find($module);
        if (!$discovered || !$discovered->isEnabled()) {
            return false;
        }

        return true;
    }

    /**
     * Convert this access context into a WarehouseScope value object for legacy skill compatibility.
     */
    public function toWarehouseScope(): WarehouseScope
    {
        if ($this->isGlobalWarehouseAccess) {
            return new WarehouseScope(isRestricted: false, warehouseIds: [], ownUserId: null);
        }

        if ($this->ownUserId !== null) {
            return new WarehouseScope(isRestricted: false, warehouseIds: [], ownUserId: $this->ownUserId);
        }

        return new WarehouseScope(
            isRestricted: $this->isRestrictedWarehouseAccess,
            warehouseIds: $this->allowedWarehouseIds,
            ownUserId: null
        );
    }

    /**
     * Export business context array for AssistantContextData.
     *
     * @return array<string, mixed>
     */
    public function toBusinessContext(): array
    {
        $ctx = [];
        if ($this->ownUserId !== null) {
            $ctx['own_user_id'] = $this->ownUserId;
        } elseif ($this->isRestrictedWarehouseAccess) {
            $ctx['warehouse_ids'] = $this->allowedWarehouseIds;
        }
        if ($this->isPortalUser && $this->portalCustomerId !== null) {
            $ctx['customer_id'] = $this->portalCustomerId;
        }
        if (!empty($this->pageContext)) {
            $ctx['page_context'] = $this->pageContext;
        }

        return $ctx;
    }
}
