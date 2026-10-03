<?php

namespace Modules\Construction\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ConstructionModuleContractTest extends TestCase
{
    public function test_module_exposes_required_workflows(): void
    {
        $routes = file_get_contents(__DIR__.'/../../Routes/web.php');
        foreach (['project-costs', 'material-issues', 'subcontractors', 'project-wages', 'equipment-assignments', 'project-receipts', 'project-profitability', 'project-statement'] as $workflow) {
            $this->assertStringContainsString($workflow, $routes);
        }
        $this->assertStringContainsString("costs.post", $routes);
        $this->assertStringContainsString("receipts.post", $routes);
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

    public function test_project_costs_and_receipts_bridge_to_core_accounting_engines(): void
    {
        $service = file_get_contents(__DIR__.'/../../Services/ConstructionAccountingService.php');
        $this->assertStringContainsString('recordExpense', $service);
        $this->assertStringContainsString('recordDeposit', $service);
        $this->assertStringContainsString('CustomerDepositService', $service);
        $this->assertStringContainsString('PaymentAccountService', $service);
        $this->assertStringContainsString("deposit_type' => 'live'", $service);
    }

    public function test_project_receipts_are_not_presented_as_earned_revenue(): void
    {
        $receipts = file_get_contents(__DIR__.'/../../Resources/views/receipts.blade.php');
        $statement = file_get_contents(__DIR__.'/../../Resources/views/reports/statement.blade.php');
        $profitability = file_get_contents(__DIR__.'/../../Resources/views/reports/profitability.blade.php');

        $this->assertStringContainsString('Client Advances', $receipts);
        $this->assertStringContainsString('Customer Deposit Liability', $receipts);
        $this->assertStringContainsString('Projected Contract Profit', $statement);
        $this->assertStringContainsString('Remaining Contract Amount', $statement);
        $this->assertStringContainsString('Projected Contract Profit', $profitability);
    }

    public function test_linked_core_financial_records_are_protected_from_out_of_band_edits(): void
    {
        $expenseController = file_get_contents(dirname(__DIR__, 4).'/app/Http/Controllers/ExpenseController.php');
        $customerController = file_get_contents(dirname(__DIR__, 4).'/app/Http/Controllers/CustomerController.php');

        $this->assertStringContainsString('isConstructionLinkedExpense', $expenseController);
        $this->assertStringContainsString('must be managed from Construction ERP', $expenseController);
        $this->assertStringContainsString("ProjectReceipt::where('deposit_id'", $customerController);
        $this->assertStringContainsString('must be managed from Project Receipts', $customerController);
    }

    public function test_procurement_and_logistics_scope_is_wired_to_core_purchase_and_transfer_engines(): void
    {
        $routes = file_get_contents(__DIR__.'/../../Routes/web.php');
        $migration = file_get_contents(__DIR__.'/../../Database/Migrations/2026_10_04_000400_add_construction_procurement_logistics.php');
        $purchase = file_get_contents(dirname(__DIR__, 4).'/app/Models/Purchase.php');
        $transfer = file_get_contents(dirname(__DIR__, 4).'/app/Models/Transfer.php');
        $purchaseForm = file_get_contents(dirname(__DIR__, 4).'/resources/views/backend/purchase/create.blade.php');
        $financials = file_get_contents(__DIR__.'/../../Services/ProjectFinancialService.php');

        $this->assertStringContainsString('procurement-logistics', $routes);
        $this->assertStringContainsString('construction_transport_records', $migration);
        $this->assertStringContainsString('supplier_product_references', $migration);
        $this->assertStringContainsString('project_id', $purchase);
        $this->assertStringContainsString('project_id', $transfer);
        $this->assertStringContainsString('Construction Project', $purchaseForm);
        $this->assertStringContainsString('transport', $financials);
    }

    public function test_workforce_finance_reuses_core_expense_and_employee_advance_accounting(): void
    {
        $routes = file_get_contents(__DIR__.'/../../Routes/web.php');
        $migration = file_get_contents(__DIR__.'/../../Database/Migrations/2026_10_04_000500_add_construction_workforce_finance.php');
        $service = file_get_contents(__DIR__.'/../../Services/ConstructionAccountingService.php');
        $financials = file_get_contents(__DIR__.'/../../Services/ProjectFinancialService.php');

        foreach (['employee-rewards', 'employee-expenses', 'employee-advances'] as $workflow) {
            $this->assertStringContainsString($workflow, $routes);
        }
        $this->assertStringContainsString('construction_employee_advances', $migration);
        $this->assertStringContainsString('recordEmployeeAdvance', $service);
        $this->assertStringContainsString('ROLE_EMPLOYEE_ADVANCE_RECEIVABLE', $service);
        $this->assertStringContainsString('employeeExpenses', $financials);
        $this->assertStringContainsString('rewards', $financials);
    }

    public function test_construction_sidebar_has_no_retail_navigation(): void
    {
        $sidebar = file_get_contents(dirname(__DIR__, 4).'/resources/views/backend/layout/sidebar.blade.php');
        foreach (['POS', 'Gift Card', 'Coupon', 'Sale Agent', 'Biller', 'Restaurant', 'WooCommerce', 'Ecommerce'] as $retailLabel) {
            $this->assertStringNotContainsString($retailLabel, $sidebar);
        }
    }
    public function test_phase5_controls_assets_and_documents_are_wired(): void
    {
        $routes = file_get_contents(__DIR__.'/../../Routes/web.php');
        $migration = file_get_contents(__DIR__.'/../../Database/Migrations/2026_10_04_000600_add_construction_assets_and_controls.php');
        $inventory = file_get_contents(__DIR__.'/../../Services/InventoryMovementService.php');
        $ledger = file_get_contents(dirname(__DIR__, 4).'/app/Services/StockLedgerService.php');
        $financials = file_get_contents(__DIR__.'/../../Services/ProjectFinancialService.php');
        $costs = file_get_contents(__DIR__.'/../../Resources/views/costs.blade.php');
        $contracts = file_get_contents(__DIR__.'/../../Resources/views/subcontractors.blade.php');
        $equipment = file_get_contents(__DIR__.'/../../Resources/views/equipment.blade.php');

        foreach (['issues.approve', 'issues.reject', 'fixed-assets', 'equipment.lifecycle'] as $workflow) {
            $this->assertStringContainsString($workflow, $routes);
        }
        $this->assertStringContainsString('construction_fixed_assets', $migration);
        $this->assertStringContainsString('requestIssue', $inventory);
        $this->assertStringContainsString('approveIssue', $inventory);
        $this->assertStringContainsString("where('h.status','issued')", $ledger);
        $this->assertStringContainsString("where('material_issues.status', 'issued')", $financials);
        $this->assertStringContainsString('name="attachment"', $costs);
        $this->assertStringContainsString('Contract Document', $contracts);
        $this->assertStringContainsString('Fixed Asset Register', $equipment);
        $this->assertStringContainsString('Useful Life (months)', $equipment);
    }

    public function test_phase6_financial_controls_reuse_authoritative_core_engines(): void
    {
        $routes = file_get_contents(__DIR__.'/../../Routes/web.php');
        $migration = file_get_contents(__DIR__.'/../../Database/Migrations/2026_10_04_000700_add_construction_financial_controls.php');
        $finance = file_get_contents(__DIR__.'/../../Http/Controllers/FinanceController.php');
        $income = file_get_contents(dirname(__DIR__, 4).'/app/Models/Income.php');
        $transfer = file_get_contents(dirname(__DIR__, 4).'/app/Models/MoneyTransfer.php');
        $supplierDue = file_get_contents(dirname(__DIR__, 4).'/app/Services/SupplierDuePaymentService.php');

        foreach (['payments-due', 'other-revenue', 'inventory-value'] as $workflow) {
            $this->assertStringContainsString($workflow, $routes);
        }
        $this->assertStringContainsString('money_transfers', $migration);
        $this->assertStringContainsString('InventoryValuationService', $finance);
        $this->assertStringContainsString('SupplierDuePaymentService', $finance);
        $this->assertStringContainsString('project_id', $income);
        $this->assertStringContainsString('external_reference', $transfer);
        $this->assertStringContainsString('public function dueForPurchase', $supplierDue);
    }

    public function test_phase7_shareholders_post_to_core_double_entry_accounting(): void
    {
        $routes = file_get_contents(__DIR__.'/../../Routes/web.php');
        $migration = file_get_contents(__DIR__.'/../../Database/Migrations/2026_10_04_000800_add_construction_shareholders.php');
        $service = file_get_contents(__DIR__.'/../../Services/ConstructionAccountingService.php');
        $view = file_get_contents(__DIR__.'/../../Resources/views/shareholders.blade.php');

        foreach (['shareholders', 'shareholder-transactions'] as $workflow) {
            $this->assertStringContainsString($workflow, $routes);
        }
        $this->assertStringContainsString('construction_shareholders', $migration);
        $this->assertStringContainsString('construction_shareholder_transactions', $migration);
        $this->assertStringContainsString('Shareholder Capital', $migration);
        $this->assertStringContainsString('Shareholder Loans Payable', $migration);
        $this->assertStringContainsString('JournalBuilder::create()', $service);
        $this->assertStringContainsString('capital_contribution', $service);
        $this->assertStringContainsString('loan_repayment', $service);
        $this->assertStringContainsString('SalePro\'s mapped payment accounts', $view);
    }

    public function test_phase8_construction_movement_reports_reuse_authoritative_ledgers(): void
    {
        $routes = file_get_contents(__DIR__.'/../../Routes/web.php');
        $controller = file_get_contents(__DIR__.'/../../Http/Controllers/ReportController.php');
        $stockView = file_get_contents(__DIR__.'/../../Resources/views/reports/stock_movement.blade.php');
        $cashView = file_get_contents(__DIR__.'/../../Resources/views/reports/accounts_cash_movement.blade.php');

        $this->assertStringContainsString('reports/stock-movement', $routes);
        $this->assertStringContainsString('reports/accounts-cash-movement', $routes);
        $this->assertStringContainsString('StockLedgerService', $controller);
        $this->assertStringContainsString('journal_lines as jl', $controller);
        $this->assertStringContainsString('Pending / Not-Received Purchases', $stockView);
        $this->assertStringContainsString('Posted double-entry journal lines from SalePro accounting', $cashView);
    }

    public function test_employee_advance_balance_is_shared_with_payroll_and_reports(): void
    {
        $advanceService = file_get_contents(dirname(__DIR__, 4).'/app/Services/EmployeeAdvanceService.php');
        $constructionAccounting = file_get_contents(__DIR__.'/../../Services/ConstructionAccountingService.php');
        $financials = file_get_contents(__DIR__.'/../../Services/ProjectFinancialService.php');
        $dashboard = file_get_contents(__DIR__.'/../../Http/Controllers/DashboardController.php');

        $this->assertStringContainsString("['advance', 'construction_employee_advance']", $advanceService);
        $this->assertStringContainsString('construction_employee_advance_settlements', $advanceService);
        $this->assertStringContainsString('constructionOutstanding', $advanceService);
        $this->assertStringContainsString('outstandingForEmployee', $constructionAccounting);
        $this->assertStringContainsString('constructionOutstanding', $financials);
        $this->assertStringContainsString('totalOutstanding', $dashboard);
    }

    public function test_construction_stock_and_financial_records_are_warehouse_scoped(): void
    {
        $root = dirname(__DIR__, 4);
        $scopedFiles = [
            $root.'/app/Models/Product_Warehouse.php',
            __DIR__.'/../../Entities/MaterialIssue.php',
            __DIR__.'/../../Entities/MaterialReturn.php',
            __DIR__.'/../../Entities/ProjectCost.php',
            __DIR__.'/../../Entities/ProjectReceipt.php',
            __DIR__.'/../../Entities/SubcontractorPayment.php',
            __DIR__.'/../../Entities/EmployeeAdvance.php',
            __DIR__.'/../../Entities/EmployeeProjectExpense.php',
        ];

        foreach ($scopedFiles as $file) {
            $this->assertStringContainsString('WarehouseScoped', file_get_contents($file), $file);
        }
    }

}
