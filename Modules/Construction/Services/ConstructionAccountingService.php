<?php

namespace Modules\Construction\Services;

use App\Models\Deposit;
use App\Models\Account;
use App\Models\AccountingAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\CustomerDepositService;
use App\Services\EmployeeAdvanceService;
use App\Services\PaymentAccountService;
use App\Services\JournalBuilder;
use Modules\Construction\Entities\EmployeeAdvance;
use Modules\Construction\Entities\EmployeeAdvanceSettlement;
use Modules\Construction\Entities\EmployeeProjectExpense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Construction\Entities\CostCategory;
use Modules\Construction\Entities\ProjectCost;
use Modules\Construction\Entities\ProjectReceipt;
use Modules\Construction\Entities\SubcontractorContract;
use Modules\Construction\Entities\SubcontractorPayment;
use Modules\Construction\Entities\Shareholder;
use Modules\Construction\Entities\ShareholderTransaction;

/**
 * Bridges Construction operational records into SalePro's authoritative
 * expense, customer-deposit and double-entry accounting engines.
 *
 * Until project billing/progress certificates exist, client project receipts
 * are treated as customer advances (liabilities), never as earned revenue.
 */
class ConstructionAccountingService
{
    public function __construct(
        private AccountingService $accounting,
        private PaymentAccountService $paymentAccounts,
        private CustomerDepositService $customerDeposits,
    ) {
    }

    public function createPaidProjectCost(array $data, int $userId): ProjectCost
    {
        return DB::transaction(function () use ($data, $userId) {
            $cost = ProjectCost::create([
                'project_id' => $data['project_id'],
                'cost_category_id' => $data['cost_category_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'account_id' => $data['account_id'],
                'date' => $data['date'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'attachment' => $data['attachment'] ?? null,
                'posting_status' => 'operational',
                'created_by' => $userId,
            ]);

            return $this->postProjectCost($cost, (int) $data['account_id'], (int) $data['warehouse_id']);
        });
    }

    public function postProjectCost(ProjectCost $cost, int $accountId, int $warehouseId): ProjectCost
    {
        return DB::transaction(function () use ($cost, $accountId, $warehouseId) {
            $cost = ProjectCost::whereKey($cost->id)->lockForUpdate()->firstOrFail();
            if ($cost->expense_id) {
                return $cost->fresh(['expense']);
            }

            $this->paymentAccounts->assertValidId($accountId);
            $warehouse = Warehouse::whereKey($warehouseId)->where('is_active', true)->firstOrFail();
            $expenseCategory = $this->expenseCategoryFor(CostCategory::findOrFail($cost->cost_category_id));

            $expense = new Expense();
            $expense->forceFill([
                'reference_no' => 'PCR-' . str_pad((string) $cost->id, 6, '0', STR_PAD_LEFT),
                'expense_category_id' => $expenseCategory->id,
                'warehouse_id' => $warehouse->id,
                'account_id' => $accountId,
                'user_id' => $cost->created_by ?: auth()->id(),
                'type' => 'project_cost',
                'amount' => $cost->amount,
                'tax_id' => null,
                'tax_name' => null,
                'tax_rate' => 0,
                'tax' => 0,
                'note' => trim(($cost->reference ? $cost->reference . ' · ' : '') . $cost->description),
                'accounting_status' => 'pending',
                'project_id' => $cost->project_id,
                'site_id' => null,
                'cost_category_id' => $cost->cost_category_id,
                'created_at' => $this->postingTimestamp($cost->date),
                'updated_at' => now(),
            ]);
            $expense->save();

            $result = $this->accounting->recordExpense($expense);
            if (!$result->isSuccess()) {
                throw new \RuntimeException($result->getMessage() ?: 'Project cost accounting posting failed.');
            }
            if ($result->isPosted()) {
                $expense->accounting_status = 'posted';
                $expense->saveQuietly();
                $this->stampJournalDimensions(
                    $result->journalEntry?->id,
                    (int) $cost->project_id,
                    null,
                    (int) $cost->cost_category_id
                );
            }

            $cost->forceFill([
                'expense_id' => $expense->id,
                'warehouse_id' => $warehouse->id,
                'account_id' => $accountId,
                'posting_status' => $result->sourceStatus(),
            ])->save();

            return $cost->fresh(['expense', 'warehouse', 'account']);
        });
    }

    public function createProjectReceipt(array $data, int $userId): ProjectReceipt
    {
        return DB::transaction(function () use ($data, $userId) {
            $receipt = ProjectReceipt::create([
                'reference_no' => $data['reference_no'],
                'project_id' => $data['project_id'],
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'],
                'receipt_date' => $data['receipt_date'],
                'amount' => $data['amount'],
                'account_id' => $data['account_id'],
                'payment_method' => $data['payment_method'],
                'external_reference' => $data['external_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'posting_status' => 'operational',
                'created_by' => $userId,
            ]);

            return $this->postProjectReceipt($receipt, (int) $data['account_id'], (int) $data['warehouse_id']);
        });
    }

    public function postProjectReceipt(ProjectReceipt $receipt, int $accountId, int $warehouseId): ProjectReceipt
    {
        return DB::transaction(function () use ($receipt, $accountId, $warehouseId) {
            $receipt = ProjectReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->deposit_id) {
                return $receipt->fresh(['deposit']);
            }

            $this->paymentAccounts->assertValidDepositReceivingAccountId($accountId);
            $warehouse = Warehouse::whereKey($warehouseId)->where('is_active', true)->firstOrFail();
            $this->customerDeposits->addGross((int) $receipt->customer_id, $receipt->amount);

            $deposit = new Deposit();
            $deposit->forceFill([
                'amount' => $receipt->amount,
                'deposit_type' => 'live',
                'account_id' => $accountId,
                'customer_id' => $receipt->customer_id,
                'user_id' => $receipt->created_by ?: auth()->id() ?: 1,
                'warehouse_id' => $warehouse->id,
                'request_token' => (string) Str::uuid(),
                'note' => 'Project advance ' . $receipt->reference_no . ($receipt->notes ? ' · ' . $receipt->notes : ''),
                'accounting_status' => 'pending',
                'created_at' => $this->postingTimestamp($receipt->receipt_date),
                'updated_at' => now(),
            ]);
            $deposit->save();

            $result = $this->accounting->recordDeposit($deposit, 'construction_project_advance_received');
            if (!$result->isSuccess()) {
                throw new \RuntimeException($result->getMessage() ?: 'Project receipt accounting posting failed.');
            }
            if ($result->isPosted()) {
                $deposit->accounting_status = 'posted';
                $deposit->saveQuietly();
                $this->stampJournalDimensions(
                    $result->journalEntry?->id,
                    (int) $receipt->project_id,
                    null,
                    null
                );
            }

            $receipt->forceFill([
                'account_id' => $accountId,
                'warehouse_id' => $warehouse->id,
                'deposit_id' => $deposit->id,
                'posting_status' => $result->sourceStatus(),
            ])->save();

            return $receipt->fresh(['deposit', 'warehouse', 'account']);
        });
    }

    public function createSubcontractorPayment(array $data, int $userId): SubcontractorPayment
    {
        return DB::transaction(function () use ($data, $userId) {
            $contract = SubcontractorContract::whereKey($data['subcontractor_contract_id'])->lockForUpdate()->firstOrFail();
            if ($contract->status === 'cancelled') {
                throw new \RuntimeException('Cancelled subcontract contracts cannot receive payments.');
            }

            $amount = (float) $data['amount'];
            $outstanding = max(0, (float) $contract->contract_value - (float) $contract->paid_amount);
            if ($amount <= 0 || $amount - $outstanding > 0.0001) {
                throw new \RuntimeException('Subcontract payment exceeds the outstanding contract amount.');
            }

            $this->paymentAccounts->assertValidId((int) $data['account_id']);
            $warehouse = Warehouse::whereKey($data['warehouse_id'])->where('is_active', true)->firstOrFail();
            $costCategory = CostCategory::firstOrCreate(
                ['name' => 'Subcontract'],
                ['code' => 'SUBCONTRACT', 'active' => true]
            );
            $expenseCategory = $this->expenseCategoryFor($costCategory);

            $payment = SubcontractorPayment::create([
                'subcontractor_contract_id' => $contract->id,
                'project_id' => $contract->project_id,
                'subcontractor_id' => $contract->subcontractor_id,
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'warehouse_id' => $warehouse->id,
                'account_id' => $data['account_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'posting_status' => 'operational',
                'created_by' => $userId,
            ]);

            $expense = new Expense();
            $expense->forceFill([
                'reference_no' => 'SCP-' . str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                'expense_category_id' => $expenseCategory->id,
                'warehouse_id' => $warehouse->id,
                'account_id' => (int) $data['account_id'],
                'user_id' => $userId,
                'type' => 'subcontractor_payment',
                'amount' => $payment->amount,
                'tax_id' => null,
                'tax_name' => null,
                'tax_rate' => 0,
                'tax' => 0,
                'note' => trim('Subcontract ' . $contract->contract_no . ($payment->reference ? ' · ' . $payment->reference : '') . ($payment->notes ? ' · ' . $payment->notes : '')),
                'accounting_status' => 'pending',
                'project_id' => $contract->project_id,
                'site_id' => null,
                'cost_category_id' => $costCategory->id,
                'created_at' => $this->postingTimestamp($payment->payment_date),
                'updated_at' => now(),
            ]);
            $expense->save();

            $result = $this->accounting->recordExpense($expense);
            if (!$result->isSuccess()) {
                throw new \RuntimeException($result->getMessage() ?: 'Subcontractor payment accounting posting failed.');
            }
            if ($result->isPosted()) {
                $expense->accounting_status = 'posted';
                $expense->saveQuietly();
                $this->stampJournalDimensions(
                    $result->journalEntry?->id,
                    (int) $contract->project_id,
                    null,
                    (int) $costCategory->id
                );
            }

            $payment->expense_id = $expense->id;
            $payment->posting_status = $result->sourceStatus();
            $payment->save();

            $contract->paid_amount = bcadd((string) $contract->paid_amount, (string) $payment->amount, 4);
            $contract->save();

            return $payment->fresh(['contract', 'project', 'subcontractor', 'expense']);
        });
    }

    public function createEmployeeProjectExpense(array $data, int $userId): EmployeeProjectExpense
    {
        return DB::transaction(function () use ($data, $userId) {
            $this->paymentAccounts->assertValidId((int) $data['account_id']);
            $warehouse = Warehouse::whereKey($data['warehouse_id'])->where('is_active', true)->firstOrFail();
            $costCategory = CostCategory::findOrFail($data['cost_category_id']);
            $expenseCategory = $this->expenseCategoryFor($costCategory);

            $record = EmployeeProjectExpense::create([
                'employee_id' => $data['employee_id'], 'project_id' => $data['project_id'] ?? null,
                'site_id' => $data['site_id'] ?? null, 'cost_category_id' => $costCategory->id,
                'warehouse_id' => $warehouse->id, 'account_id' => $data['account_id'],
                'expense_date' => $data['expense_date'], 'amount' => $data['amount'],
                'purpose' => $data['purpose'], 'reference' => $data['reference'] ?? null,
                'posting_status' => 'operational', 'created_by' => $userId,
            ]);

            $expense = new Expense();
            $expense->forceFill([
                'reference_no' => 'CEE-' . str_pad((string) $record->id, 6, '0', STR_PAD_LEFT),
                'expense_category_id' => $expenseCategory->id, 'warehouse_id' => $warehouse->id,
                'account_id' => (int) $data['account_id'], 'user_id' => $userId,
                'employee_id' => $data['employee_id'], 'type' => 'construction_employee_expense',
                'amount' => $data['amount'], 'tax_id' => null, 'tax_name' => null, 'tax_rate' => 0, 'tax' => 0,
                'note' => trim(($data['reference'] ?? '') . (($data['reference'] ?? null) ? ' · ' : '') . $data['purpose']),
                'accounting_status' => 'pending', 'project_id' => $data['project_id'] ?? null,
                'site_id' => $data['site_id'] ?? null, 'cost_category_id' => $costCategory->id,
                'created_at' => $this->postingTimestamp($data['expense_date']), 'updated_at' => now(),
            ]);
            $expense->save();

            $result = $this->accounting->recordExpense($expense);
            if (!$result->isSuccess()) throw new \RuntimeException($result->getMessage() ?: 'Employee project expense accounting posting failed.');
            if ($result->isPosted()) {
                $expense->accounting_status = 'posted'; $expense->saveQuietly();
                if (!empty($data['project_id'])) $this->stampJournalDimensions($result->journalEntry?->id, (int) $data['project_id'], $data['site_id'] ?? null, (int) $costCategory->id);
            }
            $record->forceFill(['expense_id' => $expense->id, 'posting_status' => $result->sourceStatus()])->save();
            return $record->fresh(['employee','project','expense']);
        });
    }

    public function createEmployeeAdvance(array $data, int $userId): EmployeeAdvance
    {
        return DB::transaction(function () use ($data, $userId) {
            $this->paymentAccounts->assertValidId((int) $data['account_id']);
            $warehouse = Warehouse::whereKey($data['warehouse_id'])->where('is_active', true)->firstOrFail();
            $record = EmployeeAdvance::create([
                'employee_id' => $data['employee_id'], 'project_id' => $data['project_id'] ?? null,
                'site_id' => $data['site_id'] ?? null, 'warehouse_id' => $warehouse->id,
                'account_id' => $data['account_id'], 'advance_date' => $data['advance_date'],
                'amount' => $data['amount'], 'outstanding_amount' => $data['amount'],
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'status' => 'open', 'posting_status' => 'operational', 'created_by' => $userId,
            ]);

            $expense = new Expense();
            $expense->forceFill([
                'reference_no' => 'CEA-' . str_pad((string) $record->id, 6, '0', STR_PAD_LEFT),
                'expense_category_id' => 0, 'warehouse_id' => $warehouse->id, 'account_id' => (int) $data['account_id'],
                'user_id' => $userId, 'employee_id' => $data['employee_id'], 'type' => 'construction_employee_advance',
                'amount' => $data['amount'], 'tax_id' => null, 'tax_name' => null, 'tax_rate' => 0, 'tax' => 0,
                'note' => trim('Construction employee advance' . (($data['reference'] ?? null) ? ' · '.$data['reference'] : '') . (($data['notes'] ?? null) ? ' · '.$data['notes'] : '')),
                'accounting_status' => 'pending', 'project_id' => $data['project_id'] ?? null, 'site_id' => $data['site_id'] ?? null,
                'cost_category_id' => null, 'created_at' => $this->postingTimestamp($data['advance_date']), 'updated_at' => now(),
            ]);
            $expense->save();
            $result = $this->accounting->recordEmployeeAdvance($expense);
            if (!$result->isSuccess()) throw new \RuntimeException($result->getMessage() ?: 'Employee advance accounting posting failed.');
            if ($result->isPosted()) {
                $expense->accounting_status = 'posted'; $expense->saveQuietly();
                if (!empty($data['project_id'])) $this->stampJournalDimensions($result->journalEntry?->id, (int) $data['project_id'], $data['site_id'] ?? null, null);
            }
            $record->forceFill(['expense_id' => $expense->id, 'posting_status' => $result->sourceStatus()])->save();
            return $record->fresh(['employee','project','expense']);
        });
    }

    public function settleEmployeeAdvance(EmployeeAdvance $advance, array $data, int $userId): EmployeeAdvanceSettlement
    {
        return DB::transaction(function () use ($advance, $data, $userId) {
            $advance = EmployeeAdvance::whereKey($advance->id)->lockForUpdate()->firstOrFail();
            $amount = (float) $data['amount'];
            $employeeOutstanding = app(EmployeeAdvanceService::class)
                ->outstandingForEmployee((int) $advance->employee_id);
            if ($amount <= 0
                || $amount - (float) $advance->outstanding_amount > 0.0001
                || $amount - $employeeOutstanding > 0.0001) {
                throw new \RuntimeException('Settlement amount exceeds the outstanding employee advance.');
            }
            $projectId = $data['project_id'] ?? $advance->project_id;
            $siteId = $data['site_id'] ?? $advance->site_id;
            $settlement = EmployeeAdvanceSettlement::create([
                'employee_advance_id' => $advance->id, 'project_id' => $projectId, 'site_id' => $siteId,
                'settlement_date' => $data['settlement_date'], 'settlement_type' => $data['settlement_type'],
                'amount' => $amount, 'cost_category_id' => $data['cost_category_id'] ?? null,
                'account_id' => $data['account_id'] ?? null, 'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null, 'created_by' => $userId,
            ]);

            $builder = JournalBuilder::create()
                ->setSource(EmployeeAdvanceSettlement::class, $settlement->id)
                ->setSourceSubtype('employee_advance_settlement')
                ->setEventType($data['settlement_type'] === 'cash_return' ? 'employee_advance_cash_returned' : 'employee_advance_expense_settled')
                ->setReference('CEAS-' . str_pad((string) $settlement->id, 6, '0', STR_PAD_LEFT))
                ->setDate(Carbon::parse($data['settlement_date'])->toDateString())
                ->setWarehouse((int) $advance->warehouse_id)
                ->setNote(trim(($data['reference'] ?? '') . (($data['reference'] ?? null) ? ' · ' : '') . ($data['notes'] ?? '')));

            if ($data['settlement_type'] === 'cash_return') {
                $this->paymentAccounts->assertValidId((int) $data['account_id']);
                $builder->addDebit($this->accounting->resolveStrictCashAccountId((int) $data['account_id']), $amount, 'Employee advance cash returned')
                    ->addCredit($this->accounting->getRoleAccountId(\App\Services\AccountingService::ROLE_EMPLOYEE_ADVANCE_RECEIVABLE), $amount, 'Employee advance receivable settled');
            } else {
                $costCategory = CostCategory::findOrFail($data['cost_category_id']);
                $expenseCategory = $this->expenseCategoryFor($costCategory);
                $expenseAccount = $this->accounting->getMappedAccount(ExpenseCategory::class, $expenseCategory->id, $this->accounting->getRoleAccountId(\App\Services\AccountingService::ROLE_OPERATING_EXPENSE));
                $builder->addDebit($expenseAccount, $amount, 'Employee advance expense settlement')
                    ->addCredit($this->accounting->getRoleAccountId(\App\Services\AccountingService::ROLE_EMPLOYEE_ADVANCE_RECEIVABLE), $amount, 'Employee advance receivable settled');

            }

            $journal = $builder->save();
            $settlement->journal_entry_id = $journal->id;
            $settlement->save();
            if ($projectId) $this->stampJournalDimensions($journal->id, (int) $projectId, $siteId ? (int) $siteId : null, !empty($data['cost_category_id']) ? (int) $data['cost_category_id'] : null);

            if ($data['settlement_type'] === 'cash_return') $advance->cash_returned_amount = bcadd((string) $advance->cash_returned_amount, (string) $amount, 4);
            else $advance->settled_expense_amount = bcadd((string) $advance->settled_expense_amount, (string) $amount, 4);
            $advance->outstanding_amount = max(0, (float) $advance->amount - (float) $advance->settled_expense_amount - (float) $advance->cash_returned_amount);
            $advance->status = (float) $advance->outstanding_amount <= 0.0001 ? 'settled' : 'partial';
            $advance->save();
            return $settlement->fresh(['advance','expense','category']);
        });
    }

    public function createShareholderTransaction(array $data, int $userId): ShareholderTransaction
    {
        return DB::transaction(function () use ($data, $userId) {
            $shareholder = Shareholder::whereKey($data['shareholder_id'])->lockForUpdate()->firstOrFail();
            $type = (string) $data['transaction_type'];
            $amount = round((float) $data['amount'], 4);
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Shareholder transaction amount must be greater than zero.');
            }

            $allowed = ['capital_contribution', 'capital_withdrawal', 'loan_received', 'loan_repayment'];
            if (!in_array($type, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported shareholder transaction type.');
            }

            if ($type === 'capital_withdrawal') {
                $capitalBalance = (float) ShareholderTransaction::where('shareholder_id', $shareholder->id)
                    ->selectRaw("COALESCE(SUM(CASE WHEN transaction_type='capital_contribution' THEN amount WHEN transaction_type='capital_withdrawal' THEN -amount ELSE 0 END),0) balance")
                    ->value('balance');
                if ($amount - $capitalBalance > 0.0001) {
                    throw new \RuntimeException('Capital withdrawal exceeds this shareholder\'s recorded capital balance.');
                }
            }
            if ($type === 'loan_repayment') {
                $loanBalance = (float) ShareholderTransaction::where('shareholder_id', $shareholder->id)
                    ->selectRaw("COALESCE(SUM(CASE WHEN transaction_type='loan_received' THEN amount WHEN transaction_type='loan_repayment' THEN -amount ELSE 0 END),0) balance")
                    ->value('balance');
                if ($amount - $loanBalance > 0.0001) {
                    throw new \RuntimeException('Loan repayment exceeds this shareholder\'s recorded loan balance.');
                }
            }

            $this->paymentAccounts->assertValidId((int) $data['account_id']);
            $cashAccount = $this->paymentAccounts->ensureMapping(Account::findOrFail((int) $data['account_id']));
            $capitalAccount = $this->shareholderLedger('3200', 'Shareholder Capital', 'equity', '3000');
            $loanAccount = $this->shareholderLedger('2300', 'Shareholder Loans Payable', 'liability', '2000');

            $transaction = ShareholderTransaction::create([
                'shareholder_id' => $shareholder->id,
                'transaction_date' => $data['transaction_date'],
                'transaction_type' => $type,
                'amount' => $amount,
                'account_id' => (int) $data['account_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'posting_status' => 'pending',
                'created_by' => $userId,
            ]);

            $reference = 'SHT-' . str_pad((string) $transaction->id, 6, '0', STR_PAD_LEFT);
            $label = match ($type) {
                'capital_contribution' => 'Shareholder Capital Contribution',
                'capital_withdrawal' => 'Shareholder Capital Withdrawal',
                'loan_received' => 'Shareholder Loan Received',
                'loan_repayment' => 'Shareholder Loan Repayment',
            };

            $builder = JournalBuilder::create()
                ->setSource(ShareholderTransaction::class, $transaction->id)
                ->setSourceSubtype('construction_shareholder')
                ->setEventType($type)
                ->setReference($reference)
                ->setDate($data['transaction_date'])
                ->setGlobalWarehouseScope()
                ->setCreatedBy($userId)
                ->setNote(trim($label . ' · ' . $shareholder->name . (($data['reference'] ?? null) ? ' · ' . $data['reference'] : '')));

            if ($type === 'capital_contribution') {
                $builder->addDebit($cashAccount->id, $amount, $label)
                    ->addCredit($capitalAccount->id, $amount, $label);
            } elseif ($type === 'capital_withdrawal') {
                $builder->addDebit($capitalAccount->id, $amount, $label)
                    ->addCredit($cashAccount->id, $amount, $label);
            } elseif ($type === 'loan_received') {
                $builder->addDebit($cashAccount->id, $amount, $label)
                    ->addCredit($loanAccount->id, $amount, $label);
            } else {
                $builder->addDebit($loanAccount->id, $amount, $label)
                    ->addCredit($cashAccount->id, $amount, $label);
            }

            $journal = $builder->save();
            $transaction->forceFill([
                'journal_entry_id' => $journal->id,
                'posting_status' => 'posted',
            ])->save();

            return $transaction->fresh(['shareholder', 'account', 'journalEntry']);
        });
    }

    private function shareholderLedger(string $code, string $name, string $type, string $parentCode): AccountingAccount
    {
        $parent = AccountingAccount::where('code', $parentCode)->first();
        return AccountingAccount::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'account_type' => $type,
                'parent_id' => $parent?->id,
                'is_control_account' => true,
                'is_system' => true,
                'is_active' => true,
            ]
        );
    }

    private function stampJournalDimensions(?int $journalEntryId, int $projectId, ?int $siteId, ?int $costCategoryId): void
    {
        if (!$journalEntryId || !Schema::hasColumn('journal_lines', 'project_id')) {
            return;
        }

        DB::table('journal_lines')->where('journal_entry_id', $journalEntryId)->update([
            'project_id' => $projectId,
            'site_id' => $siteId,
            'cost_category_id' => $costCategoryId,
        ]);
    }

    private function postingTimestamp(string|\DateTimeInterface $businessDate): Carbon
    {
        $date = Carbon::parse($businessDate);

        return $date->isToday() ? now() : $date->startOfDay();
    }

    private function expenseCategoryFor(CostCategory $costCategory): ExpenseCategory
    {
        if ($costCategory->expense_category_id) {
            $existing = ExpenseCategory::whereKey($costCategory->expense_category_id)->where('is_active', true)->first();
            if ($existing) return $existing;
        }

        $expenseCategory = ExpenseCategory::firstOrCreate(
            ['code' => 'CON-COST-' . $costCategory->id],
            ['name' => 'Construction - ' . $costCategory->name, 'is_active' => true]
        );
        if (!$expenseCategory->is_active) {
            $expenseCategory->is_active = true;
            $expenseCategory->save();
        }

        if ((int) $costCategory->expense_category_id !== (int) $expenseCategory->id) {
            $costCategory->expense_category_id = $expenseCategory->id;
            $costCategory->save();
        }

        return $expenseCategory;
    }
}
