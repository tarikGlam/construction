<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountingAccount;
use App\Models\AccountMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentAccountMappingRepairService
{
    public function __construct(
        private AccountingModeService $mode,
        private AccountingService $accounting,
        private PaymentAccountService $paymentAccounts
    ) {}

    public function inspect(?int $sampleLimit = 50, bool $includeUsageEvidence = false): array
    {
        $schemaIssues = $this->schemaIssues();
        $authoritative = $this->mode->isDoubleEntryAuthoritative();
        $parent = $authoritative && empty($schemaIssues) ? $this->cashParent() : null;
        $missing = [];
        $repairable = [];
        $ambiguous = [];
        $unsupported = [];
        $invalid = [];

        if (!Schema::hasTable('accounts')) {
            $schemaIssues[] = 'accounts';
        }
        $schemaIssues = array_values(array_unique($schemaIssues));

        if (!empty($schemaIssues)) {
            return [
                'authoritative' => $authoritative, 'schema_safe' => false, 'schema_issues' => $schemaIssues,
                'inspected_count' => 0, 'missing_count' => 0, 'ambiguous_count' => 0,
                'invalid_count' => 0, 'repairable_count' => 0,
                'sample_limit' => $sampleLimit, 'samples_capped' => false, 'missing' => [], 'repairable' => [],
                'ambiguous' => [], 'invalid' => [], 'unsupported' => [], 'historical_anomalies' => [],
            ];
        }

        $active = Account::query()->where('is_active', true);
        $eligible = (clone $active)->where('is_payment', true);
        $missingQuery = (clone $eligible)->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('account_mappings')
                ->whereColumn('account_mappings.mapped_id', 'accounts.id')
                ->where('account_mappings.mapped_type', Account::class);
        });
        $inspectedCount = (clone $active)->count();
        $missingCount = (clone $missingQuery)->count();
        if ($sampleLimit !== null) {
            $missingQuery->limit(max(1, $sampleLimit));
        }

        foreach ($missingQuery->orderBy('id')->get() as $account) {
            $item = $this->missingItem($account, $parent, $includeUsageEvidence);
            $missing[] = $item;
            if (!$authoritative || !empty($schemaIssues)) {
                $item['review_reason'] = 'Automatic repair is unsupported until authoritative double-entry accounting and its schema are ready.';
                $unsupported[] = $item;
            } elseif (!$parent) {
                $ambiguous[] = $item;
            } elseif ($this->needsOwnerClassification($account)) {
                $item['owner_question'] = true;
                $item['review_reason'] = 'The account name suggests owner investment, which cannot be inferred from its legacy payment type.';
                $ambiguous[] = $item;
            } else {
                $repairable[] = $item;
            }
        }

        $nonPaymentMissingQuery = (clone $active)->where('is_payment', false)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('account_mappings')
                    ->whereColumn('account_mappings.mapped_id', 'accounts.id')
                    ->where('account_mappings.mapped_type', Account::class);
            });
        $ambiguousCount = (clone $nonPaymentMissingQuery)->count();
        if ($sampleLimit !== null) {
            $nonPaymentMissingQuery->limit(max(1, $sampleLimit));
        }
        foreach ($nonPaymentMissingQuery->orderBy('id')->get() as $account) {
            $ambiguous[] = $this->missingItem($account, $parent, $includeUsageEvidence);
        }

        $invalidQuery = DB::table('accounts')
            ->join('account_mappings', function ($join) {
                $join->on('account_mappings.mapped_id', '=', 'accounts.id')
                    ->where('account_mappings.mapped_type', '=', Account::class);
            })
            ->leftJoin('accounting_accounts', 'accounting_accounts.id', '=', 'account_mappings.accounting_account_id')
            ->where('accounts.is_active', true)->where('accounts.is_payment', true)
            ->where(function ($query) {
                $query->whereNull('accounting_accounts.id')->orWhere('accounting_accounts.is_active', false)
                    ->orWhere('accounting_accounts.account_type', '<>', 'asset')
                    ->orWhere('accounting_accounts.is_cash_account', false);
            });
        $invalidCount = (clone $invalidQuery)->count();
        if ($sampleLimit !== null) {
            $invalidQuery->limit(max(1, $sampleLimit));
        }
        foreach ($invalidQuery->orderBy('accounts.id')->get([
            'accounts.id as account_id', 'accounts.name as account_name', 'account_mappings.id as mapping_id',
            'account_mappings.accounting_account_id',
        ]) as $row) {
            $invalid[] = [
                'account_id' => (int) $row->account_id,
                'account_name' => (string) $row->account_name,
                'mapping_id' => (int) $row->mapping_id,
                'accounting_account_id' => (int) $row->accounting_account_id,
                'reason' => 'The existing mapping is not an active Cash/Bank asset account. It will not be changed automatically.',
            ];
        }

        $repairableCount = count($repairable);

        return [
            'authoritative' => $authoritative,
            'schema_safe' => empty($schemaIssues),
            'schema_issues' => $schemaIssues,
            'inspected_count' => $inspectedCount,
            'missing_count' => $missingCount,
            'ambiguous_count' => $ambiguousCount,
            'invalid_count' => $invalidCount,
            'repairable_count' => $repairableCount,
            'sample_limit' => $sampleLimit,
            'samples_capped' => $sampleLimit !== null && ($missingCount > count($missing)
                || $ambiguousCount > count($ambiguous) || $invalidCount > count($invalid)),
            'missing' => $missing,
            'repairable' => $repairable,
            'ambiguous' => $ambiguous,
            'invalid' => $invalid,
            'unsupported' => $unsupported,
            'historical_anomalies' => [],
        ];
    }

    public function deterministicCandidate(int $accountId): array
    {
        $schemaIssues = $this->schemaIssues();
        $account = Account::whereKey($accountId)->where('is_active', true)->where('is_payment', true)->first();
        $mapped = $account && AccountMapping::where('mapped_type', Account::class)->where('mapped_id', $accountId)->exists();
        $parent = $this->mode->isDoubleEntryAuthoritative() && empty($schemaIssues) ? $this->cashParent() : null;
        if (!$account || $mapped || !$parent) {
            throw ValidationException::withMessages([
                'account' => 'This payment account is no longer eligible for a deterministic mapping repair.',
            ]);
        }
        if ($this->needsOwnerClassification($account)) {
            throw ValidationException::withMessages(['account' => 'Tell SalePro whether this account represents cash or owner investment before continuing.']);
        }

        $candidate = $this->missingItem($account, $parent, true);

        $candidate['fingerprint'] = $this->candidateFingerprint($candidate);

        return $candidate;
    }

    public function compatibleLedgerAccounts(): array
    {
        return AccountingAccount::query()->where('is_active', true)->where('account_type', 'asset')
            ->where('is_cash_account', true)->where('is_control_account', false)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('account_mappings')
                    ->whereColumn('account_mappings.accounting_account_id', 'accounting_accounts.id')
                    ->where('account_mappings.mapped_type', Account::class);
            })->orderBy('code')->limit(100)->get(['id', 'code', 'name', 'parent_id'])->map->toArray()->all();
    }

    public function existingLedgerCandidate(int $accountId, int $ledgerId): array
    {
        $base = $this->deterministicCandidate($accountId);
        $ledger = AccountingAccount::query()->whereKey($ledgerId)->where('is_active', true)
            ->where('account_type', 'asset')->where('is_cash_account', true)
            ->where('is_control_account', false)->first();
        $alreadyUsed = $ledger && AccountMapping::where('mapped_type', Account::class)
            ->where('accounting_account_id', $ledger->id)->exists();
        if (!$ledger || $alreadyUsed) {
            throw ValidationException::withMessages(['ledger_account_id' => 'Select an unused active Cash or Bank ledger account.']);
        }
        $base['existing_ledger'] = $ledger->only(['id', 'code', 'name', 'parent_id']);
        $base['fingerprint'] = $this->candidateFingerprint($base);

        return $base;
    }

    public function applyExistingCandidate(int $accountId, int $ledgerId, string $expectedFingerprint): array
    {
        $account = Account::whereKey($accountId)->lockForUpdate()->first();
        $ledger = AccountingAccount::whereKey($ledgerId)->lockForUpdate()->first();
        if (!$account || !$account->is_active || !$account->is_payment || !$ledger || !$ledger->is_active
            || $ledger->account_type !== 'asset' || !$ledger->is_cash_account || $ledger->is_control_account) {
            throw ValidationException::withMessages(['mapping' => 'The approved account or ledger is no longer compatible.']);
        }
        if (AccountMapping::where('mapped_type', Account::class)->where(function ($query) use ($accountId, $ledgerId) {
            $query->where('mapped_id', $accountId)->orWhere('accounting_account_id', $ledgerId);
        })->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['mapping' => 'The payment account or selected ledger has been mapped since preview.']);
        }
        $candidate = $this->existingLedgerCandidate($accountId, $ledgerId);
        if (!hash_equals($expectedFingerprint, $candidate['fingerprint'])) {
            throw ValidationException::withMessages(['fingerprint' => 'The payment-account or ledger dataset changed after preview.']);
        }
        AccountMapping::create(['accounting_account_id' => $ledgerId, 'mapped_type' => Account::class, 'mapped_id' => $accountId]);
        if (!$this->paymentAccounts->isValid($account->fresh())) {
            throw ValidationException::withMessages(['verification' => 'The selected mapping did not pass payment-account verification.']);
        }

        return ['payment_account_id' => $accountId, 'ledger_account_id' => $ledgerId,
            'ledger_account_code' => (string) $ledger->code, 'mapping_verified' => true];
    }

    /**
     * Execute one already-approved candidate. The caller owns the surrounding
     * transaction and locks the repair plan before reaching this method.
     */
    public function applyDeterministicCandidate(
        int $accountId,
        string $expectedCode,
        int $expectedParentId,
        string $expectedFingerprint
    ): array {
        $account = Account::whereKey($accountId)->lockForUpdate()->first();
        if (!$account || !$account->is_active || !$account->is_payment) {
            throw ValidationException::withMessages(['account' => 'The payment account eligibility changed after preview.']);
        }

        if (AccountMapping::where('mapped_type', Account::class)->where('mapped_id', $accountId)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['mapping' => 'A payment-account mapping now exists; create a new review plan.']);
        }

        $parent = $this->cashParent();
        if (!$parent || (int) $parent->id !== $expectedParentId) {
            throw ValidationException::withMessages(['parent' => 'The Cash & Bank parent changed or is now ambiguous.']);
        }
        AccountingAccount::whereKey($parent->id)->lockForUpdate()->firstOrFail();

        $candidate = $this->missingItem($account, $parent, true);
        if (!$candidate['safe_to_create'] || !hash_equals($expectedFingerprint, $this->candidateFingerprint($candidate))) {
            throw ValidationException::withMessages(['fingerprint' => 'The payment-account dataset changed after preview.']);
        }
        if ((string) $candidate['expected']['code'] !== $expectedCode
            || AccountingAccount::where('code', $expectedCode)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['code' => 'The approved account code is no longer uniquely available.']);
        }

        $ledger = AccountingAccount::create([
            'code' => $expectedCode,
            'name' => $account->name,
            'account_type' => 'asset',
            'parent_id' => $parent->id,
            'is_control_account' => false,
            'is_system' => false,
            'is_active' => true,
            'is_cash_account' => true,
        ]);
        AccountMapping::create([
            'accounting_account_id' => $ledger->id,
            'mapped_type' => Account::class,
            'mapped_id' => $account->id,
        ]);

        if (!$this->paymentAccounts->isValid($account->fresh())) {
            throw ValidationException::withMessages(['verification' => 'The new mapping did not pass payment-account verification.']);
        }

        return [
            'payment_account_id' => (int) $account->id,
            'ledger_account_id' => (int) $ledger->id,
            'ledger_account_code' => (string) $ledger->code,
            'parent_account_id' => (int) $parent->id,
            'mapping_verified' => true,
        ];
    }

    public function candidateFingerprint(array $candidate): string
    {
        $payload = [
            'account_id' => (int) $candidate['account_id'],
            'account_name' => (string) $candidate['account_name'],
            'evidence' => $candidate['evidence'] ?? [],
            'expected' => $candidate['expected'] ?? [],
            'safe_to_create' => (bool) ($candidate['safe_to_create'] ?? false),
            'existing_ledger' => $candidate['existing_ledger'] ?? null,
        ];
        $sort = function (&$value) use (&$sort): void {
            if (!is_array($value)) return;
            foreach ($value as &$child) $sort($child);
            if (!array_is_list($value)) ksort($value);
        };
        $sort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function apply(): array
    {
        $inspection = $this->inspect(null, true);
        $result = $inspection + ['repaired_count' => 0, 'repaired' => [], 'skipped' => [], 'errors' => []];

        if (!$inspection['authoritative']) {
            $result['errors'][] = 'Double-entry accounting is not authoritative; no mappings were changed.';
            return $result;
        }

        if (!$inspection['schema_safe']) {
            $result['errors'][] = 'Required AUTO_INCREMENT metadata is missing from: ' . implode(', ', $inspection['schema_issues']) . '.';
            return $result;
        }

        foreach ($inspection['repairable'] as $candidate) {
            try {
                $outcome = DB::transaction(function () use ($candidate) {
                    $account = Account::whereKey($candidate['account_id'])->lockForUpdate()->firstOrFail();
                    $mapping = AccountMapping::where('mapped_type', Account::class)
                        ->where('mapped_id', $account->id)
                        ->first();

                    if ($mapping) {
                        return ['skipped' => true, 'account_id' => $account->id, 'reason' => 'A mapping now exists; it was left unchanged.'];
                    }

                    $mapped = $this->paymentAccounts->ensureMapping($account);

                    return [
                        'skipped' => false,
                        'account_id' => $account->id,
                        'account_name' => $account->name,
                        'accounting_account_id' => $mapped->id,
                        'accounting_code' => $mapped->code,
                        'accounting_name' => $mapped->name,
                        'parent_id' => $mapped->parent_id,
                    ];
                });

                if ($outcome['skipped']) {
                    $result['skipped'][] = $outcome;
                } else {
                    $result['repaired'][] = $outcome;
                    $result['repaired_count']++;
                }
            } catch (Throwable $e) {
                report($e);
                $result['errors'][] = "Account {$candidate['account_id']} [{$candidate['account_name']}]: {$e->getMessage()}";
            }
        }

        return $result;
    }

    private function missingItem(Account $account, ?AccountingAccount $parent, bool $includeUsageEvidence = false): array
    {
        $isPayment = (bool) $account->is_payment;
        $safe = $parent && $isPayment;

        return [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'account_type' => $account->type,
            'reason' => 'No operational payment-account mapping exists.',
            'safe_to_create' => (bool) $safe,
            'review_reason' => !$parent
                ? 'The configured Cash & Bank parent could not be determined; manual review is required.'
                : (!$isPayment
                    ? 'The account is not marked as an operational payment account; human classification is required.'
                    : null),
            'evidence' => [
                'is_payment' => $isPayment,
                'is_default' => (bool) $account->is_default,
                'usage' => $includeUsageEvidence ? $this->usageEvidence($account->id) : [],
            ],
            'expected' => [
                'code' => $this->nextCode($account->id),
                'name' => $account->name,
                'account_type' => 'asset',
                'is_cash_account' => true,
                'parent_id' => $parent?->id,
                'parent_code' => $parent?->code,
                'parent_name' => $parent?->name,
            ],
        ];
    }

    public function needsOwnerClassification(Account $account): bool
    {
        return (bool) preg_match('/\b(owner|proprietor|partner|capital|investment|equity)\b/i', (string) $account->name);
    }

    public function applyOwnerClassification(int $accountId, string $classification): array
    {
        if (!in_array($classification, ['cash', 'owner_investment'], true)) {
            throw ValidationException::withMessages(['classification' => 'Choose Cash / Bank / Mobile Wallet or Owner Investment / Capital.']);
        }
        return DB::transaction(function () use ($accountId, $classification) {
            $account = Account::whereKey($accountId)->lockForUpdate()->firstOrFail();
            if (!$this->needsOwnerClassification($account)) throw ValidationException::withMessages(['account' => 'This account does not require owner classification.']);
            if (AccountMapping::where('mapped_type', Account::class)->where('mapped_id', $accountId)->lockForUpdate()->exists()) {
                return ['account_id' => $accountId, 'already_mapped' => true];
            }
            if ($classification === 'owner_investment') {
                $ledgerId = $this->accounting->getRoleAccountId(AccountingService::ROLE_OPENING_EQUITY);
                $account->update(['is_payment' => false]);
            } else {
                $ledgerId = $this->paymentAccounts->ensureMapping($account)->id;
            }
            AccountMapping::firstOrCreate(['mapped_type' => Account::class, 'mapped_id' => $accountId], ['accounting_account_id' => $ledgerId]);
            return ['account_id' => $accountId, 'ledger_account_id' => $ledgerId, 'classification' => $classification,
                'historical_transactions_modified' => 0];
        });
    }

    private function usageEvidence(int $accountId): array
    {
        $counts = [];
        foreach ([
            'payments' => ['account_id'],
            'expenses' => ['account_id'],
            'incomes' => ['account_id'],
            'payrolls' => ['account_id'],
            'money_transfers' => ['from_account_id', 'to_account_id'],
        ] as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $available = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
            if (!$available) {
                continue;
            }
            $counts[$table] = DB::table($table)->where(function ($query) use ($available, $accountId) {
                foreach ($available as $index => $column) {
                    $index === 0 ? $query->where($column, $accountId) : $query->orWhere($column, $accountId);
                }
            })->count();
        }

        return $counts;
    }

    private function cashParent(): ?AccountingAccount
    {
        if (!$this->mode->isDoubleEntryAuthoritative()) {
            return null;
        }

        try {
            $parent = AccountingAccount::find($this->accounting->getRoleAccountId(AccountingService::ROLE_CASH));
            if (!$parent || !$parent->is_active || $parent->account_type !== 'asset'
                || !$parent->is_control_account || !$parent->is_cash_account) {
                return null;
            }

            return $parent;
        } catch (Throwable) {
            return null;
        }
    }

    private function nextCode(int $accountId): string
    {
        $base = '10A' . $accountId;
        $code = $base;
        $suffix = 1;
        while (AccountingAccount::where('code', $code)->exists()) {
            $code = $base . '-' . $suffix++;
        }
        return $code;
    }

    private function schemaIssues(): array
    {
        $tables = ['accounting_accounts', 'account_mappings'];
        $missing = collect($tables)->reject(fn (string $table) => Schema::hasTable($table))->values()->all();
        if ($missing) {
            return $missing;
        }

        if (DB::getDriverName() !== 'mysql') {
            return collect($tables)
                ->reject(fn (string $table) => Schema::hasColumn($table, 'id'))
                ->values()->all();
        }

        return collect($tables)
            ->reject(function (string $table) {
                $column = DB::selectOne(
                    'SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, 'id']
                );
                return $column && str_contains(strtolower((string) $column->EXTRA), 'auto_increment');
            })->values()->all();
    }
}
