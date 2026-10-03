<?php

namespace App\Services;

use App\Models\AccountingConfig;
use App\Models\AccountingActivationSession;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Exception;
use App\Services\JournalBuilder;
use App\Services\AccountingService;

class AccountingActivationService
{
    /**
     * Get the current accounting configuration (singleton).
     */
    public function getConfig()
    {
        return AccountingConfig::firstOrCreate(['id' => 1]);
    }

    /**
     * Determines if the business MUST use 'existing_business' mode.
     */
    public function requiresExistingBusinessMode(): bool
    {
        foreach ([
            'products', 'product_warehouse', 'sales', 'purchases', 'payments',
            'returns', 'return_purchases', 'expenses', 'incomes', 'payrolls',
            'deposits', 'money_transfers', 'gift_cards', 'reward_points',
            'journal_entries', 'accounting_sync_queue', 'accounting_activation_sessions',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) return true;
        }

        return $this->hasNonzeroLegacyAccountBalances()
            || (Schema::hasTable('customers') && DB::table('customers')->where(fn ($q) => $q->where('deposit', '!=', 0)->orWhere('expense', '!=', 0)->orWhere('points', '!=', 0))->exists())
            || (Schema::hasTable('suppliers')
                && Schema::hasColumn('suppliers', 'initial_balance')
                && DB::table('suppliers')->where('initial_balance', '!=', 0)->exists());
    }

    /** Read-only gate for a prospective legacy-business cutover. */
    public function preflight(): array
    {
        $blocking = [];
        $warnings = [];
        $config = AccountingConfig::find(1);

        if ($config && ($config->enabled || $config->status === 'active')) {
            $blocking[] = 'Accounting configuration is partially configured and requires review.';
        }
        if (Schema::hasTable('journal_entries') && DB::table('journal_entries')->exists()) {
            $blocking[] = 'Existing journal records must be reviewed before cutover.';
        }
        if (Schema::hasTable('accounting_sync_queue') && DB::table('accounting_sync_queue')->whereIn('status', ['failed', 'pending'])->exists()) {
            $blocking[] = 'Pending or failed accounting records must be resolved before cutover.';
        }
        if (Schema::hasTable('product_warehouse') && DB::table('product_warehouse')->where('qty', '<', 0)->exists()) {
            $blocking[] = 'Negative inventory must be resolved before cutover.';
        }
        if (Schema::hasTable('account_mappings') && DB::table('account_mappings as am')
            ->leftJoin('accounting_accounts as aa', 'aa.id', '=', 'am.accounting_account_id')
            ->where(fn ($q) => $q->whereNull('aa.id')->orWhere('aa.is_active', false))->exists()) {
            $blocking[] = 'Invalid account mappings must be resolved before cutover.';
        }
        $anomalies = $this->controlBalanceAnomalies();
        if (collect($anomalies)->flatten(1)->isNotEmpty()) {
            $blocking[] = 'Control balance anomalies must be resolved before cutover.';
        }

        $balances = $this->calculateOpeningBalances();
        if (bccomp(
            bcsub($balances['total_assets'], $balances['total_liabilities'], 4),
            $balances['opening_balance_equity'],
            4
        ) !== 0) {
            $blocking[] = 'The proposed opening position is not balanced.';
        }
        if ($this->requiresExistingBusinessMode()) {
            $warnings[] = 'Historical operational data will be represented by one reviewed opening position; it will not be backfilled.';
        }

        return [
            'status' => $blocking ? 'blocking' : ($warnings ? 'warning' : 'ready'),
            'blocking' => $blocking,
            'warnings' => $warnings,
            'balances' => $balances,
            'requires_existing_business' => $this->requiresExistingBusinessMode(),
        ];
    }

    public function hasNonzeroLegacyAccountBalances(): bool
    {
        return DB::table('accounts')->where(function ($query) {
            $query->where('initial_balance', '!=', 0)->orWhere('total_balance', '!=', 0);
        })->exists();
    }

    /**
     * Calculates all opening balances using exact logic from SalePro operational reports.
     */
    public function calculateOpeningBalances(): array
    {
        $cashAndBank = $this->calculateTotalCashAndBank();
        $accountsReceivable = $this->calculateAccountsReceivable();
        $accountsPayable = $this->calculateAccountsPayable();
        $inventoryValue = $this->calculateInventoryValue();
        $customerDeposits = $this->calculateCustomerDepositLiability();
        $giftCards = $this->calculateGiftCardLiability();
        $rewards = $this->calculateRewardLiability();

        $totalAssets = bcadd(bcadd($cashAndBank, $accountsReceivable, 4), $inventoryValue, 4);
        $totalLiabilities = bcadd(
            bcadd($accountsPayable, $customerDeposits, 4),
            bcadd($giftCards, $rewards, 4),
            4
        );
        $openingBalanceEquity = bcsub($totalAssets, $totalLiabilities, 4);

        return [
            'cash_and_bank' => $cashAndBank,
            'accounts_receivable' => $accountsReceivable,
            'accounts_payable' => $accountsPayable,
            'inventory_value' => $inventoryValue,
            'customer_deposits' => $customerDeposits,
            'gift_card_liability' => $giftCards,
            'rewards_liability' => $rewards,
            'control_balance_anomalies' => $this->controlBalanceAnomalies(),
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'opening_balance_equity' => $openingBalanceEquity,
        ];
    }

    /**
     * Reuses the Account balance logic to calculate total Cash & Bank.
     */
    private function calculateTotalCashAndBank()
    {
        return DB::table('accounts')->where('is_active', true)->sum('total_balance') ?? 0;
    }

    /**
     * Reuses the Customer Due Report logic to calculate Accounts Receivable.
     */
    private function calculateAccountsReceivable()
    {
        return number_format(
            app(ReceivableReconciliationService::class)->operationalBalance(),
            4,
            '.',
            ''
        );
    }

    /**
     * Reuses the Supplier Due Report logic to calculate Accounts Payable.
     */
    private function calculateAccountsPayable()
    {
        $purchases = DB::table('purchases')
            ->leftJoinSub(
                DB::table('return_purchases')
                    ->select('purchase_id', DB::raw('SUM(grand_total) as returned_amount'))
                    ->groupBy('purchase_id'),
                'purchase_returns',
                'purchase_returns.purchase_id',
                '=',
                'purchases.id'
            )
            ->whereNull('purchases.deleted_at')
            ->select(
                'purchases.grand_total',
                'purchases.paid_amount',
                'purchases.exchange_rate',
                DB::raw('COALESCE(purchase_returns.returned_amount, 0) as returned_amount')
            )
            ->get();

        $total = '0.0000';
        foreach ($purchases as $purchase) {
            $exchangeRate = (float) $purchase->exchange_rate ?: 1;
            $due = max(
                0,
                ((float) $purchase->grand_total
                    - (float) $purchase->paid_amount
                    - (float) $purchase->returned_amount) / $exchangeRate
            );
            $total = bcadd($total, (string) $due, 4);
        }

        return $total;
    }

    /**
     * Reuses the Inventory Valuation logic to calculate Inventory Value.
     */
    private function calculateInventoryValue()
    {
        return app(InventoryValuationService::class)->currentValuation()['value'];
    }

    private function calculateCustomerDepositLiability(): string
    {
        $value = DB::table('customers')->selectRaw(
            'COALESCE(SUM(GREATEST(COALESCE(deposit, 0) - COALESCE(expense, 0), 0)), 0) as liability'
        )->value('liability');

        return number_format((float) $value, 4, '.', '');
    }

    private function calculateGiftCardLiability(): string
    {
        $value = DB::table('gift_cards')
            ->where('is_active', true)
            ->selectRaw('COALESCE(SUM(GREATEST(COALESCE(amount, 0) - COALESCE(expense, 0), 0)), 0) as liability')
            ->value('liability');

        return number_format((float) $value, 4, '.', '');
    }

    private function calculateRewardLiability(): string
    {
        $rate = (float) (DB::table('reward_point_settings')->latest('id')->value('redeem_amount_per_unit_rp') ?? 0);
        $points = (float) DB::table('customers')->selectRaw(
            'COALESCE(SUM(GREATEST(COALESCE(points, 0), 0)), 0) as points'
        )->value('points');

        return number_format($points * $rate, 4, '.', '');
    }

    public function controlBalanceAnomalies(): array
    {
        return [
            'negative_customer_deposits' => DB::table('customers')
                ->whereRaw('COALESCE(expense, 0) > COALESCE(deposit, 0)')
                ->select('id', 'deposit', 'expense')
                ->selectRaw('(COALESCE(expense, 0) - COALESCE(deposit, 0)) as deficit')
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'negative_gift_cards' => DB::table('gift_cards')
                ->where('is_active', true)
                ->whereRaw('COALESCE(expense, 0) > COALESCE(amount, 0)')
                ->select('id', 'amount', 'expense')
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'negative_reward_customers' => DB::table('customers')
                ->where('points', '<', 0)
                ->select('id', 'points')
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    /**
     * Returns a readiness checklist for the UI.
     */
    public function getReadinessChecklist(): array
    {
        $checklist = [];
        
        $checklist[] = [
            'status' => 'pass',
            'message' => 'Operational data analyzed successfully.'
        ];

        // Additional checks like negative stock can go here
        $negativeStockCount = DB::table('product_warehouse')->where('qty', '<', 0)->count();
        if ($negativeStockCount > 0) {
            $checklist[] = [
                'status' => 'warn',
                'message' => "Found $negativeStockCount warehouse records with negative stock."
            ];
        } else {
            $checklist[] = [
                'status' => 'pass',
                'message' => 'Stock levels reconcile properly (no negative stock).'
            ];
        }

        $controlAnomalyCount = collect($this->controlBalanceAnomalies())->flatten(1)->count();
        if ($controlAnomalyCount > 0) {
            $checklist[] = [
                'status' => 'warn',
                'message' => "Found {$controlAnomalyCount} negative opening control balance(s) requiring accountant review.",
            ];
        }

        return $checklist;
    }

    /**
     * Activates accounting and generates the single opening journal.
     */
    public function activate(string $mode, bool $openingBalancesReviewed = false, bool $backupConfirmed = false)
    {
        $config = $this->getConfig();
        if ($config->enabled) {
            throw new Exception("Accounting is already activated.");
        }

        if ($mode === 'new_business' && $this->requiresExistingBusinessMode()) {
            throw new Exception("Historical data exists. Existing business mode is required.");
        }

        if ($mode === 'existing_business' && $this->hasNonzeroLegacyAccountBalances() && !$openingBalancesReviewed) {
            throw new Exception('Legacy account balances require explicit review before activation.');
        }

        if ($mode === 'existing_business' && !$backupConfirmed) {
            throw new Exception('A current database backup must be confirmed before accounting cutover.');
        }

        $preflight = $this->preflight();
        if ($preflight['status'] === 'blocking') {
            throw new Exception(implode(' ', $preflight['blocking']));
        }

        $balances = $preflight['balances'];
        $checklist = $this->getReadinessChecklist();

        DB::beginTransaction();
        try {
            $config = AccountingConfig::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($config->enabled || $config->status === 'active') {
                throw new Exception('Accounting is already activated or requires review.');
            }
            $cutoverAt = Carbon::now();
            $journalId = null;
            $this->ensureRuntimeAccounts();
            $accountingService = app(AccountingService::class);
            $accountingService->bootstrapSemanticRoleMappings();
            if (config('accounting.tax_split_v2_new_activation_enabled', false)) {
                $config->sales_tax_policy_version = TaxAccountingComponentService::POLICY_V2;
                $config->sales_tax_policy_effective_at = $cutoverAt;
            } else {
                $config->sales_tax_policy_version = TaxAccountingComponentService::POLICY_LEGACY;
                $config->sales_tax_policy_effective_at = null;
            }
            $cashAccountId = $accountingService->getRoleAccountId(AccountingService::ROLE_CASH);
            $paymentAccounts = Account::where('is_active', true)->get();
            $mappedPaymentAccounts = [];
            foreach ($paymentAccounts as $paymentAccount) {
                $mappedPaymentAccounts[$paymentAccount->id] = $accountingService->getMappedAccount(
                    Account::class,
                    $paymentAccount->id,
                    $cashAccountId
                );
            }
            $roleValidation = $accountingService->validateSemanticRoleMappings(AccountingService::CORE_CERTIFICATION_ROLES);
            $roleFailures = array_filter($roleValidation, fn ($result) => ($result['status'] ?? null) !== 'pass');
            if (!empty($roleFailures)) {
                throw new Exception(implode(' ', array_map(fn ($result) => $result['message'], $roleFailures)));
            }

            if ($mode === 'existing_business') {
                $this->deactivateUnusedLegacyOpeningAccounts();

                $arAccountId = $accountingService->getRoleAccountId(AccountingService::ROLE_ACCOUNTS_RECEIVABLE);
                $inventoryAccountId = $accountingService->getRoleAccountId(AccountingService::ROLE_INVENTORY);
                $apAccountId = $accountingService->getRoleAccountId(AccountingService::ROLE_ACCOUNTS_PAYABLE);
                $depositAccountId = $accountingService->getRoleAccountId(AccountingService::ROLE_CUSTOMER_DEPOSIT);
                $giftCardAccountId = $accountingService->getRoleAccountId(AccountingService::ROLE_GIFT_CARD_LIABILITY);
                $rewardsAccountId = $accountingService->getRoleAccountId(AccountingService::ROLE_REWARDS_LIABILITY);
                $equityAccountId = $accountingService->getRoleAccountId(AccountingService::ROLE_OPENING_EQUITY);

                $builder = JournalBuilder::create()
                    ->setSource('activation', 1)
                    ->setEventType('opening_balance')
                    ->setReference('JE-OPENING-'.date('YmdHis'))
                    ->setDate($cutoverAt)
                    ->setNote('Initial Opening Balance');

                if ($balances['accounts_receivable'] > 0) $builder->addDebit($arAccountId, $balances['accounts_receivable'], 'Opening AR');
                if ($balances['inventory_value'] > 0) $builder->addDebit($inventoryAccountId, $balances['inventory_value'], 'Opening Inventory');
                foreach ($paymentAccounts as $paymentAccount) {
                    $openingBalance = (float) $paymentAccount->total_balance;
                    if ($openingBalance > 0) {
                        $builder->addDebit($mappedPaymentAccounts[$paymentAccount->id], $openingBalance, 'Opening '.$paymentAccount->name);
                    } elseif ($openingBalance < 0) {
                        $builder->addCredit($mappedPaymentAccounts[$paymentAccount->id], abs($openingBalance), 'Opening '.$paymentAccount->name.' Overdraft');
                    }
                }

                if ($balances['accounts_payable'] > 0) $builder->addCredit($apAccountId, $balances['accounts_payable'], 'Opening AP');
                if ($balances['customer_deposits'] > 0) $builder->addCredit($depositAccountId, $balances['customer_deposits'], 'Opening Customer Deposits');
                if ($balances['gift_card_liability'] > 0) $builder->addCredit($giftCardAccountId, $balances['gift_card_liability'], 'Opening Gift Cards');
                if ($balances['rewards_liability'] > 0) $builder->addCredit($rewardsAccountId, $balances['rewards_liability'], 'Opening Rewards');
                
                if ($balances['opening_balance_equity'] > 0) {
                    $builder->addCredit($equityAccountId, $balances['opening_balance_equity'], 'Opening Balance Equity');
                } elseif ($balances['opening_balance_equity'] < 0) {
                    $builder->addDebit($equityAccountId, abs($balances['opening_balance_equity']), 'Opening Balance Equity');
                }

                $journal = $builder->save();
                $journalId = $journal->id;
            }

            $session = AccountingActivationSession::create([
                'mode' => $mode,
                'start_date' => $cutoverAt->toDateString(),
                'summary_json' => json_encode(array_merge($balances, [
                    'cutover_at' => $cutoverAt->toIso8601String(),
                    'backup_confirmed_by_user' => $backupConfirmed,
                ])),
                'validation_json' => json_encode($checklist),
                'opening_journal_entry_id' => $journalId,
                'activated_by' => auth()->id() ?? 1,
                'activated_at' => $cutoverAt,
            ]);

            $config->enabled = true;
            $config->status = 'active';
            $config->activation_mode = $mode;
            $config->start_date = $session->start_date;
            $config->cutover_at = $cutoverAt;
            $config->opening_journal_entry_id = $journalId;
            $config->activated_by = auth()->id() ?? 1;
            $config->activated_at = $cutoverAt;
            $config->save();

            DB::commit();
            return $session;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function reset()
    {
        $config = $this->getConfig();
        if (!$config->enabled) {
            throw new Exception("Accounting is not activated.");
        }

        $count = DB::table('journal_entries')
            ->where('id', '!=', $config->opening_journal_entry_id ?? 0)
            ->whereDate('entry_date', '>=', $config->start_date)
            ->count();

        if ($count > 0) {
            throw new Exception("Cannot reset: Post-opening journals exist.");
        }

        DB::beginTransaction();
        try {
            if ($config->opening_journal_entry_id) {
                DB::table('journal_lines')->where('journal_entry_id', $config->opening_journal_entry_id)->delete();
                DB::table('journal_entries')->where('id', $config->opening_journal_entry_id)->delete();
            }

            $config->enabled = false;
            $config->status = 'not_activated';
            $config->activation_mode = null;
            $config->start_date = null;
            $config->cutover_at = null;
            $config->opening_journal_entry_id = null;
            $config->save();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function getDefaultAccountId($code, $name, $type, bool $isCashAccount = false)
    {
        $account = DB::table('accounting_accounts')->where('code', $code)->first();
        if (!$account) {
            $id = DB::table('accounting_accounts')->insertGetId([
                'name' => $name,
                'code' => $code,
                'account_type' => $type,
                'is_active' => true,
                'is_system' => true,
                'is_control_account' => true,
                'is_cash_account' => $isCashAccount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $id;
        }
        DB::table('accounting_accounts')
            ->where('id', $account->id)
            ->update(array_filter([
                'account_type' => $type,
                'is_active' => true,
                'is_system' => true,
                'is_cash_account' => $isCashAccount ? true : null,
                'updated_at' => now(),
            ], fn ($value) => $value !== null));

        return $account->id;
    }

    private function ensureRuntimeAccounts(): void
    {
        $accounts = [
            ['1000', 'Cash & Bank', 'asset', true],
            ['1100', 'Accounts Receivable', 'asset', false],
            ['1150', 'Employee Advance Receivable', 'asset', false],
            ['1200', 'Inventory', 'asset', false],
            ['1250', 'Input VAT', 'asset', false],
            ['2100', 'Accounts Payable', 'liability', false],
            ['2200', 'Output Tax Payable', 'liability', false],
            ['2210', 'Customer Deposits', 'liability', false],
            ['2220', 'Customer Rewards', 'liability', false],
            ['2250', 'Gift Card Liability', 'liability', false],
            ['3900', 'Opening Balance Equity', 'equity', false],
            ['4100', 'Sales Revenue', 'revenue', false],
            ['4150', 'Sales Returns', 'revenue', false],
            ['4160', 'Sales Discounts', 'revenue', false],
            ['4300', 'Other Income', 'revenue', false],
            ['5000', 'Cost of Goods Sold', 'cogs', false],
            ['5100', 'Payroll Expense', 'expense', false],
            ['5150', 'Purchase Returns', 'expense', false],
            ['5200', 'Freight In', 'expense', false],
            ['5210', 'Rewards Expense', 'expense', false],
            ['5300', 'Purchase Discounts', 'expense', false],
            ['6100', 'General Expense', 'expense', false],
        ];

        foreach ($accounts as [$code, $name, $type, $isCashAccount]) {
            $this->getDefaultAccountId($code, $name, $type, $isCashAccount);
        }
    }

    private function deactivateUnusedLegacyOpeningAccounts(): void
    {
        $legacyCodes = [
            'accounts_receivable',
            'inventory',
            'cash',
            'accounts_payable',
            'opening_balance_equity',
        ];

        $usedAccountIds = DB::table('journal_lines')
            ->whereIn('accounting_account_id', function ($query) use ($legacyCodes) {
                $query->select('id')
                    ->from('accounting_accounts')
                    ->whereIn('code', $legacyCodes);
            })
            ->pluck('accounting_account_id')
            ->all();

        DB::table('accounting_accounts')
            ->whereIn('code', $legacyCodes)
            ->whereNotIn('id', $usedAccountIds)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }
}
