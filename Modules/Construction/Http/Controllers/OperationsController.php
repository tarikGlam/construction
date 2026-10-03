<?php

namespace Modules\Construction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\PaymentAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Construction\Entities\CostCategory;
use Modules\Construction\Entities\Equipment;
use Modules\Construction\Entities\EquipmentAssignment;
use Modules\Construction\Entities\FixedAsset;
use Modules\Construction\Entities\MaterialIssue;
use Modules\Construction\Entities\ProjectCost;
use Modules\Construction\Entities\ProjectReceipt;
use Modules\Construction\Entities\ProjectWage;
use Modules\Construction\Entities\Subcontractor;
use Modules\Construction\Entities\SubcontractorContract;
use Modules\Construction\Entities\SubcontractorPayment;
use Modules\Construction\Services\ConstructionAccountingService;
use Modules\Construction\Services\InventoryMovementService;
use Modules\Project\Entities\Project;

class OperationsController extends Controller
{
    public function settings()
    {
        return view('construction::settings');
    }

    public function clients()
    {
        return view('construction::clients', [
            'clients' => Customer::where('is_active', true)->latest()->paginate(20),
            'groups' => CustomerGroup::where('is_active', true)->get(),
        ]);
    }

    public function storeClient(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'company_name' => 'nullable|string|max:191',
            'phone_number' => 'required|string|max:50',
            'wa_number' => 'nullable|string|max:50',
            'email' => 'nullable|email',
            'tax_no' => 'nullable|string|max:100',
            'address' => 'required|string',
            'city' => 'required|string',
            'opening_balance' => 'nullable|numeric',
            'pay_term_no' => 'nullable|integer|min:0',
            'pay_term_period' => 'nullable|string|max:30',
        ]);
        $group = CustomerGroup::where('is_active', true)->firstOrFail();
        Customer::create($data + ['customer_group_id' => $group->id, 'is_active' => true]);

        return back()->with('message', 'Client added.');
    }

    public function materialCatalog()
    {
        return view('construction::catalog', [
            'materials' => Product::with(['category', 'unit'])->where('is_active', true)->latest()->paginate(20),
            'categories' => Category::where('is_active', true)->get(),
            'units' => Unit::where('is_active', true)->get(),
            'warehouses' => Warehouse::where('is_active', true)->get(),
        ]);
    }

    public function storeMaterial(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'code' => 'required|string|max:191|unique:products,code',
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'cost' => 'required|numeric|min:0',
            'quantity' => 'nullable|numeric|min:0',
            'alert_quantity' => 'nullable|numeric|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'product_details' => 'nullable|string',
        ]);

        DB::transaction(function () use ($data) {
            $qty = (float) ($data['quantity'] ?? 0);
            $product = Product::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'type' => 'standard',
                'barcode_symbology' => 'C128',
                'category_id' => $data['category_id'],
                'unit_id' => $data['unit_id'],
                'purchase_unit_id' => $data['unit_id'],
                'sale_unit_id' => $data['unit_id'],
                'cost' => $data['cost'],
                'price' => $data['cost'],
                'qty' => $qty,
                'alert_quantity' => $data['alert_quantity'] ?? 0,
                'promotion' => 0,
                'featured' => 0,
                'product_details' => $data['product_details'] ?? null,
                'is_active' => true,
            ]);
            if (!empty($data['warehouse_id'])) {
                Product_Warehouse::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $data['warehouse_id'],
                    'qty' => $qty,
                    'price' => $data['cost'],
                ]);
            }
        });

        return back()->with('message', 'Material added.');
    }

    private function common(): array
    {
        return [
            'projects' => Project::orderBy('title')->get(),
            'categories' => CostCategory::where('active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(),
        ];
    }

    public function costs(PaymentAccountService $paymentAccounts)
    {
        return view('construction::costs', $this->common() + [
            'costs' => ProjectCost::with(['project', 'category', 'expense', 'warehouse', 'account'])->latest()->paginate(20),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'accounts' => $paymentAccounts->validOperationalAccounts(),
        ]);
    }

    public function storeCost(Request $request, ConstructionAccountingService $constructionAccounting)
    {
        $data = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'cost_category_id' => 'required|exists:cost_categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'account_id' => 'required|exists:accounts,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.0001',
            'description' => 'required|string',
            'reference' => 'nullable|string|max:191',
            'attachment' => 'nullable|file|max:10240|mimes:jpeg,png,jpg,gif,ppt,pptx,doc,docx,pdf',
        ]);
        if ($request->hasFile('attachment')) {
            $data['attachment'] = $request->file('attachment')->store('construction/project-costs', 'public');
        }

        try {
            $cost = $constructionAccounting->createPaidProjectCost($data, (int) Auth::id());
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->withErrors(['accounting' => $e->getMessage()]);
        }

        return back()->with('message', 'Project cost recorded and linked to Expense. Accounting status: ' . $cost->posting_status . '.');
    }

    public function postCost(Request $request, ProjectCost $cost, ConstructionAccountingService $constructionAccounting)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'account_id' => 'required|exists:accounts,id',
        ]);

        try {
            $cost = $constructionAccounting->postProjectCost($cost, (int) $data['account_id'], (int) $data['warehouse_id']);
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['accounting' => $e->getMessage()]);
        }

        return back()->with('message', 'Existing project cost linked to Expense. Accounting status: ' . $cost->posting_status . '.');
    }

    public function materials()
    {
        $stock = Product_Warehouse::query()->with(['product', 'warehouse'])->where('qty', '>', 0)->orderBy('warehouse_id')->get();
        return view('construction::materials', $this->common() + [
            'stock' => $stock,
            'issues' => MaterialIssue::with(['project', 'warehouse', 'items.product'])->latest()->paginate(15),
        ]);
    }

    public function storeIssue(Request $request, InventoryMovementService $inventory)
    {
        $data = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'issue_date' => 'required|date',
            'notes' => 'nullable|string',
            'stock_row_id' => 'required|array|min:1',
            'stock_row_id.*' => 'required|integer|exists:product_warehouse,id',
            'quantity' => 'required|array',
            'quantity.*' => 'required|numeric|min:0.0001',
        ]);
        $lines = [];
        foreach ($data['stock_row_id'] as $i => $rowId) {
            $lines[] = [
                'stock_row_id' => $rowId,
                'quantity' => $data['quantity'][$i] ?? 0,
                'cost_category_id' => $request->input("cost_category_id.$i"),
            ];
        }
        $inventory->requestIssue([
            'reference_no' => 'MI-' . now()->format('YmdHis') . '-' . random_int(10, 99),
            'project_id' => $data['project_id'],
            'warehouse_id' => $data['warehouse_id'],
            'issue_date' => $data['issue_date'],
            'requested_by' => Auth::id(),
            'notes' => $data['notes'] ?? null,
            'created_by' => Auth::id(),
        ], $lines);

        return back()->with('message', 'Material issue request saved as Pending. Stock will move only after approval.');
    }

    public function approveIssue(MaterialIssue $issue, InventoryMovementService $inventory)
    {
        try {
            $inventory->approveIssue($issue, (int) Auth::id());
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['approval' => $e->getMessage()]);
        }
        return back()->with('message', 'Material issue approved and posted to authoritative SalePro stock.');
    }

    public function rejectIssue(Request $request, MaterialIssue $issue, InventoryMovementService $inventory)
    {
        $data = $request->validate(['rejection_reason' => 'nullable|string|max:1000']);
        try {
            $inventory->rejectIssue($issue, (int) Auth::id(), $data['rejection_reason'] ?? null);
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['approval' => $e->getMessage()]);
        }
        return back()->with('message', 'Material issue request rejected. No stock was moved.');
    }

    public function storeReturn(Request $request, MaterialIssue $issue, InventoryMovementService $inventory)
    {
        $data = $request->validate([
            'return_date' => 'required|date',
            'notes' => 'nullable|string',
            'quantity' => 'required|array',
        ]);
        $inventory->return($issue, $data['quantity'], [
            'reference_no' => 'MR-' . now()->format('YmdHis') . '-' . random_int(10, 99),
            'return_date' => $data['return_date'],
            'status' => 'returned',
            'notes' => $data['notes'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return back()->with('message', 'Material return restored to Store stock and Stock Ledger.');
    }

    public function subcontractors()
    {
        return view('construction::subcontractors', $this->common() + [
            'subcontractors' => Subcontractor::with('contracts.project')->latest()->get(),
            'contracts' => SubcontractorContract::with(['subcontractor', 'project'])->latest()->get(),
            'payments' => SubcontractorPayment::with(['contract', 'subcontractor', 'project', 'account'])->latest()->take(30)->get(),
            'accounts' => app(PaymentAccountService::class)->validOperationalAccounts(),
        ]);
    }

    public function storeSubcontractor(Request $request)
    {
        Subcontractor::create($request->validate([
            'name' => 'required|string|max:191',
            'company_name' => 'nullable|string|max:191',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email',
            'tax_no' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
        ]));

        return back()->with('message', 'Subcontractor added.');
    }

    public function storeContract(Request $request)
    {
        $data = $request->validate([
            'contract_no' => 'required|string|max:100|unique:subcontractor_contracts',
            'project_id' => 'required|exists:projects,id',
            'subcontractor_id' => 'required|exists:subcontractors,id',
            'scope' => 'required|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'contract_value' => 'required|numeric|min:0',
            'status' => 'required|in:draft,active,completed,cancelled',
            'notes' => 'nullable|string',
            'attachment' => 'nullable|file|max:10240|mimes:jpeg,png,jpg,gif,ppt,pptx,doc,docx,pdf',
        ]);
        if ($request->hasFile('attachment')) {
            $data['attachment'] = $request->file('attachment')->store('construction/subcontract-contracts', 'public');
        }
        $data['paid_amount'] = 0;
        SubcontractorContract::create($data);

        return back()->with('message', 'Subcontract contract added.');
    }

    public function storeSubcontractorPayment(Request $request, ConstructionAccountingService $constructionAccounting)
    {
        $data = $request->validate([
            'subcontractor_contract_id' => 'required|exists:subcontractor_contracts,id',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0.0001',
            'warehouse_id' => 'required|exists:warehouses,id',
            'account_id' => 'required|exists:accounts,id',
            'reference' => 'nullable|string|max:191',
            'notes' => 'nullable|string',
        ]);

        try {
            $payment = $constructionAccounting->createSubcontractorPayment($data, (int) Auth::id());
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->withErrors(['accounting' => $e->getMessage()]);
        }

        return back()->with('message', 'Subcontractor payment recorded. Accounting status: ' . $payment->posting_status . '.');
    }

    public function workforce()
    {
        return view('construction::workforce', $this->common() + [
            'wages' => ProjectWage::with(['project', 'employee'])->latest()->paginate(20),
        ]);
    }

    public function storeWage(Request $request)
    {
        $data = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'employee_id' => 'required|exists:employees,id',
            'period' => 'required|date',
            'days_worked' => 'nullable|numeric|min:0',
            'basic_amount' => 'required|numeric|min:0',
            'overtime_amount' => 'nullable|numeric|min:0',
            'bonus_amount' => 'nullable|numeric|min:0',
            'deduction_amount' => 'nullable|numeric|min:0',
            'cost_category_id' => 'nullable|exists:cost_categories,id',
            'notes' => 'nullable|string',
        ]);
        $data['total_amount'] = (float) $data['basic_amount'] + (float) ($data['overtime_amount'] ?? 0) + (float) ($data['bonus_amount'] ?? 0) - (float) ($data['deduction_amount'] ?? 0);
        $data['created_by'] = Auth::id();
        ProjectWage::create($data);

        return back()->with('message', 'Project wage recorded.');
    }

    public function equipment()
    {
        return view('construction::equipment', $this->common() + [
            'equipmentList' => Equipment::with(['assignments.project','fixedAsset'])->orderBy('name')->get(),
            'assignments' => EquipmentAssignment::with(['equipment', 'project'])->latest()->get(),
            'fixedAssets' => FixedAsset::with('equipment')->orderBy('name')->get(),
        ]);
    }

    public function storeEquipment(Request $request)
    {
        Equipment::create($request->validate([
            'code' => 'required|string|max:50|unique:equipment,code',
            'name' => 'required|string|max:191',
            'type' => 'nullable|string|max:100',
            'serial_no' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'acquisition_date' => 'nullable|date',
            'acquisition_value' => 'nullable|numeric|min:0',
            'depreciation_method' => 'nullable|in:straight_line',
            'useful_life_months' => 'nullable|integer|min:1',
            'salvage_value' => 'nullable|numeric|min:0',
            'fixed_asset_id' => 'nullable|exists:construction_fixed_assets,id',
            'status' => 'required|in:available,assigned,maintenance,retired',
            'notes' => 'nullable|string',
        ]));
        return back()->with('message', 'Equipment added.');
    }

    public function updateEquipment(Request $request, Equipment $equipment)
    {
        $data = $request->validate([
            'status'=>'required|in:available,assigned,maintenance,retired',
            'depreciation_method'=>'nullable|in:straight_line',
            'useful_life_months'=>'nullable|integer|min:1',
            'salvage_value'=>'nullable|numeric|min:0',
            'fixed_asset_id'=>'nullable|exists:construction_fixed_assets,id',
            'notes'=>'nullable|string',
        ]);
        $equipment->update($data);
        return back()->with('message','Equipment lifecycle information updated.');
    }

    public function storeAssignment(Request $request)
    {
        $data = $request->validate([
            'equipment_id' => 'required|exists:equipment,id',
            'project_id' => 'required|exists:projects,id',
            'assigned_from' => 'required|date',
            'assigned_to' => 'nullable|date|after_or_equal:assigned_from',
            'rate_type' => 'nullable|in:hour,day,month,fixed',
            'rate' => 'nullable|numeric|min:0',
            'usage_quantity' => 'nullable|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'status' => 'required|in:assigned,returned,completed',
            'notes' => 'nullable|string',
        ]);
        if (!isset($data['cost']) || $data['cost'] === null) {
            $data['cost'] = isset($data['rate'], $data['usage_quantity']) ? round((float)$data['rate'] * (float)$data['usage_quantity'], 4) : 0;
        }
        DB::transaction(function () use ($data) {
            EquipmentAssignment::create($data);
            Equipment::whereKey($data['equipment_id'])->update(['status' => $data['status'] === 'assigned' ? 'assigned' : 'available']);
        });
        return back()->with('message', 'Equipment assignment recorded.');
    }

    public function storeFixedAsset(Request $request)
    {
        FixedAsset::create($request->validate([
            'asset_code'=>'required|string|max:50|unique:construction_fixed_assets,asset_code',
            'name'=>'required|string|max:191',
            'category'=>'nullable|string|max:100',
            'acquisition_date'=>'nullable|date',
            'acquisition_value'=>'required|numeric|min:0',
            'depreciation_method'=>'nullable|in:straight_line',
            'useful_life_months'=>'nullable|integer|min:1',
            'salvage_value'=>'nullable|numeric|min:0',
            'status'=>'required|in:active,disposed,inactive',
            'notes'=>'nullable|string',
        ]));
        return back()->with('message','Fixed asset added.');
    }

    public function receipts(PaymentAccountService $paymentAccounts)
    {
        return view('construction::receipts', $this->common() + [
            'receipts' => ProjectReceipt::with(['project', 'customer', 'account', 'warehouse', 'deposit'])->latest()->paginate(20),
            'accounts' => $paymentAccounts->validOperationalAccounts(),
        ]);
    }

    public function storeReceipt(Request $request, ConstructionAccountingService $constructionAccounting)
    {
        $data = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'receipt_date' => 'required|date',
            'amount' => 'required|numeric|min:0.0001',
            'account_id' => 'required|exists:accounts,id',
            'payment_method' => 'required|string|max:50',
            'external_reference' => 'nullable|string|max:191',
            'notes' => 'nullable|string',
        ]);
        $project = Project::findOrFail($data['project_id']);
        if (!$project->customer_id) {
            return back()->withInput()->withErrors(['project_id' => 'This project has no Client.']);
        }

        try {
            $receipt = $constructionAccounting->createProjectReceipt($data + [
                'reference_no' => 'PR-' . now()->format('YmdHis') . '-' . random_int(10, 99),
                'customer_id' => $project->customer_id,
            ], (int) Auth::id());
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->withErrors(['accounting' => $e->getMessage()]);
        }

        return back()->with('message', 'Project receipt recorded as a Client Advance. Accounting status: ' . $receipt->posting_status . '.');
    }

    public function postReceipt(Request $request, ProjectReceipt $receipt, ConstructionAccountingService $constructionAccounting)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'account_id' => 'required|exists:accounts,id',
        ]);

        try {
            $receipt = $constructionAccounting->postProjectReceipt($receipt, (int) $data['account_id'], (int) $data['warehouse_id']);
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['accounting' => $e->getMessage()]);
        }

        return back()->with('message', 'Existing project receipt posted as a Client Advance. Accounting status: ' . $receipt->posting_status . '.');
    }
}
