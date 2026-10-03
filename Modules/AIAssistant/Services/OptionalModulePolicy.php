<?php

namespace Modules\AIAssistant\Services;

class OptionalModulePolicy
{
    /**
     * Map of deferred optional module feature keys to their target module and user-friendly explanation.
     *
     * @var array<string, array{module: string, name: string, phase: string}>
     */
    private const DEFERRED_FEATURES = [
        // Restaurant Module
        'table_occupancy' => ['module' => 'Restaurant', 'name' => 'Restaurant Table Occupancy', 'phase' => 'Phase 2'],
        'open_table_orders' => ['module' => 'Restaurant', 'name' => 'Restaurant Open Orders', 'phase' => 'Phase 2'],
        'restaurant_orders' => ['module' => 'Restaurant', 'name' => 'Restaurant Dining Orders', 'phase' => 'Phase 2'],

        // HR & People Module
        'today_attendance' => ['module' => 'HR', 'name' => 'Employee Attendance', 'phase' => 'Phase 2'],
        'headcount_summary' => ['module' => 'HR', 'name' => 'Staff Headcount', 'phase' => 'Phase 2'],
        'payroll_summary' => ['module' => 'HR', 'name' => 'Payroll Summary', 'phase' => 'Phase 2'],

        // Supply Expansion / Manufacturing / Landed Cost
        'quotations_summary' => ['module' => 'CRM', 'name' => 'Quotations Summary', 'phase' => 'Phase 2'],
        'sales_agent_performance' => ['module' => 'CRM', 'name' => 'Sales Agent Performance', 'phase' => 'Phase 2'],
        'batch_expiry_alerts' => ['module' => 'LandedCost', 'name' => 'Batch Expiry Alerts', 'phase' => 'Phase 2'],
        'pending_arrivals' => ['module' => 'Supply', 'name' => 'Pending Purchase Orders', 'phase' => 'Phase 2'],
        'landed_cost_summary' => ['module' => 'LandedCost', 'name' => 'Landed Cost Overview', 'phase' => 'Phase 2'],
    ];

    /**
     * Check if a feature/intent key is part of deferred optional modules.
     */
    public static function isDeferred(string $key): bool
    {
        return isset(self::DEFERRED_FEATURES[$key]);
    }

    /**
     * Get details for a deferred feature.
     *
     * @return array{module: string, name: string, phase: string}|null
     */
    public static function getDeferredDetails(string $key): ?array
    {
        return self::DEFERRED_FEATURES[$key] ?? null;
    }

    /**
     * Construct a standard explanation message for deferred features.
     */
    public static function getDeferredMessage(string $key): string
    {
        $details = self::getDeferredDetails($key);
        if (!$details) {
            return 'This feature belongs to an optional module and is deferred for a future phase.';
        }

        return "The {$details['name']} feature requires the {$details['module']} module and is scheduled for {$details['phase']}. It is not enabled in Phase 1.";
    }
}
