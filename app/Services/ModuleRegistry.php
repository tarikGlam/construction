<?php

namespace App\Services;

final class ModuleRegistry
{
    /**
     * Canonical registry for optional SalePro modules.
     *
     * Existing granular permission names are intentionally preserved. The
     * module.*.access permission is an additional master role gate only.
     */
    public const MODULES = [
        'construction' => [
            'module_name' => 'Construction',
            'label' => 'Construction ERP',
            'group' => 'Core',
            'description' => 'Project costing, material control, subcontractors, workforce, equipment and construction reporting.',
            'access_permission' => 'module.construction.access',
            'permissions' => [
                'construction.dashboard.view' => 'View construction dashboard',
                'construction.project-costs.manage' => 'Manage project costs',
                'construction.material-issues.manage' => 'Manage material issues',
                'construction.material-returns.manage' => 'Manage material returns',
                'construction.subcontractors.manage' => 'Manage subcontractors and contracts',
                'construction.project-wages.manage' => 'Manage project workforce, rewards, expenses and advances',
                'construction.equipment.manage' => 'Manage equipment and assignments',
                'construction.project-receipts.manage' => 'Manage project receipts',
                'construction.shareholders.manage' => 'Manage shareholders and shareholder transactions',
                'construction.procurement.view' => 'View project procurement and logistics',
                'construction.transport.manage' => 'Manage transport records',
                'construction.supplier-items.manage' => 'Manage supplier-item relationships',
                'construction.reports.view' => 'View construction reports',
            ],
            'legacy_access' => 'admins',
        ],
        'ecommerce' => [
            'module_name' => 'Ecommerce',
            'label' => 'eCommerce',
            'group' => 'Commerce',
            'description' => 'Online storefront, CMS, collections, reviews and eCommerce product fields.',
            'access_permission' => 'module.ecommerce.access',
            'permissions' => [],
            'legacy_access' => 'all_roles',
        ],
        'woocommerce' => [
            'module_name' => 'Woocommerce',
            'label' => 'WooCommerce',
            'group' => 'Commerce',
            'description' => 'WooCommerce connector, synchronization, credentials and mappings.',
            'access_permission' => 'module.woocommerce.access',
            'permissions' => [
                'woocommerce.sync' => 'Run WooCommerce synchronization',
                'woocommerce.manage' => 'Manage WooCommerce credentials, mappings, and settings',
            ],
            'legacy_access' => 'permissions',
        ],
        'manufacturing' => [
            'module_name' => 'Manufacturing',
            'label' => 'Manufacturing',
            'group' => 'Operations',
            'description' => 'Production, recipes and manufacturing workflows.',
            'access_permission' => 'module.manufacturing.access',
            'permissions' => [
                'production-view' => 'Production List',
                'production-add' => 'Add Production',
                'production-edit' => 'Edit Production',
                'production-delete' => 'Delete Production',
                'recipe-view' => 'Recipe List',
                'recipe-add' => 'Add Recipe',
                'recipe-edit' => 'Edit Recipe',
                'recipe-delete' => 'Delete Recipe',
            ],
            'legacy_access' => 'permissions',
        ],
        'repair' => [
            'module_name' => 'Repair',
            'label' => 'Repair',
            'group' => 'Operations',
            'description' => 'Repair/service jobs, device types, parts, charges and repair payments.',
            'access_permission' => 'module.repair.access',
            'permissions' => [
                'repair-dashboard' => 'Repair Dashboard',
                'repair-service-index' => 'Service Jobs',
                'repair-service-view' => 'View Service Jobs',
                'repair-service-add' => 'Add Service Job',
                'repair-service-edit' => 'Edit Service Job',
                'repair-service-delete' => 'Delete Service Job',
                'repair-parts-view' => 'View Parts and Billing',
                'repair-parts-add' => 'Add Part',
                'repair-parts-edit' => 'Edit Parts (Qty / Price)',
                'repair-parts-delete' => 'Remove Parts',
                'repair-charges-edit' => 'Edit Service Charges',
                'repair-payment-add' => 'Collect Payment',
                'repair-payment-delete' => 'Delete Payment',
                'repair-device-type' => 'Device Type',
            ],
            'legacy_access' => 'permissions',
        ],
        'project' => [
            'module_name' => 'Project',
            'label' => 'Project Management',
            'group' => 'Operations',
            'description' => 'Projects, tasks, categories, assignments, discussions and files.',
            'access_permission' => 'module.project.access',
            'permissions' => [
                'project_project_list' => 'Project List',
                'project_project_add' => 'Project Add',
                'project_project_show' => 'Project Show',
                'project_project_edit' => 'Project Edit',
                'project_project_delete' => 'Project Delete',
                'project_task_list' => 'Task List',
                'project_task_add' => 'Task Add',
                'project_task_show' => 'Task Show',
                'project_task_edit' => 'Task Edit',
                'project_task_delete' => 'Task Delete',
                'project_category_list' => 'Project Category List',
                'project_category_add' => 'Project Category Add',
                'project_category_edit' => 'Project Category Edit',
                'project_category_delete' => 'Project Category Delete',
            ],
            'legacy_access' => 'permissions',
        ],
        'restaurant' => [
            'module_name' => 'Restaurant',
            'label' => 'Restaurant',
            'group' => 'Industry',
            'description' => 'Restaurant POS, floors, tables, reservations, menu types, modifiers and kitchen workflows.',
            'access_permission' => 'module.restaurant.access',
            'permissions' => [
                'restaurant-floor' => 'Floors',
                'restaurant-table' => 'Tables',
                'restaurant-reservation' => 'Reservation',
                'restaurant-menu-type' => 'Menu Type',
                'restaurant-modifier-group' => 'Modifier Group',
                'restaurant-kitchen' => 'Kitchen',
                'restaurant-kitchen-dashboard' => 'Kitchen Dashboard',
                'restaurant-pos' => 'Restaurant POS',
            ],
            'legacy_access' => 'permissions',
        ],
        'tailoring' => [
            'module_name' => 'Tailoring',
            'label' => 'Tailoring',
            'group' => 'Industry',
            'description' => 'Measurements, tailoring orders, garment templates, production, trials and alterations.',
            'access_permission' => 'module.tailoring.access',
            'permissions' => [
                'tailoring-dashboard' => 'Tailoring Dashboard',
                'tailoring-measurements-index' => 'Measurement Profiles',
                'tailoring-measurements-add' => 'Add Measurement Profile',
                'tailoring-measurements-edit' => 'Edit Measurement Profile',
                'tailoring-measurements-delete' => 'Delete Measurement Profile',
                'tailoring-orders-index' => 'Tailoring Orders',
                'tailoring-orders-add' => 'Add Tailoring Order',
                'tailoring-orders-edit' => 'Edit Tailoring Order',
                'tailoring-orders-delete' => 'Delete Tailoring Order',
                'tailoring-order-payments-add' => 'Add Tailoring Advance Payment',
                'tailoring-templates-index' => 'Garment Templates',
                'tailoring-templates-add' => 'Add Garment Template',
                'tailoring-templates-edit' => 'Edit Garment Template',
                'tailoring-templates-delete' => 'Delete Garment Template',
                'tailoring-materials-manage' => 'Manage Tailoring Materials',
                'tailoring-production-board' => 'Production Board',
                'tailoring-notifications-manage' => 'Manage Notification Templates',
                'tailoring-notifications-send' => 'Send Tailoring Notifications',
                'tailoring-delivery-manage' => 'Delivery and Final Sale',
                'tailoring-reports' => 'Tailoring Reports',
                'tailoring-trials-view' => 'View Tailoring Trials',
                'tailoring-trials-schedule' => 'Schedule Tailoring Trials',
                'tailoring-trials-complete' => 'Complete Tailoring Trials',
                'tailoring-alterations-manage' => 'Manage Tailoring Alterations',
                'tailoring-alterations-approve' => 'Approve Tailoring Alterations',
                'tailoring-catalog.view' => 'View Tailoring Catalog',
                'tailoring-catalog.manage' => 'Manage Tailoring Catalog',
                'tailoring.collection.manage' => 'Manage Tailoring Collections',
                'tailoring.style.manage' => 'Manage Tailoring Styles',
                'tailoring.style_version.activate' => 'Activate Tailoring Style Versions',
                'tailoring.component_role.manage' => 'Manage Tailoring Component Roles',
                'tailoring.order.price_override' => 'Override Tailoring Order Price',
            ],
            'legacy_access' => 'permissions',
        ],
        'gym' => [
            'module_name' => 'Gym',
            'label' => 'Gym',
            'group' => 'Industry',
            'description' => 'Gym members, attendance, packages, classes and settings.',
            'access_permission' => 'module.gym.access',
            'permissions' => [],
            'legacy_access' => 'all_roles',
        ],
        'socialcommerce' => [
            'module_name' => 'SocialCommerce',
            'label' => 'Social Commerce',
            'group' => 'Commerce',
            'description' => 'Social catalogue, public social product pages, feeds and social-commerce settings.',
            'access_permission' => 'module.socialcommerce.access',
            'permissions' => [
                'view social commerce' => 'Social Commerce Dashboard',
                'manage social catalogue' => 'Manage Social Commerce Catalogue',
                'manage social commerce settings' => 'Manage Social Commerce Settings',
            ],
            'legacy_access' => 'permissions',
        ],
        'aiassistant' => [
            'module_name' => 'AIAssistant',
            'label' => 'AI Assistant',
            'group' => 'Productivity',
            'description' => 'SalePro AI assistant, structured prompts, conversations and business skills.',
            'access_permission' => 'module.aiassistant.access',
            'permissions' => [
                'ai-assistant-index' => 'AI Assistant',
            ],
            'legacy_access' => 'permissions',
        ],
        'vcardnfc' => [
            'module_name' => 'VCardNfc',
            'label' => 'vCard & NFC',
            'group' => 'Productivity',
            'description' => 'Digital vCard profiles, QR codes and NFC card management.',
            'access_permission' => 'module.vcardnfc.access',
            'permissions' => [],
            // Historically this module was owner/admin-only.
            'legacy_access' => 'admins',
        ],
        'indiagst' => [
            'module_name' => 'IndiaGST',
            'label' => 'India GST',
            'group' => 'Compliance',
            'description' => 'India GST profiles, mappings, calculation preview and GST reporting.',
            'access_permission' => 'module.indiagst.access',
            'permissions' => [],
            // Routes previously had no module-specific role permission.
            'legacy_access' => 'all_roles',
        ],
        'zatcaintegrationksa' => [
            'module_name' => 'ZatcaIntegrationKsa',
            'label' => 'ZATCA KSA',
            'group' => 'Compliance',
            'description' => 'Saudi ZATCA onboarding, settings, document processing and retry tools.',
            'access_permission' => 'module.zatcaintegrationksa.access',
            'permissions' => [
                'zatca.dashboard' => 'ZATCA Dashboard',
                'zatca.settings' => 'ZATCA Settings',
                'zatca.onboarding' => 'ZATCA Onboarding',
                'zatca.documents' => 'ZATCA Documents',
                'zatca.retry' => 'Retry ZATCA Documents',
            ],
            'legacy_access' => 'permissions',
        ],
    ];

    public static function all(): array
    {
        return self::MODULES;
    }

    public static function get(string $slug): ?array
    {
        return self::MODULES[strtolower(trim($slug))] ?? null;
    }

    public static function accessPermissions(): array
    {
        return array_map(fn (array $definition) => $definition['access_permission'], self::MODULES);
    }

    public static function permissionNames(string $slug, bool $includeAccess = true): array
    {
        $definition = self::get($slug) ?? [];
        $permissions = array_keys($definition['permissions'] ?? []);
        if ($includeAccess && isset($definition['access_permission'])) {
            array_unshift($permissions, $definition['access_permission']);
        }
        return $permissions;
    }

    /**
     * Permissions that represented module access before the master gate existed.
     */
    public static function legacyPermissionNames(string $slug): array
    {
        $definition = self::get($slug) ?? [];
        return array_values(array_unique(array_merge(
            array_keys($definition['permissions'] ?? []),
            $definition['legacy_access_permissions'] ?? []
        )));
    }
}
