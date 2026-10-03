<?php

namespace Modules\Construction\Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\GeneralSetting;
use App\Models\HrmSetting;
use App\Models\IncomeCategory;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Construction\Entities\CostCategory;
use Modules\Construction\Entities\Equipment;
use Modules\Construction\Entities\EquipmentAssignment;
use Modules\Construction\Entities\MaterialIssue;
use Modules\Construction\Entities\ProjectCost;
use Modules\Construction\Entities\ProjectReceipt;
use Modules\Construction\Entities\ProjectWage;
use Modules\Construction\Entities\Subcontractor;
use Modules\Construction\Entities\SubcontractorContract;
use Modules\Construction\Services\InventoryMovementService;
use Modules\Project\Entities\Project;

class ConstructionDemoSeeder extends Seeder
{
    private const ADMIN_EMAIL = 'admin@construction.local';
    private const ADMIN_PASSWORD = 'Construction@123';

    public function run(): void
    {
        DB::transaction(function (): void {
            $foundation = $this->seedFoundation();
            $materials = $this->seedMaterials($foundation['unit'], $foundation['category'], $foundation['warehouse']);
            $projects = $this->seedProjects($foundation['client'], $foundation['admin'], $foundation['employees']);

            $this->seedProjectTransactions(
                $projects,
                $foundation['client'],
                $foundation['account'],
                $foundation['admin'],
                $foundation['employees']
            );
            $this->seedInventoryMovements($projects[0], $foundation['warehouse'], $materials, $foundation['admin']);
        });
    }

    /** @return array<string, mixed> */
    private function seedFoundation(): array
    {
        $currency = Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 1, 'is_active' => true]
        );
        GeneralSetting::firstOrCreate(
            ['site_title' => 'Construction ERP Demo'],
            [
                'currency' => $currency->id,
                'staff_access' => 'all',
                'without_stock' => 'no',
                'date_format' => 'd-m-Y',
                'theme' => 'default.css',
                'currency_position' => 'prefix',
                'company_name' => 'Construction ERP Demo',
                'timezone' => 'Asia/Dhaka',
            ]
        );
        HrmSetting::firstOrCreate([], ['checkin' => '09:00:00', 'checkout' => '18:00:00']);

        $unit = Unit::firstOrCreate(
            ['unit_code' => 'PCS'],
            ['unit_name' => 'Piece', 'operator' => '*', 'operation_value' => 1, 'is_active' => true]
        );
        $category = Category::firstOrCreate(['name' => 'Construction Materials'], ['is_active' => true]);
        $warehouse = Warehouse::firstOrCreate(
            ['name' => 'Central Construction Store'],
            ['address' => 'Main Yard', 'is_active' => true, 'store_type' => 'Central Store']
        );
        $account = Account::firstOrCreate(
            ['account_no' => 'CON-DEMO-CASH'],
            [
                'name' => 'Construction Demo Bank',
                'initial_balance' => 0,
                'total_balance' => 0,
                'note' => 'Demo receipts account',
                'is_default' => true,
                'is_active' => true,
                'type' => 'Bank Account',
                'is_payment' => true,
            ]
        );
        ExpenseCategory::firstOrCreate(
            ['code' => 'CON-DEMO-EXP'],
            ['name' => 'Construction Project Expense', 'is_active' => true]
        );
        IncomeCategory::firstOrCreate(
            ['code' => 'CON-DEMO-INC'],
            ['name' => 'Construction Project Receipt', 'is_active' => true]
        );

        $admin = $this->seedAdministrator($warehouse, $account);
        $employees = $this->seedEmployees($warehouse);
        $group = CustomerGroup::firstOrCreate(
            ['name' => 'Construction Clients'],
            ['percentage' => 0, 'is_active' => true]
        );
        $client = Customer::firstOrCreate(
            ['email' => 'demo.client@construction.local'],
            [
                'customer_group_id' => $group->id,
                'name' => 'Al Noor Developments',
                'company_name' => 'Al Noor Developments',
                'phone_number' => '0000000000',
                'address' => 'Demo Business District',
                'city' => 'Demo City',
                'is_active' => true,
            ]
        );
        Supplier::firstOrCreate(
            ['email' => 'materials@demo-supplier.local'],
            [
                'name' => 'Demo Building Materials Supply',
                'company_name' => 'Demo Building Materials Supply',
                'phone_number' => '0000000001',
                'address' => 'Industrial Area',
                'city' => 'Demo City',
                'is_active' => true,
            ]
        );

        return compact('currency', 'unit', 'category', 'warehouse', 'account', 'admin', 'employees', 'client');
    }

    private function seedAdministrator(Warehouse $warehouse, Account $account): User
    {
        // Stock Ledger is a retained core Construction surface. Its legacy
        // permission normally comes from the retail demo seeder, so bootstrap
        // it here for a fresh standalone database.
        DB::table('permissions')->updateOrInsert(
            ['name' => 'stock-report', 'guard_name' => 'web'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $administratorRoleId = DB::table('roles')->where('id', 1)->value('id');
        if (!$administratorRoleId) {
            $administratorRoleId = DB::table('roles')->insertGetId([
                'name' => 'Administrator',
                'description' => 'Construction ERP administrator',
                'is_active' => true,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('roles')->where('id', $administratorRoleId)->update([
                'name' => 'Administrator',
                'description' => 'Construction ERP administrator',
                'is_active' => true,
                'guard_name' => 'web',
                'updated_at' => now(),
            ]);
        }

        $admin = User::firstOrNew(['email' => self::ADMIN_EMAIL]);
        $admin->fill([
            'name' => 'Construction Demo Administrator',
            'phone' => '0000000002',
            'company_name' => 'Construction ERP Demo',
            'role_id' => $administratorRoleId,
            'warehouse_id' => $warehouse->id,
            'account_id' => $account->id,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        if (!$admin->exists || !Hash::check(self::ADMIN_PASSWORD, (string) $admin->password)) {
            $admin->password = Hash::make(self::ADMIN_PASSWORD);
        }
        $admin->save();

        foreach (DB::table('permissions')->pluck('id') as $permissionId) {
            DB::table('role_has_permissions')->updateOrInsert([
                'role_id' => $administratorRoleId,
                'permission_id' => $permissionId,
            ]);
        }
        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => $administratorRoleId,
            'model_type' => User::class,
            'model_id' => $admin->id,
        ]);
        $this->seedManagementRole();
        app('cache')->forget('spatie.permission.cache');
        app('cache')->forget('role_has_permissions_list'.$administratorRoleId);

        return $admin;
    }

    private function seedManagementRole(): void
    {
        $roleId = DB::table('roles')->where('name', 'Management / Director')->value('id');
        if (!$roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'Management / Director',
                'description' => 'Construction ERP role',
                'is_active' => true,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('name', [
            'module.construction.access',
            'construction.dashboard.view',
            'construction.reports.view',
            'project_project_list',
        ])->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    /** @return array<int, Employee> */
    private function seedEmployees(Warehouse $warehouse): array
    {
        $department = Department::firstOrCreate(
            ['name' => 'Construction Operations', 'warehouse_id' => $warehouse->id],
            ['is_active' => true]
        );
        $designationId = DB::table('designations')->where('name', 'Site Workforce')->value('id');
        if (!$designationId) {
            $designationId = DB::table('designations')->insertGetId([
                'name' => 'Site Workforce',
                'warehouse_id' => $warehouse->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $shiftId = DB::table('shifts')->where('name', 'Construction Day Shift')->value('id');
        if (!$shiftId) {
            $shiftId = DB::table('shifts')->insertGetId([
                'name' => 'Construction Day Shift',
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'total_hours' => 8,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $employees = [];
        foreach (['Demo Site Engineer', 'Demo Foreman', 'Demo Skilled Worker'] as $index => $name) {
            $employees[] = Employee::firstOrCreate(
                ['staff_id' => 'CON-DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                [
                    'name' => $name,
                    'email' => 'workforce'.($index + 1).'@construction.local',
                    'phone_number' => '000000001'.($index + 1),
                    'department_id' => $department->id,
                    'designation_id' => $designationId,
                    'shift_id' => $shiftId,
                    'warehouse_id' => $warehouse->id,
                    'basic_salary' => 2500 + ($index * 500),
                    'is_active' => true,
                    'is_sale_agent' => false,
                ]
            );
        }

        return $employees;
    }

    /** @return array<string, Product_Warehouse> */
    private function seedMaterials(Unit $unit, Category $category, Warehouse $warehouse): array
    {
        $definitions = [
            'Portland Cement' => 9.50,
            'Reinforcement Steel 12mm' => 620.00,
            'Reinforcement Steel 16mm' => 645.00,
            'Sand' => 28.00,
            'Aggregate' => 34.00,
            'Concrete Block' => 0.85,
            'Electrical Cable' => 1.75,
            'PVC Pipe' => 4.20,
        ];
        $materials = [];
        foreach ($definitions as $name => $cost) {
            $code = 'MAT-'.str_pad((string) (count($materials) + 1), 3, '0', STR_PAD_LEFT);
            $product = Product::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => 'standard',
                    'barcode_symbology' => 'C128',
                    'category_id' => $category->id,
                    'unit_id' => $unit->id,
                    'purchase_unit_id' => $unit->id,
                    'sale_unit_id' => $unit->id,
                    'cost' => $cost,
                    'price' => $cost,
                    'qty' => 1000,
                    'alert_quantity' => 100,
                    'promotion' => false,
                    'featured' => false,
                    'is_active' => true,
                ]
            );
            $materials[$code] = Product_Warehouse::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'variant_id' => null,
                    'product_batch_id' => null,
                ],
                ['qty' => 1000, 'price' => $cost]
            );
        }

        return $materials;
    }

    /** @return array<int, Project> */
    private function seedProjects(Customer $client, User $admin, array $employees): array
    {
        $definitions = [
            ['PRJ-001', 'Al Noor Residential Tower', 2500000, 2100000],
            ['PRJ-002', 'Marina Villa Compound', 1450000, 1200000],
            ['PRJ-003', 'City Centre Renovation', 680000, 540000],
        ];
        $projects = [];
        foreach ($definitions as $index => [$code, $title, $contract, $budget]) {
            $projects[] = Project::firstOrCreate(
                ['project_code' => $code],
                [
                    'title' => $title,
                    'customer_id' => $client->id,
                    'start_date' => now()->subMonths(3)->format('Y-m-d'),
                    'end_date' => now()->addMonths(9)->format('Y-m-d'),
                    'expected_end_date' => now()->addMonths(9)->format('Y-m-d'),
                    'project_priority' => 'high',
                    'description' => 'Construction ERP demonstration project',
                    'project_status' => 'in_progress',
                    'project_progress' => '35',
                    'location' => 'Demo City',
                    'contract_value' => $contract,
                    'budget' => $budget,
                    'project_manager_id' => $employees[$index]->id,
                    'added_by' => $admin->id,
                ]
            );
        }

        return $projects;
    }

    private function seedProjectTransactions(array $projects, Customer $client, Account $account, User $admin, array $employees): void
    {
        $categories = CostCategory::pluck('id', 'name');
        foreach ($projects as $index => $project) {
            ProjectCost::firstOrCreate(
                ['project_id' => $project->id, 'reference' => 'DEMO-COST-'.($index + 1)],
                [
                    'cost_category_id' => $categories['Other Project Cost'],
                    'date' => now()->subDays(12 - $index),
                    'amount' => 18000 + ($index * 4500),
                    'description' => 'Site mobilization and preliminary works',
                    'created_by' => $admin->id,
                ]
            );
            ProjectWage::firstOrCreate(
                ['project_id' => $project->id, 'employee_id' => $employees[$index]->id, 'period' => now()->startOfMonth()->format('Y-m-d')],
                [
                    'days_worked' => 22,
                    'basic_amount' => 2500 + ($index * 500),
                    'overtime_amount' => 250,
                    'bonus_amount' => 0,
                    'deduction_amount' => 0,
                    'total_amount' => 2750 + ($index * 500),
                    'cost_category_id' => $categories['Labour'],
                    'created_by' => $admin->id,
                ]
            );
            ProjectReceipt::firstOrCreate(
                ['reference_no' => 'PR-DEMO-'.($index + 1)],
                [
                    'project_id' => $project->id,
                    'customer_id' => $client->id,
                    'receipt_date' => now()->subDays(30 - $index),
                    'amount' => 250000 + ($index * 50000),
                    'account_id' => $account->id,
                    'payment_method' => 'Bank Transfer',
                    'external_reference' => 'DEMO-TRANSFER-'.($index + 1),
                    'posting_status' => 'operational',
                    'created_by' => $admin->id,
                ]
            );
        }

        $subcontractors = [];
        foreach (['Gulf Electrical Works', 'Prime Plumbing Services', 'Modern Aluminium Works'] as $name) {
            $subcontractors[] = Subcontractor::firstOrCreate(['name' => $name], ['company_name' => $name, 'status' => 'active']);
        }
        foreach ($projects as $index => $project) {
            SubcontractorContract::firstOrCreate(
                ['contract_no' => 'SC-DEMO-'.($index + 1)],
                [
                    'project_id' => $project->id,
                    'subcontractor_id' => $subcontractors[$index]->id,
                    'scope' => 'Specialist construction package',
                    'contract_value' => 120000 + ($index * 30000),
                    'paid_amount' => 30000 + ($index * 5000),
                    'status' => 'active',
                ]
            );
        }

        foreach ([['EX-01', 'Excavator'], ['CM-02', 'Concrete Mixer'], ['GN-03', 'Generator']] as [$code, $name]) {
            Equipment::firstOrCreate(['code' => $code], ['name' => $name, 'category' => 'Plant & Equipment', 'status' => 'available']);
        }
        $equipmentByCode = Equipment::whereIn('code', ['EX-01', 'CM-02', 'GN-03'])->get()->keyBy('code');
        foreach (['EX-01', 'CM-02', 'GN-03'] as $index => $code) {
            $equipment = $equipmentByCode[$code];
            EquipmentAssignment::firstOrCreate(
                ['equipment_id' => $equipment->id, 'project_id' => $projects[$index]->id],
                [
                    'assigned_from' => now()->subDays(20)->format('Y-m-d'),
                    'rate_type' => 'day',
                    'rate' => 250,
                    'usage_quantity' => 20,
                    'cost' => 5000,
                    'status' => 'assigned',
                ]
            );
        }
    }

    private function seedInventoryMovements(Project $project, Warehouse $warehouse, array $materials, User $admin): void
    {
        $service = app(InventoryMovementService::class);
        $materialCategoryId = CostCategory::where('name', 'Materials')->value('id');
        $issue = MaterialIssue::where('reference_no', 'MI-DEMO-001')->first();
        if (!$issue) {
            $issue = $service->issue(
                [
                    'reference_no' => 'MI-DEMO-001',
                    'project_id' => $project->id,
                    'warehouse_id' => $warehouse->id,
                    'issue_date' => now()->subDays(7)->format('Y-m-d'),
                    'status' => 'issued',
                    'notes' => 'Demo foundation material issue',
                    'created_by' => $admin->id,
                ],
                [
                    ['stock_row_id' => $materials['MAT-001']->id, 'quantity' => 120, 'cost_category_id' => $materialCategoryId],
                    ['stock_row_id' => $materials['MAT-002']->id, 'quantity' => 8, 'cost_category_id' => $materialCategoryId],
                ]
            );
        }

        if (!DB::table('material_returns')->where('reference_no', 'MR-DEMO-001')->exists()) {
            $cementItem = $issue->items()->where('product_id', $materials['MAT-001']->product_id)->firstOrFail();
            $service->return(
                $issue,
                [$cementItem->id => 20],
                [
                    'reference_no' => 'MR-DEMO-001',
                    'return_date' => now()->subDays(5)->format('Y-m-d'),
                    'status' => 'returned',
                    'notes' => 'Unused demo cement returned to store',
                    'created_by' => $admin->id,
                ]
            );
        }
    }
}
