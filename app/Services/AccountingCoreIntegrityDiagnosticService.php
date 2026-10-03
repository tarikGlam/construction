<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\AccountingConfig;
use App\Models\Income;
use App\Models\Purchase;
use App\Models\ReturnPurchase;
use App\Models\Returns;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact, read-only F-012 checks over retained core accounting evidence. */
class AccountingCoreIntegrityDiagnosticService
{
    public const VERSION = '1.0.0';
    public const SAMPLE_LIMIT = 25;
    private array $datasetBoundary = [];
    private string $scanMode = 'quick';

    public function scan(?int $forcedWarehouseId = null, ?array $only = null, ?array $datasetBoundary = null): array
    {
        abort_if(app(WarehouseAccessService::class)->isPortalIdentity(), 403);
        $this->scanMode = $datasetBoundary === null ? 'quick' : 'deep';
        $this->datasetBoundary = $datasetBoundary ?: $this->captureBoundary();
        $warehouseId = $forcedWarehouseId ?: (app(WarehouseAccessService::class)->isRestricted()
            ? app(WarehouseAccessService::class)->warehouseId() : null);
        if (!AccountingConfig::find(1)?->enabled) {
            return collect($this->methods())->when($only !== null, fn ($methods) => $methods->only($only))->mapWithKeys(fn ($method, $key) => [$key => $this->finding(
                $key, 'not_applicable', 'info', 0, [], $warehouseId, ['reason' => 'accounting_disabled']
            )])->all();
        }
        $checks = [];
        foreach ($this->methods() as $key => $method) {
            if ($only !== null && !in_array($key, $only, true)) continue;
            try {
                $checks[$key] = $this->{$method}($warehouseId);
            } catch (\Throwable $exception) {
                report($exception);
                $checks[$key] = $this->finding($key, 'scan_failed', 'critical', null, [], $warehouseId,
                    ['reason' => 'detector_failed']);
            }
        }
        return $checks;
    }

    public function checkKeys(): array
    {
        return array_keys($this->methods());
    }

    private function unbalancedActiveJournals(?int $warehouseId): array
    {
        $query = $this->activeJournals()->join('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->when($warehouseId, fn ($q) => $q->where('je.warehouse_id', $warehouseId))
            ->groupBy('je.id', 'je.reference_no', 'je.warehouse_id')
            ->havingRaw('ROUND(SUM(jl.debit),4) <> ROUND(SUM(jl.credit),4)')
            ->selectRaw('je.id, je.reference_no, je.warehouse_id, SUM(jl.debit) debit, SUM(jl.credit) credit');
        $this->boundary($query, 'jl.id', 'journal_lines');
        return $this->fromQuery(__FUNCTION__, $query, $warehouseId, 'critical');
    }

    private function duplicateActiveJournalFamilies(?int $warehouseId): array
    {
        $query = $this->activeJournals()->whereNotNull('je.source_type')->whereNotNull('je.source_id')
            ->when($warehouseId, fn ($q) => $q->where('je.warehouse_id', $warehouseId))
            ->groupBy('je.source_type', 'je.source_id', 'je.warehouse_id')->havingRaw('COUNT(*) > 1')
            ->selectRaw('MIN(je.id) id, je.source_type, je.source_id, je.warehouse_id, COUNT(*) active_count');
        return $this->fromQuery(__FUNCTION__, $query, $warehouseId, 'critical');
    }

    private function missingRequiredSemanticMappings(?int $warehouseId): array
    {
        $roles = array_merge(AccountingService::CORE_CERTIFICATION_ROLES, AccountingService::FEATURE_CERTIFICATION_ROLES);
        $presentQuery = DB::table('account_mappings as am')->join('accounting_accounts as aa', 'aa.id', '=', 'am.accounting_account_id')
            ->where('am.mapped_id', 0)->where('aa.is_active', true)->whereIn('am.mapped_type', $roles);
        $this->boundary($presentQuery, 'am.id', 'account_mappings');
        $this->boundary($presentQuery, 'aa.id', 'accounting_accounts');
        $present = $presentQuery->pluck('am.mapped_type')->all();
        $missing = array_values(array_diff($roles, $present));
        $samples = array_map(fn ($role, $i) => ['id' => $i + 1, 'semantic_role' => $role, 'reason' => 'missing_unique_active_mapping'],
            array_slice($missing, 0, self::SAMPLE_LIMIT), array_keys(array_slice($missing, 0, self::SAMPLE_LIMIT)));
        return $this->finding(__FUNCTION__, $missing ? 'critical' : 'healthy', $missing ? 'critical' : 'info', count($missing), $samples, null);
    }

    private function duplicateSemanticMappingsOrAccountCodes(?int $warehouseId): array
    {
        $mapping = DB::table('account_mappings')->groupBy('mapped_type', 'mapped_id')->havingRaw('COUNT(*) > 1')
            ->selectRaw('MIN(id) id, mapped_type, mapped_id, COUNT(*) duplicate_count');
        $codes = DB::table('accounting_accounts')->groupBy('code')->havingRaw('COUNT(*) > 1')
            ->selectRaw('MIN(id) id, code, COUNT(*) duplicate_count');
        $this->boundary($mapping, 'account_mappings.id', 'account_mappings');
        $this->boundary($codes, 'accounting_accounts.id', 'accounting_accounts');
        $count = DB::query()->fromSub(clone $mapping, 'd')->count() + DB::query()->fromSub(clone $codes, 'd')->count();
        $samples = array_merge($mapping->orderBy('id')->limit(self::SAMPLE_LIMIT)->get()->map(fn ($r) => (array) $r + ['reason' => 'duplicate_mapping'])->all(),
            $codes->orderBy('id')->limit(self::SAMPLE_LIMIT)->get()->map(fn ($r) => (array) $r + ['reason' => 'duplicate_account_code'])->all());
        return $this->finding(__FUNCTION__, $count ? 'critical' : 'healthy', $count ? 'critical' : 'info', $count,
            array_slice($samples, 0, self::SAMPLE_LIMIT), null);
    }

    private function stalledAccountingEvents(?int $warehouseId): array
    {
        $warehouseSources = [Sale::class, Purchase::class, Returns::class, ReturnPurchase::class, Expense::class, Income::class];
        $query = DB::table('accounting_sync_queue as q')->where(function ($q) {
            $q->where('q.status', 'failed')->orWhere(fn ($p) => $p->where('q.status', 'pending')->where('q.created_at', '<', now()->subMinutes(15)));
        });
        $this->boundary($query, 'q.id', 'accounting_sync_queue');
        if ($warehouseId) {
            $query->where(function ($outer) use ($warehouseSources, $warehouseId) {
                foreach ($warehouseSources as $type) {
                    $table = (new $type)->getTable();
                    $outer->orWhereExists(function ($x) use ($table, $type, $warehouseId) {
                        $x->selectRaw(1)->from($table.' as src')->whereColumn('src.id', 'q.source_id')
                            ->where('q.source_type', $type)->where('src.warehouse_id', $warehouseId);
                        $this->boundary($x, 'src.id', $table);
                    });
                }
            });
        }
        return $this->fromQuery(__FUNCTION__, $query->select(['q.id', 'q.source_type', 'q.source_id', 'q.status', 'q.attempts']), $warehouseId, 'needs_review');
    }

    private function closedPeriodLatePostings(?int $warehouseId): array
    {
        $query = $this->activeJournals()->join('accounting_periods as ap', function ($join) {
            $join->on('je.entry_date', '>=', 'ap.start_date')->on('je.entry_date', '<=', 'ap.end_date');
        })->where('ap.is_closed', true)->whereNotNull('ap.closed_at')->whereColumn('je.created_at', '>', 'ap.closed_at')
            ->when($warehouseId, fn ($q) => $q->where('je.warehouse_id', $warehouseId))
            ->select(['je.id', 'je.reference_no', 'je.warehouse_id', 'je.event_type', 'ap.id as period_id']);
        $this->boundary($query, 'ap.id', 'accounting_periods');
        return $this->fromQuery(__FUNCTION__, $query, $warehouseId, 'needs_review');
    }

    private function warehouseAttributionIntegrity(?int $warehouseId): array
    {
        $families = [[Sale::class, 'sales'], [Purchase::class, 'purchases'], [Returns::class, 'returns'],
            [ReturnPurchase::class, 'return_purchases'], [Expense::class, 'expenses'], [Income::class, 'incomes']];
        $count = 0; $samples = [];
        foreach ($families as [$type, $table]) {
            $query = $this->activeJournals()->join($table.' as src', 'src.id', '=', 'je.source_id')
                ->where('je.source_type', $type)->where(fn ($q) => $q->whereNull('je.warehouse_id')->orWhereColumn('je.warehouse_id', '<>', 'src.warehouse_id'))
                ->when($warehouseId, fn ($q) => $q->where('src.warehouse_id', $warehouseId))
                ->selectRaw('je.id, je.reference_no, je.warehouse_id, src.warehouse_id source_warehouse_id');
            $this->boundary($query, 'src.id', $table);
            $count += (clone $query)->count();
            foreach ($query->orderBy('je.id')->limit(self::SAMPLE_LIMIT)->get() as $row) {
                if (count($samples) < self::SAMPLE_LIMIT) $samples[] = (array) $row + ['source_type' => $type, 'reason' => 'missing_or_mismatched_warehouse'];
            }
        }
        return $this->finding(__FUNCTION__, $count ? 'critical' : 'healthy', $count ? 'critical' : 'info', $count, $samples, $warehouseId);
    }

    private function orphanedPaymentAccountMappings(?int $warehouseId): array
    {
        $query = DB::table('account_mappings as am')->leftJoin('accounts as a', function ($join) {
            $join->on('a.id', '=', 'am.mapped_id');
            if (array_key_exists('accounts', $this->datasetBoundary)) {
                $join->where('a.id', '<=', (int) $this->datasetBoundary['accounts']);
            }
        })
            ->where('am.mapped_type', 'App\\Models\\Account')->whereNull('a.id')
            ->select(['am.id', 'am.mapped_id', 'am.accounting_account_id']);
        $this->boundary($query, 'am.id', 'account_mappings');
        return $this->fromQuery(__FUNCTION__, $query, null, 'critical');
    }

    private function customerDepositLiabilityDisagreement(?int $warehouseId): array
    {
        $deposits = DB::table('deposits')->whereNotIn('accounting_status', ['reversed', 'voided', 'failed'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(amount) gross_deposit');
        $this->boundary($deposits, 'deposits.id', 'deposits');
        $consumed = DB::table('payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->whereRaw('LOWER(p.paying_method) = ?', ['deposit'])->whereNotIn('p.accounting_status', ['reversed', 'voided', 'failed'])
            ->when($warehouseId, fn ($q) => $q->where('s.warehouse_id', $warehouseId))
            ->groupBy('s.customer_id')->selectRaw('s.customer_id, SUM(p.amount) consumed_deposit');
        $this->boundary($consumed, 'p.id', 'payments');
        $this->boundary($consumed, 's.id', 'sales');
        $query = DB::table('customers as c')->leftJoinSub($deposits, 'd', 'd.customer_id', '=', 'c.id')
            ->leftJoinSub($consumed, 'u', 'u.customer_id', '=', 'c.id')
            ->where(function ($q) {
                $q->whereRaw('ROUND(COALESCE(c.deposit,0),4) <> ROUND(COALESCE(d.gross_deposit,0),4)')
                    ->orWhereRaw('ROUND(COALESCE(c.expense,0),4) <> ROUND(COALESCE(u.consumed_deposit,0),4)');
            })->selectRaw('c.id, ROUND(COALESCE(c.deposit,0),4) cached_gross, ROUND(COALESCE(d.gross_deposit,0),4) ledger_gross,
                ROUND(COALESCE(c.expense,0),4) cached_consumed, ROUND(COALESCE(u.consumed_deposit,0),4) ledger_consumed');
        $this->boundary($query, 'c.id', 'customers');
        return $this->fromQuery(__FUNCTION__, $query, $warehouseId, 'critical');
    }

    private function customerControlAccountDisagreement(?int $warehouseId): array
    {
        if ($this->scanMode === 'deep') return $this->finding(__FUNCTION__, 'inconclusive', 'warning', null, [], $warehouseId,
            ['reason' => 'receivable_reconciliation_service_is_not_watermark_aware']);
        if ($warehouseId) return $this->finding(__FUNCTION__, 'inconclusive', 'warning', null, [], $warehouseId,
            ['reason' => 'cross_warehouse_ar_clearing_policy_not_defined']);
        $operational = app(ReceivableReconciliationService::class)->operationalBalance();
        $accounts = $this->controlAccountIds(AccountingService::ROLE_ACCOUNTS_RECEIVABLE, 'App\\Models\\Customer');
        if (!$accounts) return $this->finding(__FUNCTION__, 'inconclusive', 'warning', null, [], null, ['reason' => 'ar_mapping_missing']);
        $ledger = $this->activeLedgerBalance($accounts);
        $difference = bcsub((string) $operational, $ledger, 4);
        $affected = bccomp($difference, '0.0000', 4) === 0 ? 0 : 1;
        return $this->finding(__FUNCTION__, $affected ? 'critical' : 'healthy', $affected ? 'critical' : 'info', $affected,
            $affected ? [['id' => 1, 'operational_balance' => (string) $operational, 'control_balance' => $ledger, 'difference' => $difference]] : [], null);
    }

    private function supplierControlAccountDisagreement(?int $warehouseId): array
    {
        if ($warehouseId) return $this->finding(__FUNCTION__, 'inconclusive', 'warning', null, [], $warehouseId,
            ['reason' => 'cross_warehouse_ap_clearing_policy_not_defined']);
        $purchaseQuery = DB::table('purchases')->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('purchase_type')->orWhereRaw('LOWER(purchase_type) <> ?', ['opening balance']));
        $this->boundary($purchaseQuery, 'purchases.id', 'purchases');
        $purchases = $purchaseQuery->sum('grand_total');
        $supplierQuery = DB::table('suppliers'); $this->boundary($supplierQuery, 'suppliers.id', 'suppliers');
        $opening = $supplierQuery->sum('opening_balance');
        $returnQuery = DB::table('return_purchases')->whereNotIn('accounting_status', ['reversed', 'voided', 'failed']);
        $this->boundary($returnQuery, 'return_purchases.id', 'return_purchases');
        $returns = $returnQuery->sum('grand_total');
        $paymentQuery = DB::table('payments as p')->join('purchases as x', 'x.id', '=', 'p.purchase_id')->whereNull('x.deleted_at')
            ->whereNotIn('p.accounting_status', ['reversed', 'voided', 'failed']);
        $this->boundary($paymentQuery, 'p.id', 'payments'); $this->boundary($paymentQuery, 'x.id', 'purchases');
        $payments = $paymentQuery->sum('p.amount');
        $operational = bcsub(bcadd((string) $opening, (string) $purchases, 4), bcadd((string) $returns, (string) $payments, 4), 4);
        $accounts = $this->controlAccountIds(AccountingService::ROLE_ACCOUNTS_PAYABLE, 'App\\Models\\Supplier');
        if (!$accounts) return $this->finding(__FUNCTION__, 'inconclusive', 'warning', null, [], null, ['reason' => 'ap_mapping_missing']);
        $ledger = bcmul($this->activeLedgerBalance($accounts), '-1', 4);
        $difference = bcsub($operational, $ledger, 4);
        $affected = bccomp($difference, '0.0000', 4) === 0 ? 0 : 1;
        return $this->finding(__FUNCTION__, $affected ? 'critical' : 'healthy', $affected ? 'critical' : 'info', $affected,
            $affected ? [['id' => 1, 'operational_balance' => $operational, 'control_balance' => $ledger, 'difference' => $difference]] : [], null);
    }

    private function stockQuantityRetainedMovementEvidence(?int $warehouseId): array
    {
        $query = DB::table('product_warehouse')->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId));
        $this->boundary($query, 'product_warehouse.id', 'product_warehouse');
        $count = $query->count();
        if (!$count) return $this->finding(__FUNCTION__, 'healthy', 'info', 0, [], $warehouseId);
        return $this->finding(__FUNCTION__, 'inconclusive', 'warning', null, [], $warehouseId, [
            'reason' => 'append_only_stock_movement_ledger_unavailable',
            'retained_current_rows' => $count,
        ]);
    }

    private function imeiOwnershipIntegrity(?int $warehouseId): array
    {
        $query = DB::table('product_warehouse')->whereNotNull('imei_number')->where('imei_number', '<>', '')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId));
        $this->boundary($query, 'product_warehouse.id', 'product_warehouse');
        $count = $query->count();
        if (!$count) return $this->finding(__FUNCTION__, 'healthy', 'info', 0, [], $warehouseId);
        return $this->finding(__FUNCTION__, 'inconclusive', 'warning', null, [], $warehouseId, [
            'reason' => 'legacy_csv_serial_history_is_not_append_only',
            'retained_current_rows' => $count,
        ]);
    }

    private function controlAccountIds(string $role, string $entityType): array
    {
        $defaultQuery = DB::table('account_mappings')->where('mapped_type', $role)->where('mapped_id', 0);
        $this->boundary($defaultQuery, 'account_mappings.id', 'account_mappings');
        $default = $defaultQuery->value('accounting_account_id');
        $entityQuery = DB::table('account_mappings')->where('mapped_type', $entityType);
        $this->boundary($entityQuery, 'account_mappings.id', 'account_mappings');
        return $entityQuery->pluck('accounting_account_id')
            ->when($default, fn ($ids) => $ids->push($default))->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    private function activeLedgerBalance(array $accountIds): string
    {
        $query = $this->activeJournals()->join('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->whereIn('jl.accounting_account_id', $accountIds)->selectRaw('COALESCE(SUM(jl.debit-jl.credit),0) balance');
        $this->boundary($query, 'jl.id', 'journal_lines');
        $value = $query->value('balance');
        return bcadd((string) $value, '0', 4);
    }

    private function activeJournals()
    {
        $query = DB::table('journal_entries as je')->whereNull('je.related_journal_entry_id')
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)->from('journal_entries as reversal')->whereColumn('reversal.related_journal_entry_id', 'je.id');
                $this->boundary($q, 'reversal.id', 'journal_entries');
            });
        $this->boundary($query, 'je.id', 'journal_entries');
        return $query;
    }

    private function fromQuery(string $key, $query, ?int $warehouseId, string $problemStatus): array
    {
        $count = DB::query()->fromSub(clone $query, 'affected')->count();
        $samples = (clone $query)->orderBy('id')->limit(self::SAMPLE_LIMIT)->get()->map(fn ($r) => (array) $r)->all();
        return $this->finding($key, $count ? $problemStatus : 'healthy', $count ? ($problemStatus === 'critical' ? 'critical' : 'warning') : 'info',
            $count, $samples, $warehouseId);
    }

    private function finding(string $key, string $status, string $severity, ?int $count, array $samples,
        ?int $warehouseId, array $evidence = []): array
    {
        $key = \Illuminate\Support\Str::snake($key);
        $checkKey = 'f012.'.$key;
        return ['check_key' => $checkKey, 'detector_version' => self::VERSION,
            'detector_fingerprint' => hash('sha256', $checkKey.':'.self::VERSION), 'status' => $status, 'severity' => $severity,
            'scope' => $warehouseId ? 'warehouse' : 'global', 'warehouse_id' => $warehouseId,
            'affected_count' => $count ?? 0, 'authoritative_affected_record_count' => $count,
            'affected_amount' => null, 'affected_amounts' => ['transaction_currency' => [],
                'base_currency' => ['amount' => null, 'status' => 'unavailable'], 'included_records' => 0, 'excluded_records' => 0, 'exclusions' => []],
            'count_is_exact' => $count !== null, 'amount_is_exact' => false, 'sample_count' => count($samples),
            'sample_limit' => self::SAMPLE_LIMIT, 'sample_truncated' => $count !== null && $count > count($samples),
            'sample_records' => array_slice($samples, 0, self::SAMPLE_LIMIT), 'technical_evidence' => $evidence,
            'scan_mode' => $this->scanMode, 'scan_status' => $status === 'scan_failed' ? 'scan_failed' : 'completed',
            'dataset_high_water_mark' => $this->datasetBoundary,
            'scanned_at' => now()->toIso8601String(), 'plain_language_explanation' => str_replace('_', ' ', $key),
            'recommended_next_action' => $status === 'healthy' ? 'No action is required.' : 'Review the bounded evidence with an authorized accounting administrator.',
            'repair_available' => false, 'repair_unavailable_reasons' => ['No automatic repair is certified for this aggregate integrity check.'],
            'limitations' => []];
    }

    private function boundary($query, string $column, string $table): void
    {
        if (array_key_exists($table, $this->datasetBoundary)) {
            $query->where($column, '<=', (int) $this->datasetBoundary[$table]);
        }
    }

    private function captureBoundary(): array
    {
        return collect(['accounting_configs', 'accounting_accounts', 'account_mappings', 'accounting_sync_queue',
            'accounting_periods', 'journal_entries', 'journal_lines', 'accounts', 'sales', 'purchases', 'returns',
            'return_purchases', 'expenses', 'incomes', 'deposits', 'payments', 'customers', 'suppliers', 'product_warehouse'])
            ->filter(fn ($table) => Schema::hasTable($table))
            ->mapWithKeys(fn ($table) => [$table => (int) DB::table($table)->max('id')])->all();
    }

    private function methods(): array
    {
        return ['unbalanced_active_journals' => 'unbalancedActiveJournals',
            'duplicate_active_journal_families' => 'duplicateActiveJournalFamilies',
            'missing_required_semantic_mappings' => 'missingRequiredSemanticMappings',
            'duplicate_semantic_mappings_or_account_codes' => 'duplicateSemanticMappingsOrAccountCodes',
            'stalled_accounting_events' => 'stalledAccountingEvents', 'closed_period_late_postings' => 'closedPeriodLatePostings',
            'warehouse_attribution_integrity' => 'warehouseAttributionIntegrity',
            'orphaned_payment_account_mappings' => 'orphanedPaymentAccountMappings',
            'customer_deposit_liability_disagreement' => 'customerDepositLiabilityDisagreement',
            'customer_control_account_disagreement' => 'customerControlAccountDisagreement',
            'supplier_control_account_disagreement' => 'supplierControlAccountDisagreement',
            'stock_quantity_retained_movement_evidence' => 'stockQuantityRetainedMovementEvidence',
            'imei_ownership_integrity' => 'imeiOwnershipIntegrity'];
    }
}
