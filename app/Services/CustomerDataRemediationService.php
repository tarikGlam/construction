<?php

namespace App\Services;

use App\Models\AccountingSyncQueue;
use App\Models\Expense;
use App\Models\ProductReturn;
use App\Models\Returns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CustomerDataRemediationService
{
    public function __construct(
        private StandardSemanticAccountService $semantic,
        private PaymentAccountMappingRepairService $paymentMappings,
        private AccountingJournalRetryService $journalRetry,
        private AccountingService $accounting,
        private AccountingActivationService $activation,
        private InventoryValuationService $inventoryValuation
    ) {}

    public function inspect(): array
    {
        return [
            'database' => DB::connection()->getDatabaseName(),
            'semantic_accounts' => $this->semantic->inspect(),
            'payment_accounts' => $this->paymentMappings->inspect(),
            'sale_returns' => $this->inspectSaleReturnAnomalies(),
            'failed_accounting_queue' => $this->failedQueue(),
            'opening_balances' => $this->inspectOpeningBalances(),
            'customer_deposits' => $this->inspectCustomerDeposits(),
            'inventory' => $this->inspectInventory(),
            'integrity' => $this->integrity(),
        ];
    }

    public function apply(array $approvedReturnIds = [], array $approvedQueueIds = []): array
    {
        $before = $this->inspect();
        if ($before['semantic_accounts']['conflicts'] || $before['semantic_accounts']['schema_issues']) {
            throw new RuntimeException('Semantic account conflicts require review; no remediation was applied.');
        }

        $changes = DB::transaction(function () use ($before, $approvedReturnIds, $approvedQueueIds) {
            $semantic = $this->semantic->apply();
            $payments = $this->paymentMappings->apply();
            if (!empty($payments['errors'])) {
                throw new RuntimeException('A payment-account mapping failed; the remediation transaction was rolled back.');
            }

            $approvedReturnIds = array_map('intval', $approvedReturnIds);
            $approvedQueueIds = array_map('intval', $approvedQueueIds);
            $returnCandidates = array_values(array_filter(
                $before['sale_returns'],
                fn (array $candidate) => in_array((int) $candidate['return_id'], $approvedReturnIds, true)
            ));
            $returns = $this->applySaleReturnAnomalies($returnCandidates);

            $queue = [];
            foreach ($before['failed_accounting_queue'] as $candidate) {
                if (!in_array((int) $candidate['queue_id'], $approvedQueueIds, true)) {
                    continue;
                }
                $record = AccountingSyncQueue::lockForUpdate()->find($candidate['queue_id']);
                if (!$record) {
                    continue;
                }
                $outcome = $this->repairQueueRecord($record, $candidate);
                if ($outcome) {
                    $queue[] = $outcome;
                }
            }

            return compact('semantic', 'payments', 'returns', 'queue', 'approvedReturnIds', 'approvedQueueIds');
        });

        return ['before' => $before, 'changes' => $changes, 'after' => $this->inspect()];
    }

    public function inspectSaleReturnAnomalies(): array
    {
        return ProductReturn::query()->where('qty', '<=', 0)->orderBy('id')->get()
            ->map(fn (ProductReturn $row) => $this->saleReturnCandidate($row))
            ->all();
    }

    private function inspectOpeningBalances(): array
    {
        $session = DB::table('accounting_activation_sessions')->latest('id')->first();
        $summary = $session ? (json_decode((string) $session->summary_json, true) ?: []) : [];
        $required = [
            'accounts_receivable', 'accounts_payable', 'cash_and_bank', 'inventory_value',
            'customer_deposits', 'gift_card_liability', 'rewards_liability',
        ];

        return [
            'activation_session_id' => $session?->id,
            'activation_date' => $session?->start_date,
            'opening_journal_entry_id' => $session?->opening_journal_entry_id,
            'recorded_summary' => $summary,
            'missing_control_balance_fields' => array_values(array_diff($required, array_keys($summary))),
            'current_projection' => $this->activation->calculateOpeningBalances(),
            'automatic_adjustment_permitted' => false,
            'disposition' => 'Historical omissions require reconstruction as of activation and explicit accountant approval; opening journals are never rewritten.',
        ];
    }

    private function inspectCustomerDeposits(): array
    {
        $customers = DB::table('customers')
            ->whereRaw('COALESCE(deposit, 0) <> 0 OR COALESCE(expense, 0) <> 0')
            ->select('id', 'name', 'deposit', 'expense')
            ->selectRaw('(COALESCE(deposit, 0) - COALESCE(expense, 0)) as balance')
            ->orderBy('id')->get();

        $depositAccount = null;
        try {
            $depositAccount = $this->accounting->getRoleAccountId(AccountingService::ROLE_CUSTOMER_DEPOSIT);
        } catch (\Throwable) {
        }

        return [
            'customers' => $customers->map(fn ($row) => (array) $row)->all(),
            'deposit_history' => DB::table('deposits')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'deposit_payments' => DB::table('payments as p')
                ->join('sales as s', 's.id', '=', 'p.sale_id')
                ->where('p.paying_method', 'Deposit')
                ->select('p.id', 'p.sale_id', 's.customer_id', 'p.amount', 'p.payment_at', 'p.accounting_status')
                ->orderBy('p.id')->get()->map(fn ($row) => (array) $row)->all(),
            'operational_net' => round((float) $customers->sum('balance'), 4),
            'gl_account_id' => $depositAccount,
            'gl_balance' => $depositAccount ? round((float) DB::table('journal_lines')
                ->where('accounting_account_id', $depositAccount)
                ->selectRaw('COALESCE(SUM(credit - debit), 0) as balance')->value('balance'), 4) : null,
            'automatic_adjustment_permitted' => false,
        ];
    }

    private function inspectInventory(): array
    {
        $valuation = $this->inventoryValuation->currentValuation();
        $inventoryAccount = null;
        try {
            $inventoryAccount = $this->accounting->getRoleAccountId(AccountingService::ROLE_INVENTORY);
        } catch (\Throwable) {
        }
        $gl = $inventoryAccount ? (float) DB::table('journal_lines')
            ->where('accounting_account_id', $inventoryAccount)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as balance')->value('balance') : null;

        return [
            'operational_value' => $valuation['value'],
            'gl_account_id' => $inventoryAccount,
            'gl_value' => $gl === null ? null : round($gl, 4),
            'variance' => $gl === null ? null : round($valuation['value'] - $gl, 4),
            'cost_anomalies' => $valuation['cost_anomalies']->map(fn ($row) => (array) $row)->all(),
            'missing_cost' => $valuation['missing_cost']->map(fn ($row) => (array) $row)->all(),
            'negative_stock' => $valuation['negative_stock']->map(fn ($row) => (array) $row)->all(),
            'quantity_mismatches' => $valuation['quantity_mismatches']->map(fn ($row) => (array) $row)->all(),
            'periodic_close_count' => Schema::hasTable('periodic_inventory_closes')
                ? DB::table('periodic_inventory_closes')->whereIn('status', ['posted', 'zero'])->count() : 0,
            'automatic_adjustment_permitted' => false,
        ];
    }

    public function applySaleReturnAnomalies(array $candidates): array
    {
        $changes = [];
        foreach ($candidates as $candidate) {
            if (!$candidate['safe_to_remove']) {
                continue;
            }
            $row = ProductReturn::lockForUpdate()->find($candidate['product_return_id']);
            $parent = Returns::lockForUpdate()->find($candidate['return_id']);
            if (!$row || !$parent) {
                throw new RuntimeException("Sale return candidate {$candidate['product_return_id']} changed after inspection.");
            }
            $current = $this->saleReturnCandidate($row, $parent, true);
            $comparisonKeys = [
                'return_id', 'product_return_id', 'product_id', 'qty', 'discount', 'tax', 'total',
                'parent_item', 'parent_total_qty', 'parent_total_price', 'parent_grand_total',
                'positive_line_count', 'positive_qty', 'positive_total', 'refund_count',
                'gst_line_snapshot_count', 'safe_to_remove',
            ];
            if (!$current['safe_to_remove']
                || array_intersect_key($current, array_flip($comparisonKeys))
                    !== array_intersect_key($candidate, array_flip($comparisonKeys))) {
                throw new RuntimeException("Sale return candidate {$candidate['product_return_id']} failed apply-time revalidation.");
            }
            $beforeRow = $row->toArray();
            $beforeItem = (int) $parent->item;
            $row->delete();
            $remaining = ProductReturn::where('return_id', $parent->id)->count();
            $parent->item = $remaining;
            $parent->save();
            $changes[] = [
                'product_return_id' => $beforeRow['id'],
                'return_id' => $parent->id,
                'before' => ['product_return' => $beforeRow, 'parent_item' => $beforeItem],
                'after' => ['product_return' => null, 'parent_item' => $remaining],
            ];
        }

        return $changes;
    }

    private function saleReturnCandidate(ProductReturn $row, ?Returns $parent = null, bool $lock = false): array
    {
        $parent ??= Returns::find($row->return_id);
        $positiveQuery = ProductReturn::where('return_id', $row->return_id)->where('qty', '>', 0);
        if ($lock) {
            $positiveQuery->lockForUpdate();
        }
        $positive = $positiveQuery->get();

        $gstLineCount = Schema::hasTable('india_gst_return_line_snapshots')
            ? DB::table('india_gst_return_line_snapshots')->where('product_return_id', $row->id)->count()
            : 0;
        $refundCount = Schema::hasTable('payments')
            ? DB::table('payments')->where('return_id', $row->return_id)->count()
            : 0;
        $zeroEffect = (float) $row->qty === 0.0
            && (float) $row->discount === 0.0
            && (float) $row->tax === 0.0
            && (float) $row->total === 0.0
            && !$row->product_batch_id && !$row->variant_id && trim((string) $row->imei_number) === '';
        $parentMatches = $parent
            && round((float) $parent->total_qty, 6) === round((float) $positive->sum('qty'), 6)
            && round((float) $parent->total_price, 4) === round((float) $positive->sum('total'), 4)
            && round((float) $parent->grand_total, 4) === round((float) $positive->sum('total') + (float) $parent->order_tax - (float) $parent->total_discount, 4);
        $safe = $zeroEffect && $parentMatches && $gstLineCount === 0;

        return [
            'return_id' => $row->return_id,
            'product_return_id' => $row->id,
            'product_id' => $row->product_id,
            'qty' => (float) $row->qty,
            'discount' => (float) $row->discount,
            'tax' => (float) $row->tax,
            'total' => (float) $row->total,
            'parent_item' => $parent?->item,
            'parent_total_qty' => (float) ($parent?->total_qty ?? 0),
            'parent_total_price' => (float) ($parent?->total_price ?? 0),
            'parent_grand_total' => (float) ($parent?->grand_total ?? 0),
            'positive_line_count' => $positive->count(),
            'positive_qty' => (float) $positive->sum('qty'),
            'positive_total' => (float) $positive->sum('total'),
            'refund_count' => $refundCount,
            'gst_line_snapshot_count' => $gstLineCount,
            'safe_to_remove' => $safe,
            'proposed_action' => $safe
                ? 'Delete only the zero-value product_return row and set the parent item count to the positive line count; totals, stock, refunds, and journals remain unchanged.'
                : 'Human review required; no automatic deletion.',
        ];
    }

    private function failedQueue(): array
    {
        return AccountingSyncQueue::where('status', 'failed')->orderBy('id')->get()->map(function (AccountingSyncQueue $record) {
            $classification = $this->journalRetry->classify($record->source_type, (int) $record->source_id);
            $supportedRepost = $classification['status'] === 'reversed' && $record->source_type === Expense::class;
            return [
                'queue_id' => $record->id,
                'source_type' => $record->source_type,
                'source_id' => $record->source_id,
                'queue_status' => $record->status,
                'attempts' => $record->attempts,
                'last_error' => $record->last_error,
                'journal_classification' => $classification,
                'safe_action' => match ($classification['status']) {
                    'already_posted_valid' => 'Mark the queue and source posted without creating a journal.',
                    'reversed' => $supportedRepost ? 'Create one uniquely keyed updated expense journal, then mark posted after verification.' : 'Human review required before reposting a reversed source.',
                    'missing' => 'Retry normal source posting and verify the resulting journal.',
                    default => 'Keep failed and visible; do not delete or replace journals.',
                },
                'automatically_repairable' => $classification['status'] === 'already_posted_valid' || $supportedRepost,
            ];
        })->all();
    }

    private function repairQueueRecord(AccountingSyncQueue $record, array $candidate): ?array
    {
        $before = $record->toArray();
        $status = $candidate['journal_classification']['status'];
        if ($status === 'already_posted_valid') {
            $journal = null;
        } elseif ($status === 'reversed' && $record->source_type === Expense::class) {
            $source = Expense::find($record->source_id);
            if (!$source) {
                return null;
            }
            $result = $this->accounting->recordExpense($source);
            if (!$result->success) {
                throw new RuntimeException($result->error);
            }
            $journal = $result->journalEntry;
        } else {
            return null;
        }

        $verified = $this->journalRetry->classify($record->source_type, (int) $record->source_id);
        if ($verified['status'] !== 'already_posted_valid') {
            throw new RuntimeException("Queue {$record->id} did not resolve to one valid active journal.");
        }
        $now = now();
        $record->forceFill([
            'status' => 'posted', 'last_error' => null, 'last_attempt_at' => $now,
            'last_success_at' => $now, 'resolved_at' => $now, 'posted_at' => $record->posted_at ?: $now,
            'attempts' => (int) $record->attempts + 1,
        ])->save();
        $source = $record->source_type::find($record->source_id);
        $source?->forceFill(['accounting_status' => 'posted'])->saveQuietly();

        return [
            'queue_id' => $record->id,
            'before' => $before,
            'after' => $record->fresh()->toArray(),
            'journal_id' => $journal?->id,
            'verification' => $verified,
        ];
    }

    private function integrity(): array
    {
        $debits = (float) DB::table('journal_lines')->sum('debit');
        $credits = (float) DB::table('journal_lines')->sum('credit');
        return [
            'journal_count' => DB::table('journal_entries')->count(),
            'journal_line_count' => DB::table('journal_lines')->count(),
            'total_debits' => $debits,
            'total_credits' => $credits,
            'difference' => round($debits - $credits, 4),
            'unbalanced_journal_count' => DB::table('journal_entries as je')->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')->select('je.id')->groupBy('je.id')->havingRaw('ROUND(COALESCE(SUM(jl.debit), 0), 4) <> ROUND(COALESCE(SUM(jl.credit), 0), 4)')->get()->count(),
            'orphan_line_count' => DB::table('journal_lines as jl')->leftJoin('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->whereNull('je.id')->count(),
            'missing_account_line_count' => DB::table('journal_lines as jl')->leftJoin('accounting_accounts as aa', 'aa.id', '=', 'jl.accounting_account_id')->whereNull('aa.id')->count(),
            'duplicate_economic_key_count' => DB::query()->fromSub(DB::table('journal_entries')->whereNotNull('source_type')->whereNotNull('source_id')->whereNotNull('event_type')->select('source_type', 'source_id', 'event_type')->groupBy('source_type', 'source_id', 'event_type')->havingRaw('COUNT(*) > 1'), 'duplicates')->count(),
        ];
    }
}
