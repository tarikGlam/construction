<?php

namespace Modules\AIAssistant\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;

class PageContextResolver
{
    /**
     * Allowed known core application domains and modular extensions.
     */
    private const ALLOWED_MODULES = [
        'sales',
        'purchases',
        'inventory',
        'accounting',
        'people',
        'reports',
        'settings',
        'ecommerce',
        'woocommerce',
        'restaurant',
        'manufacturing',
        'repair',
        'project',
        'aiassistant',
    ];

    /**
     * Resolves raw client-submitted page context into a validated, trusted context array.
     * Never trusts raw client input.
     *
     * @param User|null $user Authenticated user
     * @param bool $isRestrictedWarehouseAccess Whether user is warehouse restricted
     * @param int[] $allowedWarehouseIds Warehouses authorized for this user
     * @param array<string, mixed> $rawContext Untrusted client payload
     * @return array<string, mixed> Validated trusted context
     */
    public function resolve(
        ?User $user,
        bool $isRestrictedWarehouseAccess,
        array $allowedWarehouseIds,
        array $rawContext = []
    ): array {
        if (empty($rawContext)) {
            return [];
        }

        $trusted = [];

        // 1. Sanitize route/page string
        if (!empty($rawContext['page']) && is_string($rawContext['page'])) {
            $page = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', substr(trim($rawContext['page']), 0, 100));
            if ($page !== '') {
                $trusted['page'] = $page;
            }
        }

        // 2. Validate module hint (must be known allowed and enabled)
        if (!empty($rawContext['module']) && is_string($rawContext['module'])) {
            $module = $this->validateModule($rawContext['module']);
            if ($module !== null) {
                $trusted['module'] = $module;
            }
        }

        // 3. Validate warehouse_id
        if (!empty($rawContext['warehouse_id'])) {
            $candidateWhId = (int) $rawContext['warehouse_id'];
            if ($candidateWhId > 0) {
                if ($isRestrictedWarehouseAccess) {
                    // Restricted user may ONLY specify a warehouse within their authorized list
                    if (in_array($candidateWhId, $allowedWarehouseIds, true)) {
                        $trusted['warehouse_id'] = $candidateWhId;
                    }
                } else {
                    // Global user: verify warehouse actually exists and is active
                    if (Warehouse::where('id', $candidateWhId)->where('is_active', true)->exists()) {
                        $trusted['warehouse_id'] = $candidateWhId;
                    }
                }
            }
        }

        // 4. Validate entity type, ID, load entity, establish ownership & permissions
        $entityType = isset($rawContext['entity_type']) && is_string($rawContext['entity_type'])
            ? strtolower(trim($rawContext['entity_type']))
            : null;
        $entityId = isset($rawContext['entity_id']) ? (int) $rawContext['entity_id'] : 0;

        if ($user && $entityType && $entityId > 0) {
            $resolvedEntity = $this->resolveEntity($user, $entityType, $entityId, $isRestrictedWarehouseAccess, $allowedWarehouseIds);
            if ($resolvedEntity !== null) {
                $trusted['entity_type'] = $resolvedEntity['type'];
                $trusted['entity_id'] = $resolvedEntity['id'];
                $trusted['entity_name'] = $resolvedEntity['name'];
            }
        }

        return $trusted;
    }

    /**
     * Validate module identifier against known/allowed list and operational status.
     */
    private function validateModule(string $candidate): ?string
    {
        $normalized = strtolower(trim($candidate));
        if (!in_array($normalized, self::ALLOWED_MODULES, true)) {
            return null;
        }

        // For modular extensions, verify enabled status where Module facade exists
        if (in_array($normalized, ['ecommerce', 'woocommerce', 'restaurant', 'manufacturing', 'repair', 'project', 'aiassistant'], true)) {
            if (class_exists(\Nwidart\Modules\Facades\Module::class)) {
                $module = \Nwidart\Modules\Facades\Module::find($normalized);
                if ($module !== null && !$module->isEnabled()) {
                    return null;
                }
            }
        }

        return $normalized;
    }

    /**
     * Resolve, load, and authorize entity.
     */
    private function resolveEntity(
        User $user,
        string $type,
        int $id,
        bool $isRestrictedWarehouseAccess,
        array $allowedWarehouseIds
    ): ?array {
        $hasPermission = function (string $permission) use ($user): bool {
            if ((int) $user->role_id <= 2) {
                return true;
            }

            try {
                return $user->can($permission);
            } catch (\Throwable) {
                return false;
            }
        };

        return match ($type) {
            'customer' => $this->resolveCustomer($id, $hasPermission('customers-index')),
            'supplier' => $this->resolveSupplier($id, $hasPermission('suppliers-index')),
            'product' => $this->resolveProduct($id, $hasPermission('products-index')),
            default => null,
        };
    }

    private function resolveCustomer(int $id, bool $authorized): ?array
    {
        if (!$authorized) {
            return null;
        }

        try {
            $customer = Customer::where('id', $id)->where('is_active', true)->first(['id', 'name']);
            if (!$customer) {
                return null;
            }

            return [
                'type' => 'customer',
                'id' => (int) $customer->id,
                'name' => (string) $customer->name,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveSupplier(int $id, bool $authorized): ?array
    {
        if (!$authorized) {
            return null;
        }

        try {
            $supplier = Supplier::where('id', $id)->where('is_active', true)->first(['id', 'name']);
            if (!$supplier) {
                return null;
            }

            return [
                'type' => 'supplier',
                'id' => (int) $supplier->id,
                'name' => (string) $supplier->name,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveProduct(int $id, bool $authorized): ?array
    {
        if (!$authorized) {
            return null;
        }

        try {
            $product = Product::where('id', $id)->where('is_active', true)->first(['id', 'name']);
            if (!$product) {
                return null;
            }

            return [
                'type' => 'product',
                'id' => (int) $product->id,
                'name' => (string) $product->name,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
