<?php

namespace App\Http\Controllers;

use App\Models\MoneyTransfer;
use App\Models\Account;
use App\Models\Currency;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Auth;
use App\Services\AccountingModeService;
use App\Services\PaymentAccountService;

class MoneyTransferController extends Controller
{
    public function __construct(private PaymentAccountService $paymentAccounts, private AccountingModeService $accountingMode) {}

    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('money-transfer')){
            $lims_money_transfer_all = MoneyTransfer::with(['fromAccount', 'toAccount', 'currency', 'project', 'site'])->get();
            $lims_account_list = $this->paymentAccounts->validOperationalAccounts();
            if ($this->accountingMode->isDoubleEntryAuthoritative()) $this->paymentAccounts->decorate($lims_account_list);
            $currency_list = Currency::where('is_active', true)->get();
            $currency = $currency_list->firstWhere('exchange_rate', 1) ?? $currency_list->first();
            $construction_projects = class_exists(\Modules\Project\Entities\Project::class) ? \Modules\Project\Entities\Project::orderBy('title')->get(['id','title']) : collect();
            $construction_sites = class_exists(\Modules\Construction\Entities\ConstructionSite::class) ? \Modules\Construction\Entities\ConstructionSite::with('project')->orderBy('name')->get() : collect();
            return view('backend.money_transfer.index', compact('lims_money_transfer_all', 'lims_account_list', 'currency_list', 'currency', 'construction_projects', 'construction_sites'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $data['reference_no'] = 'mtr-' . date("Ymd") . '-'. date("his");
        $transfer = MoneyTransfer::create($data);

        // === ACCOUNTING ENGINE PHASE 2E: TRANSFER ===
        $accountingService = app(\App\Services\AccountingService::class);
        $result = $accountingService->recordMoneyTransfer($transfer, 'money_transfer_created');
        if ($result->success && $result->journalEntry && $transfer->project_id) {
            $this->stampConstructionDimensions($result->journalEntry->id, $transfer);
        }
        if (!$result->success) {
            \Log::error('Accounting failed for Money Transfer', ['transfer_id' => $transfer->id, 'error' => $result->error]);
            if (\Schema::hasColumn($transfer->getTable(), 'accounting_status')) {
                $transfer->accounting_status = 'failed';
                $transfer->save();
            }
        }
        // ===========================================

        return redirect()->back()->with('message', __('db.Money transfered successfully'));
    }

    public function update(Request $request, $id)
    {
        $data = $this->validatedData($request, $id);
        $transfer = MoneyTransfer::findOrFail($id);
        $transfer->update($data);

        // === ACCOUNTING ENGINE PHASE 2E: TRANSFER UPDATE ===
        $accountingService = app(\App\Services\AccountingService::class);
        $accountingService->reverseTransaction(get_class($transfer), $transfer->id, '_reversed');
        $result = $accountingService->recordMoneyTransfer($transfer, 'money_transfer_updated');
        if ($result->success && $result->journalEntry && $transfer->project_id) {
            $this->stampConstructionDimensions($result->journalEntry->id, $transfer);
        }
        if (!$result->success) {
            \Log::error('Accounting failed for Money Transfer Update', ['transfer_id' => $transfer->id, 'error' => $result->error]);
            if (\Schema::hasColumn($transfer->getTable(), 'accounting_status')) {
                $transfer->accounting_status = 'failed';
                $transfer->save();
            }
        }
        // ===========================================

        return redirect()->back()->with('message', __('db.Money transfer updated successfully'));
    }

    private function validatedData(Request $request, $id = null): array
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:to_account_id'],
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'site_id' => ['nullable', 'integer', 'exists:construction_sites,id'],
            'external_reference' => ['nullable', 'string', 'max:191'],
            'created_at' => [$id ? 'nullable' : 'sometimes', 'string', 'max:50'],
        ]);

        if (!$id) {
            unset($data['created_at']);
        }

        if ($this->accountingMode->isDoubleEntryAuthoritative()) {
            $this->paymentAccounts->assertValidId((int) $data['from_account_id']);
            $this->paymentAccounts->assertValidId((int) $data['to_account_id']);
        }

        return $data;
    }

    public function destroy($id)
    {
        $transfer = MoneyTransfer::find($id);

        // === ACCOUNTING ENGINE PHASE 2E: TRANSFER DELETION ===
        $accountingService = app(\App\Services\AccountingService::class);
        $accountingService->reverseTransaction(get_class($transfer), $transfer->id, '_deleted');
        // ===========================================

        $transfer->delete();
        return redirect()->back()->with('not_permitted', __('db.Data deleted successfully'));
    }

    private function stampConstructionDimensions(int $journalEntryId, MoneyTransfer $transfer): void
    {
        if (!\Schema::hasColumn('journal_lines', 'project_id')) return;
        \DB::table('journal_lines')->where('journal_entry_id', $journalEntryId)->update([
            'project_id' => $transfer->project_id,
            'site_id' => $transfer->site_id,
            'cost_category_id' => null,
        ]);
    }

}
