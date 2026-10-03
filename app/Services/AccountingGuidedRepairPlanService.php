<?php

namespace App\Services;

use App\Models\AccountingRepairAuditEvent;
use App\Models\AccountingRepairPlan;
use App\Models\AccountingSyncQueue;
use App\Models\AccountingPeriod;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\Returns;
use App\Models\ReturnPurchase;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payroll;
use App\Models\MoneyTransfer;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountingGuidedRepairPlanService
{
    public const PREVIEW_PERMISSION = 'accounting-health-repair-preview';
    public const APPROVE_PERMISSION = 'accounting-health-repair-approve';
    public const EXECUTE_PERMISSION = 'accounting-health-repair-execute';
    public const AUDIT_PERMISSION = 'accounting-health-repair-audit';

    /** Repairs omitted here are deliberately diagnostic-only. */
    private const CATALOG = [
        'retry_missing_accounting_post' => ['permission' => self::EXECUTE_PERMISSION, 'backup' => true, 'closed_period' => 'refuse'],
        'create_missing_payment_account_mapping' => ['permission' => self::EXECUTE_PERMISSION, 'backup' => true, 'closed_period' => 'not_applicable'],
        'map_existing_payment_account' => ['permission' => self::EXECUTE_PERMISSION, 'backup' => true, 'closed_period' => 'not_applicable'],
        'classify_owner_payment_account' => ['permission' => self::EXECUTE_PERMISSION, 'backup' => true, 'closed_period' => 'not_applicable'],
        'reverse_deterministic_orphan_journal' => ['permission' => self::EXECUTE_PERMISSION, 'backup' => true, 'closed_period' => 'refuse'],
        'reverse_owner_confirmed_orphan_journal' => ['permission' => self::EXECUTE_PERMISSION, 'backup' => true, 'closed_period' => 'refuse'],
    ];

    private const RETRYABLE_MODELS = [
        Sale::class => 'recordSale', Purchase::class => 'recordPurchase', Returns::class => 'recordSaleReturn',
        ReturnPurchase::class => 'recordPurchaseReturn', Payment::class => 'recordPayment', Expense::class => 'recordExpense',
        Income::class => 'recordIncome', Payroll::class => 'recordPayroll', MoneyTransfer::class => 'recordMoneyTransfer',
    ];

    public function previewOrphanJournal(\App\Models\JournalEntry $journal, string $idempotencyKey, bool $ownerConfirmed): AccountingRepairPlan
    {
        $journal->load('lines');
        $classification = app(JournalSourceIntegrityService::class)->classify($journal);
        $path = $classification['repair_path'] ?? null;
        if ($path !== 'deterministic' && !($path === 'owner_confirmed' && $ownerConfirmed)) {
            throw ValidationException::withMessages(['decision' => 'Leave this entry unchanged unless the owner confirms the refund was cancelled or removed.']);
        }
        $fingerprint = $this->orphanJournalFingerprint($journal, $classification);
        return $this->preview(['check_key' => 'accounting_health_check_orphan_journals', 'detector_version' => '1.1.0',
            'detector_fingerprint' => hash('sha256', 'accounting_health_check_orphan_journals:1.1.0'),
            'status' => 'critical', 'repair_available' => true, 'warehouse_id' => $journal->warehouse_id],
            $path === 'deterministic' ? 'reverse_deterministic_orphan_journal' : 'reverse_owner_confirmed_orphan_journal',
            [(int) $journal->id], ['journal:'.$journal->id => $fingerprint], [[
                'operation' => 'create_linked_reversal', 'journal_id' => (int) $journal->id,
                'owner_confirmed' => $ownerConfirmed, 'decision' => $ownerConfirmed ? 'refund_cancelled_or_removed' : null,
            ]], ['linked_reversal_journals_created' => 1, 'original_journals_modified' => 0], $idempotencyKey, true);
    }

    public function previewMissingAccountingPost(AccountingSyncQueue $queue, string $idempotencyKey,
        bool $backupConfirmed = false): AccountingRepairPlan
    {
        $queue = $queue->fresh();
        if (!$queue) throw ValidationException::withMessages(['source' => 'The accounting queue record is missing.']);
        $modelClass = (string) $queue->source_type;
        if (!isset(self::RETRYABLE_MODELS[$modelClass]) || !class_exists($modelClass)) {
            throw ValidationException::withMessages(['source' => 'This accounting source type has no certified retry handler.']);
        }
        $source = $modelClass::find($queue->source_id);
        if (!$source) throw ValidationException::withMessages(['source' => 'The authoritative source record is missing.']);
        $warehouseId = $this->sourceWarehouseId($source);
        app(WarehouseAccessService::class)->authorizeWarehouse($warehouseId);
        $classification = app(AccountingJournalRetryService::class)->classify($modelClass, (int) $source->getKey());
        if (($classification['status'] ?? null) !== 'missing') {
            throw ValidationException::withMessages(['source' => 'Retry is safe only when no journal history exists.']);
        }
        $fingerprint = $this->retryTargetFingerprint($queue, $source, $classification);
        return $this->preview([
            'check_key' => 'f012.payment_to_journal_disagreement', 'detector_version' => '3.0.0',
            'detector_fingerprint' => hash('sha256', 'f012.payment_to_journal_disagreement:3.0.0'),
            'status' => 'critical', 'repair_available' => true, 'warehouse_id' => $warehouseId,
        ], 'retry_missing_accounting_post', [(int) $queue->id], ['queue:'.$queue->id => $fingerprint], [[
            'operation' => 'post_missing_journal', 'queue_id' => (int) $queue->id,
            'source_type' => $modelClass, 'source_id' => (int) $source->getKey(),
        ]], ['new_balanced_journal_family' => 1, 'historical_journals_modified' => 0], $idempotencyKey, $backupConfirmed);
    }

    public function previewPaymentAccountMapping(Account $account, string $idempotencyKey,
        bool $backupConfirmed = false, ?int $existingLedgerId = null, ?string $ownerClassification = null): AccountingRepairPlan
    {
        $repair = app(PaymentAccountMappingRepairService::class);
        if ($ownerClassification) {
            $candidate = $repair->inspect(null, true);
            $item = collect($candidate['ambiguous'])->firstWhere('account_id', (int) $account->getKey());
            if (!$item || empty($item['owner_question'])) throw ValidationException::withMessages(['classification' => 'This account does not require owner classification.']);
            return $this->preview([
                'check_key' => 'accounting_health_check_payment_accounts', 'detector_version' => '1.1.0',
                'detector_fingerprint' => hash('sha256', 'accounting_health_check_payment_accounts:1.1.0'),
                'status' => 'critical', 'repair_available' => true, 'warehouse_id' => null,
            ], 'classify_owner_payment_account', [(int) $account->id],
                ['payment-account:'.$account->id => hash('sha256', json_encode($item))], [[
                    'operation' => 'classify_owner_payment_account', 'payment_account_id' => (int) $account->id,
                    'payment_account_name' => (string) $account->name, 'classification' => $ownerClassification,
                ]], ['historical_transactions_modified' => 0, 'payment_account_mappings_created' => 1],
                $idempotencyKey, $backupConfirmed);
        }
        $candidate = $existingLedgerId
            ? $repair->existingLedgerCandidate((int) $account->getKey(), $existingLedgerId)
            : $repair->deterministicCandidate((int) $account->getKey());
        $expected = $candidate['expected'];
        $repairKey = $existingLedgerId ? 'map_existing_payment_account' : 'create_missing_payment_account_mapping';
        $operation = $repairKey;
        $ledger = $candidate['existing_ledger'] ?? null;

        return $this->preview([
            'check_key' => 'accounting_health_check_payment_accounts',
            'detector_version' => '1.0.0',
            'detector_fingerprint' => hash('sha256', 'accounting_health_check_payment_accounts:1.0.0'),
            'status' => 'critical',
            'repair_available' => true,
            'warehouse_id' => null,
        ], $repairKey, [(int) $account->getKey()], [
            'payment-account:'.$account->getKey() => $candidate['fingerprint'],
        ], [[
            'operation' => $operation,
            'payment_account_id' => (int) $account->getKey(),
            'payment_account_name' => (string) $candidate['account_name'],
            'ledger_account_id' => $ledger ? (int) $ledger['id'] : null,
            'ledger_account_code' => (string) ($ledger['code'] ?? $expected['code']),
            'ledger_account_name' => (string) ($ledger['name'] ?? $expected['name']),
            'parent_account_id' => (int) ($ledger['parent_id'] ?? $expected['parent_id']),
            'parent_account_code' => (string) ($ledger ? '' : $expected['parent_code']),
            'parent_account_name' => (string) ($ledger ? '' : $expected['parent_name']),
            'scope' => 'global',
            'currency_scope' => 'base_currency',
            'safety_reason' => 'The active operational payment account is unmapped, its Cash & Bank parent is unambiguous, and the proposed code is unique.',
        ]], [
            'ledger_accounts_created' => $ledger ? 0 : 1,
            'payment_account_mappings_created' => 1,
            'historical_journals_modified' => 0,
            'payments_modified' => 0,
            'balances_modified' => 0,
            'stock_modified' => 0,
        ], $idempotencyKey, $backupConfirmed);
    }

    public function preview(array $finding, string $repairKey, array $targets, array $fingerprints, array $mutations,
        array $expectedEffect, string $idempotencyKey, bool $backupConfirmed = false): AccountingRepairPlan
    {
        $this->authorize(self::PREVIEW_PERMISSION);
        abort_unless(config('accounting.guided_repair_enabled', false), 404);
        $definition = self::CATALOG[$repairKey] ?? null;
        if (!$definition || !($finding['repair_available'] ?? false)) {
            throw ValidationException::withMessages(['repair' => 'This finding has no evidence-safe guided repair.']);
        }
        if (in_array($finding['status'] ?? null, ['inconclusive', 'scan_failed', 'not_applicable'], true)) {
            throw ValidationException::withMessages(['repair' => 'Inconclusive, failed, and not-applicable findings cannot be repaired.']);
        }
        $warehouseId = isset($finding['warehouse_id']) ? (int) $finding['warehouse_id'] : null;
        app(WarehouseAccessService::class)->authorizeWarehouse($warehouseId);

        return DB::transaction(function () use ($finding, $repairKey, $targets, $fingerprints, $mutations, $expectedEffect,
            $idempotencyKey, $backupConfirmed, $definition, $warehouseId) {
            $existing = AccountingRepairPlan::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            $immutable = $this->immutablePayloadHash(
                (string) $finding['check_key'],
                (string) ($finding['detector_version'] ?? 'unknown'),
                (string) ($finding['detector_fingerprint'] ?? ''),
                $repairKey,
                $targets,
                $fingerprints,
                $mutations,
                $expectedEffect,
                $warehouseId
            );
            if ($existing) {
                if (!hash_equals((string) ($existing->preconditions['immutable_payload_hash'] ?? ''), $immutable)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'The idempotency key was already used for a different preview.']);
                }
                return $existing;
            }
            $plan = AccountingRepairPlan::create([
                'plan_key' => (string) Str::uuid(), 'idempotency_key' => $idempotencyKey,
                'check_key' => (string) $finding['check_key'], 'detector_version' => (string) ($finding['detector_version'] ?? 'unknown'),
                'detector_fingerprint' => (string) ($finding['detector_fingerprint'] ?? ''), 'repair_key' => $repairKey,
                'scope' => $warehouseId ? 'warehouse' : 'global', 'warehouse_id' => $warehouseId, 'status' => 'previewed',
                'target_ids' => array_values($targets), 'expected_state_fingerprints' => $fingerprints,
                'proposed_mutations' => $mutations, 'expected_effect' => $expectedEffect,
                'preconditions' => ['immutable_payload_hash' => $immutable, 'source_evidence_required' => true,
                    'currency_basis_must_be_unambiguous' => true], 'required_permission' => $definition['permission'],
                'backup_confirmation_required' => $definition['backup'], 'backup_confirmed' => $backupConfirmed,
                'closed_period_policy' => $definition['closed_period'], 'previewed_by' => auth()->id(),
                'previewed_at' => now(), 'expires_at' => now()->addMinutes(30),
            ]);
            $this->audit($plan, 'previewed', ['immutable_payload_hash' => $immutable]);
            return $plan;
        });
    }

    public function approve(AccountingRepairPlan $plan, bool $backupConfirmed): AccountingRepairPlan
    {
        abort_unless(config('accounting.guided_repair_enabled', false), 404);
        $this->authorize(self::APPROVE_PERMISSION);
        app(WarehouseAccessService::class)->authorizeWarehouse($plan->warehouse_id ? (int) $plan->warehouse_id : null);
        return DB::transaction(function () use ($plan, $backupConfirmed) {
            $locked = AccountingRepairPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->assertImmutablePlan($locked);
            $this->assertAuditChain($locked);
            if ($locked->status === 'approved') return $locked;
            if ($locked->status !== 'previewed' || $locked->expires_at->isPast()) {
                throw ValidationException::withMessages(['plan' => 'The repair preview is not current and approvable.']);
            }
            if ((int) $locked->previewed_by === (int) auth()->id()) {
                throw ValidationException::withMessages(['plan' => 'Approval must be performed by a different authorized identity.']);
            }
            if ($locked->backup_confirmation_required && !$backupConfirmed) {
                throw ValidationException::withMessages(['backup' => 'Verified backup confirmation is required.']);
            }
            $locked->update(['status' => 'approved', 'backup_confirmed' => $backupConfirmed,
                'approved_by' => auth()->id(), 'approved_at' => now()]);
            $this->audit($locked, 'approved', ['backup_confirmed' => $backupConfirmed]);
            return $locked->fresh();
        });
    }

    public function authorizeAudit(AccountingRepairPlan $plan): void
    {
        abort_unless(config('accounting.guided_repair_enabled', false), 404);
        $this->authorize(self::AUDIT_PERMISSION);
        app(WarehouseAccessService::class)->authorizeWarehouse($plan->warehouse_id ? (int) $plan->warehouse_id : null);
        $this->assertAuditChain($plan);
    }

    public function execute(AccountingRepairPlan $plan): AccountingRepairPlan
    {
        $this->authorize(self::EXECUTE_PERMISSION);
        abort_unless(config('accounting.guided_repair_enabled', false), 404);
        app(WarehouseAccessService::class)->authorizeWarehouse($plan->warehouse_id ? (int) $plan->warehouse_id : null);

        try {
            return DB::transaction(function () use ($plan) {
                $locked = AccountingRepairPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                $this->assertImmutablePlan($locked);
                $this->assertAuditChain($locked);
                if ($locked->status === 'completed' && $locked->verification_status === 'passed') return $locked;
                if ($locked->status !== 'approved' || !$locked->approved_at || $locked->expires_at->isPast()) {
                    throw ValidationException::withMessages(['plan' => 'The plan is not approved or has expired.']);
                }
                if ($locked->backup_confirmation_required && !$locked->backup_confirmed) {
                    throw ValidationException::withMessages(['backup' => 'Verified backup confirmation is missing.']);
                }
                if (in_array($locked->repair_key, ['create_missing_payment_account_mapping', 'map_existing_payment_account'], true)) {
                    $mutation = $locked->proposed_mutations[0] ?? [];
                    $accountId = (int) ($mutation['payment_account_id'] ?? 0);
                    $expectedFingerprint = (string) ($locked->expected_state_fingerprints['payment-account:'.$accountId] ?? '');
                    if ($accountId < 1 || $expectedFingerprint === ''
                        || ($mutation['operation'] ?? null) !== $locked->repair_key) {
                        throw ValidationException::withMessages(['repair' => 'The approved mapping repair payload is incomplete.']);
                    }

                    $repair = app(PaymentAccountMappingRepairService::class);
                    $result = $locked->repair_key === 'map_existing_payment_account'
                        ? $repair->applyExistingCandidate($accountId, (int) ($mutation['ledger_account_id'] ?? 0), $expectedFingerprint)
                        : $repair->applyDeterministicCandidate($accountId, (string) ($mutation['ledger_account_code'] ?? ''),
                            (int) ($mutation['parent_account_id'] ?? 0), $expectedFingerprint);
                    $locked->update([
                        'status' => 'completed',
                        'executed_by' => auth()->id(),
                        'executed_at' => now(),
                        'result' => $result,
                        'corrective_journal_ids' => [],
                        'before_fingerprints' => $locked->expected_state_fingerprints,
                        'after_fingerprints' => ['payment-account:'.$accountId => hash('sha256', json_encode($result))],
                        'verification_status' => 'passed',
                        'verification_evidence' => [
                            'mapping_verified' => true,
                            'historical_journals_modified' => 0,
                            'verified_at' => now()->toIso8601String(),
                        ],
                    ]);
                    $this->audit($locked, 'executed_and_verified', [
                        'payment_account_id' => $accountId,
                        'ledger_account_id' => $result['ledger_account_id'],
                        'verification' => 'passed',
                    ]);

                    return $locked->fresh();
                }
                if ($locked->repair_key === 'classify_owner_payment_account') {
                    $mutation = $locked->proposed_mutations[0] ?? [];
                    $result = app(PaymentAccountMappingRepairService::class)->applyOwnerClassification(
                        (int) ($mutation['payment_account_id'] ?? 0), (string) ($mutation['classification'] ?? ''));
                    $locked->update(['status' => 'completed', 'executed_by' => auth()->id(), 'executed_at' => now(),
                        'result' => $result, 'corrective_journal_ids' => [], 'verification_status' => 'passed',
                        'verification_evidence' => ['mapping_verified' => true, 'historical_transactions_modified' => 0]]);
                    $this->audit($locked, 'owner_mapping_executed_verified', $result);
                    return $locked->fresh();
                }
                if (in_array($locked->repair_key, ['reverse_deterministic_orphan_journal', 'reverse_owner_confirmed_orphan_journal'], true)) {
                    $mutation = $locked->proposed_mutations[0] ?? [];
                    $journal = \App\Models\JournalEntry::whereKey((int) ($mutation['journal_id'] ?? 0))->lockForUpdate()->with('lines')->firstOrFail();
                    $existing = \App\Models\JournalEntry::where('related_journal_entry_id', $journal->id)->lockForUpdate()->first();
                    if ($existing) $reversal = $existing;
                    else {
                        $classification = app(JournalSourceIntegrityService::class)->classify($journal);
                        $expectedFingerprint = (string) ($locked->expected_state_fingerprints['journal:'.$journal->id] ?? '');
                        if ($expectedFingerprint === '' || !hash_equals($expectedFingerprint,
                            $this->orphanJournalFingerprint($journal, $classification))) {
                            throw ValidationException::withMessages(['fingerprint' => 'The journal or lifecycle evidence changed; create a new preview.']);
                        }
                        $allowed = $locked->repair_key === 'reverse_deterministic_orphan_journal'
                            ? ($classification['repair_path'] ?? null) === 'deterministic'
                            : (($classification['repair_path'] ?? null) === 'owner_confirmed' && ($mutation['owner_confirmed'] ?? false));
                        if (!$allowed) throw ValidationException::withMessages(['repair' => 'Lifecycle evidence changed; create a new preview.']);
                        if (AccountingPeriod::where('is_closed', true)->where('start_date', '<=', $journal->entry_date)
                            ->where('end_date', '>=', $journal->entry_date)->exists()) {
                            throw ValidationException::withMessages(['period' => 'The journal belongs to a closed accounting period.']);
                        }
                        $reversal = JournalBuilder::reverse($journal, '_guided_reversal', 'F-012 guided reversal of retained orphan journal');
                    }
                    $locked->update(['status' => 'completed', 'executed_by' => auth()->id(), 'executed_at' => now(),
                        'result' => ['original_journal_id' => $journal->id, 'reversal_journal_id' => $reversal->id],
                        'corrective_journal_ids' => [$reversal->id], 'verification_status' => 'passed',
                        'verification_evidence' => ['linked_reversal' => true, 'original_journal_retained' => true,
                            'owner_decision' => $mutation['decision'] ?? null]]);
                    $this->audit($locked, 'orphan_reversal_executed_and_verified', $locked->verification_evidence);
                    return $locked->fresh();
                }
                if ($locked->repair_key !== 'retry_missing_accounting_post') {
                    throw ValidationException::withMessages(['repair' => 'This repair does not have a certified executor.']);
                }
                $mutation = $locked->proposed_mutations[0] ?? [];
                $queue = AccountingSyncQueue::whereKey((int) ($mutation['queue_id'] ?? 0))->lockForUpdate()->firstOrFail();
                $modelClass = (string) $queue->source_type;
                if (!isset(self::RETRYABLE_MODELS[$modelClass]) || $modelClass !== ($mutation['source_type'] ?? null)
                    || (int) $queue->source_id !== (int) ($mutation['source_id'] ?? 0)) {
                    throw ValidationException::withMessages(['source' => 'The approved source identity changed.']);
                }
                $source = $modelClass::whereKey($queue->source_id)->lockForUpdate()->first();
                if (!$source) throw ValidationException::withMessages(['source' => 'The authoritative source disappeared.']);
                $warehouseId = $this->sourceWarehouseId($source);
                if (($locked->scope === 'warehouse' ? (int) $locked->warehouse_id : null) !== $warehouseId) {
                    throw ValidationException::withMessages(['warehouse' => 'Warehouse scope changed after preview.']);
                }
                app(WarehouseAccessService::class)->authorizeWarehouse($warehouseId);
                $this->assertOpenPeriod($source);
                $classification = app(AccountingJournalRetryService::class)->classify($modelClass, (int) $source->getKey());
                $actual = $this->retryTargetFingerprint($queue, $source, $classification);
                $expected = (string) ($locked->expected_state_fingerprints['queue:'.$queue->id] ?? '');
                if (!hash_equals($expected, $actual) || ($classification['status'] ?? null) !== 'missing') {
                    throw ValidationException::withMessages(['fingerprint' => 'Target state changed after preview; create a new plan.']);
                }

                $result = app(AccountingService::class)->{self::RETRYABLE_MODELS[$modelClass]}($source);
                if (!$result || !$result->success) {
                    throw ValidationException::withMessages(['repair' => (string) ($result->error ?? 'Accounting post failed.')]);
                }
                $verified = app(AccountingJournalRetryService::class)->classify($modelClass, (int) $source->getKey());
                if (($verified['status'] ?? null) !== 'already_posted_valid') {
                    throw ValidationException::withMessages(['verification' => 'Verification did not find exactly one valid active journal.']);
                }
                $queue->forceFill(['status' => 'posted', 'attempts' => (int) $queue->attempts + 1, 'last_error' => null,
                    'last_attempt_at' => now(), 'last_success_at' => now(), 'resolved_at' => now(), 'posted_at' => $queue->posted_at ?: now()])->save();
                $source->forceFill(['accounting_status' => 'posted'])->saveQuietly();
                $after = $this->retryTargetFingerprint($queue->fresh(), $source->fresh(), $verified);
                $locked->update(['status' => 'completed', 'executed_by' => auth()->id(), 'executed_at' => now(),
                    'result' => ['status' => 'posted', 'journal_ids' => $verified['active_journals']],
                    'corrective_journal_ids' => $verified['active_journals'], 'before_fingerprints' => $locked->expected_state_fingerprints,
                    'after_fingerprints' => ['queue:'.$queue->id => $after], 'verification_status' => 'passed',
                    'verification_evidence' => ['classification' => $verified, 'verified_at' => now()->toIso8601String()]]);
                $this->audit($locked, 'executed_and_verified', ['journal_ids' => $verified['active_journals'], 'verification' => 'passed']);
                return $locked->fresh();
            }, 1);
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($plan, $exception) {
                $locked = AccountingRepairPlan::whereKey($plan->id)->lockForUpdate()->first();
                if ($locked && $locked->status === 'approved') {
                    $locked->update(['status' => 'failed', 'failure_evidence' => mb_substr($exception->getMessage(), 0, 2000),
                        'verification_status' => 'failed']);
                    $this->audit($locked, 'failed', ['error' => mb_substr($exception->getMessage(), 0, 500)]);
                }
            });
            throw $exception;
        }
    }

    private function assertOpenPeriod($source): void
    {
        $date = $source->created_at?->toDateString();
        if ($date && AccountingPeriod::where('is_closed', true)->where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists()) {
            throw ValidationException::withMessages(['period' => 'The source belongs to a closed accounting period.']);
        }
    }

    private function sourceWarehouseId($source): ?int
    {
        if (isset($source->warehouse_id) && (int) $source->warehouse_id > 0) return (int) $source->warehouse_id;
        if ($source instanceof Payment) {
            return (int) ($source->sale?->warehouse_id ?? $source->purchase?->warehouse_id ?? 0) ?: null;
        }
        return null;
    }

    private function retryTargetFingerprint(AccountingSyncQueue $queue, $source, array $classification): string
    {
        return $this->canonicalHash(['queue' => $queue->getAttributes(), 'source' => $source->getAttributes(),
            'classification' => $classification]);
    }

    private function orphanJournalFingerprint(\App\Models\JournalEntry $journal, array $classification): string
    {
        $journal->loadMissing('lines');
        return $this->canonicalHash(['journal' => $journal->getAttributes(),
            'lines' => $journal->lines->map->getAttributes()->all(), 'classification' => $classification]);
    }

    private function assertImmutablePlan(AccountingRepairPlan $plan): void
    {
        $expected = (string) ($plan->preconditions['immutable_payload_hash'] ?? '');
        $actual = $this->immutablePayloadHash(
            (string) $plan->check_key,
            (string) $plan->detector_version,
            (string) $plan->detector_fingerprint,
            (string) $plan->repair_key,
            $plan->target_ids ?: [],
            $plan->expected_state_fingerprints ?: [],
            $plan->proposed_mutations ?: [],
            $plan->expected_effect ?: [],
            $plan->warehouse_id === null ? null : (int) $plan->warehouse_id
        );
        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw ValidationException::withMessages([
                'plan' => 'The immutable repair preview payload no longer matches its approved fingerprint.',
            ]);
        }
    }

    private function immutablePayloadHash(string $checkKey, string $detectorVersion, string $detectorFingerprint,
        string $repairKey, array $targets, array $fingerprints, array $mutations, array $expectedEffect,
        ?int $warehouseId): string
    {
        return $this->canonicalHash([
            'check_key' => $checkKey,
            'detector_version' => $detectorVersion,
            'detector_fingerprint' => $detectorFingerprint,
            'repair_key' => $repairKey,
            'targets' => array_values($targets),
            'fingerprints' => $fingerprints,
            'mutations' => $mutations,
            'expected_effect' => $expectedEffect,
            'warehouse_id' => $warehouseId,
        ]);
    }

    private function audit(AccountingRepairPlan $plan, string $type, array $evidence): void
    {
        $previous = AccountingRepairAuditEvent::where('repair_plan_id', $plan->id)->latest('id')->first();
        $occurredAt = now();
        $eventKey = (string) Str::uuid();
        $payload = ['event_key' => $eventKey, 'repair_plan_id' => $plan->id, 'event_type' => $type, 'actor_id' => auth()->id(),
            'evidence' => $evidence, 'occurred_at' => $occurredAt->toIso8601String(), 'previous_event_hash' => $previous?->event_hash];
        AccountingRepairAuditEvent::create($payload + ['event_hash' => $this->canonicalHash($payload)]);
    }

    private function assertAuditChain(AccountingRepairPlan $plan): void
    {
        $previousHash = null;
        $events = AccountingRepairAuditEvent::where('repair_plan_id', $plan->id)->orderBy('id')->get();
        if ($events->isEmpty()) {
            throw ValidationException::withMessages(['audit' => 'The immutable repair audit chain is missing.']);
        }
        foreach ($events as $event) {
            if (($event->previous_event_hash ?: null) !== $previousHash) {
                throw ValidationException::withMessages(['audit' => 'The immutable repair audit chain linkage is invalid.']);
            }
            $payload = [
                'event_key' => (string) $event->event_key,
                'repair_plan_id' => (int) $event->repair_plan_id,
                'event_type' => (string) $event->event_type,
                'actor_id' => $event->actor_id === null ? null : (int) $event->actor_id,
                'evidence' => $event->evidence ?: [],
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'previous_event_hash' => $event->previous_event_hash ?: null,
            ];
            $actual = $this->canonicalHash($payload);
            if (!hash_equals((string) $event->event_hash, $actual)) {
                throw ValidationException::withMessages(['audit' => 'The immutable repair audit event fingerprint is invalid.']);
            }
            $previousHash = (string) $event->event_hash;
        }
    }

    private function authorize(string $permission): void
    {
        $access = app(WarehouseAccessService::class);
        $user = $access->user();
        abort_if(!$user || $access->isPortalIdentity() || $access->classification() === WarehouseAccessService::INVALID_OPERATIONAL, 403);
        abort_unless((int) $user->role_id <= 2 || $user->hasPermissionTo($permission), 403);
    }

    private function canonicalHash(array $payload): string
    {
        $sort = function (&$value) use (&$sort): void {
            if (!is_array($value)) return;
            foreach ($value as &$child) $sort($child);
            if (!array_is_list($value)) ksort($value);
        };
        $sort($payload);
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
