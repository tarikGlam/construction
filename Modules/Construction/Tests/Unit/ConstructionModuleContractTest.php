<?php

namespace Modules\Construction\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ConstructionModuleContractTest extends TestCase
{
    public function test_module_exposes_required_demo_workflows(): void
    {
        $routes = file_get_contents(__DIR__.'/../../Routes/web.php');
        foreach (['project-costs', 'material-issues', 'subcontractors', 'project-wages', 'equipment-assignments', 'project-receipts', 'project-profitability', 'project-statement'] as $workflow) {
            $this->assertStringContainsString($workflow, $routes);
        }
    }

    public function test_stock_movements_are_transactional_and_ledger_visible(): void
    {
        $inventory = file_get_contents(__DIR__.'/../../Services/InventoryMovementService.php');
        $ledger = file_get_contents(dirname(__DIR__, 4).'/app/Services/StockLedgerService.php');
        $this->assertStringContainsString('DB::transaction', $inventory);
        $this->assertStringContainsString('lockForUpdate', $inventory);
        $this->assertStringContainsString("'material_issue'", $ledger);
        $this->assertStringContainsString("'material_return'", $ledger);
    }

    public function test_construction_sidebar_has_no_retail_navigation(): void
    {
        $sidebar = file_get_contents(dirname(__DIR__, 4).'/resources/views/backend/layout/sidebar.blade.php');
        foreach (['POS', 'Gift Card', 'Coupon', 'Sale Agent', 'Biller', 'Restaurant', 'WooCommerce', 'Ecommerce'] as $retailLabel) {
            $this->assertStringNotContainsString($retailLabel, $sidebar);
        }
    }
}
