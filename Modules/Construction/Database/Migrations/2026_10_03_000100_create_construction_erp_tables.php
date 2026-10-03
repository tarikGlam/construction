<?php

use App\Services\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cost_categories', function (Blueprint $table) {
            $table->id(); $table->string('code', 30)->nullable()->unique(); $table->string('name');
            $table->text('description')->nullable(); $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('construction_sites', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('project_id')->index(); $table->string('name'); $table->string('code', 50)->nullable();
            $table->string('location')->nullable(); $table->unsignedInteger('manager_id')->nullable()->index();
            $table->string('status', 30)->default('active'); $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('project_costs', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('project_id')->index(); $table->unsignedBigInteger('cost_category_id')->index();
            $table->unsignedInteger('expense_id')->nullable()->index(); $table->unsignedInteger('supplier_id')->nullable()->index();
            $table->date('date')->index(); $table->decimal('amount', 20, 4); $table->text('description');
            $table->string('reference')->nullable(); $table->string('attachment')->nullable(); $table->unsignedInteger('created_by')->nullable()->index(); $table->timestamps();
        });
        Schema::create('material_issues', function (Blueprint $table) {
            $table->id(); $table->string('reference_no')->unique(); $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index(); $table->unsignedInteger('warehouse_id')->index();
            $table->date('issue_date')->index(); $table->unsignedInteger('requested_by')->nullable(); $table->unsignedInteger('approved_by')->nullable();
            $table->string('status', 30)->default('issued'); $table->text('notes')->nullable(); $table->unsignedInteger('created_by')->nullable(); $table->timestamps();
        });
        Schema::create('material_issue_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('material_issue_id')->index(); $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('variant_id')->nullable()->index(); $table->unsignedInteger('product_batch_id')->nullable()->index();
            $table->decimal('quantity', 20, 4); $table->decimal('unit_cost', 20, 4); $table->decimal('total_cost', 20, 4);
            $table->unsignedBigInteger('cost_category_id')->nullable()->index(); $table->timestamps();
        });
        Schema::create('material_returns', function (Blueprint $table) {
            $table->id(); $table->string('reference_no')->unique(); $table->unsignedBigInteger('material_issue_id')->index();
            $table->unsignedBigInteger('project_id')->index(); $table->unsignedBigInteger('site_id')->nullable()->index(); $table->unsignedInteger('warehouse_id')->index();
            $table->date('return_date')->index(); $table->string('status', 30)->default('returned'); $table->text('notes')->nullable();
            $table->unsignedInteger('created_by')->nullable(); $table->timestamps();
        });
        Schema::create('material_return_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('material_return_id')->index(); $table->unsignedBigInteger('material_issue_item_id')->index();
            $table->unsignedInteger('product_id')->index(); $table->unsignedInteger('variant_id')->nullable()->index();
            $table->unsignedInteger('product_batch_id')->nullable()->index(); $table->decimal('quantity', 20, 4);
            $table->decimal('unit_cost', 20, 4); $table->decimal('total_cost', 20, 4); $table->timestamps();
        });
        Schema::create('subcontractors', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('company_name')->nullable(); $table->string('phone')->nullable();
            $table->string('email')->nullable(); $table->string('tax_no')->nullable(); $table->text('address')->nullable();
            $table->string('status', 30)->default('active'); $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('subcontractor_contracts', function (Blueprint $table) {
            $table->id(); $table->string('contract_no')->unique(); $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('subcontractor_id')->index(); $table->text('scope'); $table->date('start_date')->nullable(); $table->date('end_date')->nullable();
            $table->decimal('contract_value', 20, 4); $table->decimal('paid_amount', 20, 4)->default(0); $table->string('status', 30)->default('active');
            $table->text('notes')->nullable(); $table->string('attachment')->nullable(); $table->timestamps();
        });
        Schema::create('project_wages', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('project_id')->index(); $table->unsignedInteger('employee_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index(); $table->date('period')->index(); $table->decimal('days_worked', 10, 2)->nullable();
            $table->decimal('basic_amount', 20, 4); $table->decimal('overtime_amount', 20, 4)->default(0); $table->decimal('bonus_amount', 20, 4)->default(0);
            $table->decimal('deduction_amount', 20, 4)->default(0); $table->decimal('total_amount', 20, 4); $table->unsignedBigInteger('cost_category_id')->nullable()->index();
            $table->text('notes')->nullable(); $table->unsignedInteger('created_by')->nullable(); $table->timestamps();
        });
        Schema::create('equipment', function (Blueprint $table) {
            $table->id(); $table->string('code', 50)->unique(); $table->string('name'); $table->string('category')->nullable();
            $table->date('acquisition_date')->nullable(); $table->decimal('acquisition_value', 20, 4)->nullable(); $table->string('status', 30)->default('available');
            $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('equipment_assignments', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('equipment_id')->index(); $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index(); $table->date('assigned_from'); $table->date('assigned_to')->nullable();
            $table->string('rate_type', 30)->nullable(); $table->decimal('rate', 20, 4)->nullable(); $table->decimal('usage_quantity', 20, 4)->nullable();
            $table->decimal('cost', 20, 4)->default(0); $table->string('status', 30)->default('assigned'); $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('project_receipts', function (Blueprint $table) {
            $table->id(); $table->string('reference_no')->unique(); $table->unsignedBigInteger('project_id')->index(); $table->unsignedInteger('customer_id')->index();
            $table->date('receipt_date')->index(); $table->decimal('amount', 20, 4); $table->unsignedInteger('account_id')->nullable()->index();
            $table->string('payment_method', 50); $table->string('external_reference')->nullable(); $table->text('notes')->nullable();
            $table->string('posting_status', 30)->default('operational'); $table->unsignedInteger('created_by')->nullable(); $table->timestamps();
        });

        $this->addDimensions();
        $this->seedPermissions();
        $this->seedConstructionRoles();
        $this->seedCategories();
    }

    private function addDimensions(): void
    {
        $definitions = [
            'purchases' => ['project_id', 'site_id'], 'product_purchases' => ['project_id', 'site_id', 'cost_category_id'],
            'expenses' => ['project_id', 'site_id', 'cost_category_id'], 'incomes' => ['project_id', 'site_id', 'cost_category_id'],
            'payments' => ['project_id', 'site_id'], 'warehouses' => ['project_id', 'site_id'],
        ];
        foreach ($definitions as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) continue;
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                foreach ($columns as $column) if (!Schema::hasColumn($tableName, $column)) $table->unsignedBigInteger($column)->nullable()->index();
                if ($tableName === 'warehouses' && !Schema::hasColumn('warehouses', 'store_type')) $table->string('store_type', 30)->nullable()->index();
            });
        }
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions')) return;
        foreach (ModuleRegistry::permissionNames('construction') as $name) DB::table('permissions')->updateOrInsert(
            ['name' => $name, 'guard_name' => 'web'], ['created_at' => now(), 'updated_at' => now()]
        );
        if (!Schema::hasTable('role_has_permissions')) return;
        $ids = DB::table('permissions')->whereIn('name', ModuleRegistry::permissionNames('construction'))->pluck('id');
        foreach (DB::table('roles')->whereIn('id', [1, 2])->pluck('id') as $roleId) foreach ($ids as $permissionId) {
            DB::table('role_has_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], []);
        }
        app('cache')->forget('spatie.permission.cache');
    }

    private function seedCategories(): void
    {
        foreach (['Materials','Labour','Subcontract','Equipment','Transport','Fuel','Accommodation','Electrical','Plumbing','Other Project Cost'] as $name) {
            DB::table('cost_categories')->updateOrInsert(['name' => $name], ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function seedConstructionRoles(): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('role_has_permissions')) return;
        $roles = [
            'Management / Director' => ['construction.dashboard.view','construction.reports.view','project_project_list'],
            'Project Manager' => ['construction.dashboard.view','construction.project-costs.manage','construction.material-issues.manage','construction.material-returns.manage','construction.subcontractors.manage','construction.project-wages.manage','construction.equipment.manage','construction.project-receipts.manage','construction.reports.view','project_project_list','project_project_add','project_project_edit'],
            'Site Engineer' => ['construction.dashboard.view','construction.material-issues.manage','construction.material-returns.manage','construction.project-wages.manage','construction.equipment.manage','project_project_list'],
            'Storekeeper' => ['construction.dashboard.view','construction.material-issues.manage','construction.material-returns.manage','products-index','transfers-index','adjustment','stock_count'],
            'Procurement Officer' => ['construction.dashboard.view','purchases-index','purchases-add','purchases-edit','suppliers-index'],
            'Accountant' => ['construction.dashboard.view','construction.project-costs.manage','construction.project-receipts.manage','construction.reports.view','accounts-index','expenses-index','incomes-index'],
            'HR Officer' => ['construction.dashboard.view','construction.project-wages.manage','employees-index','attendance','payroll'],
            'Equipment Manager' => ['construction.dashboard.view','construction.equipment.manage','project_project_list'],
        ];
        $modulePermission = DB::table('permissions')->where('name','module.construction.access')->value('id');
        foreach ($roles as $name => $permissions) {
            $roleId = DB::table('roles')->where('name',$name)->value('id');
            if (!$roleId) $roleId = DB::table('roles')->insertGetId(['name'=>$name,'description'=>'Construction ERP role','is_active'=>true,'guard_name'=>'web','created_at'=>now(),'updated_at'=>now()]);
            $permissionIds = DB::table('permissions')->whereIn('name',array_merge([$modulePermission ? 'module.construction.access' : ''],$permissions))->pluck('id');
            foreach ($permissionIds as $permissionId) DB::table('role_has_permissions')->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$permissionId],[]);
        }
    }

    public function down(): void
    {
        foreach (['project_receipts','equipment_assignments','equipment','project_wages','subcontractor_contracts','subcontractors','material_return_items','material_returns','material_issue_items','material_issues','project_costs','construction_sites','cost_categories'] as $table) Schema::dropIfExists($table);
    }
};
