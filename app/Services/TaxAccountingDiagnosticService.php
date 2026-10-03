<?php

namespace App\Services;

use App\Models\AccountingConfig;
use App\Models\JournalEntry;
use App\Models\Returns;
use App\Models\Sale;
use App\Services\Accounting\CurrencyNormalizationService;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only, bounded F-012 checks for the prospective sale-tax policy. */
class TaxAccountingDiagnosticService
{
    public const SAMPLE_LIMIT = 25;
    public const SCAN_LIMIT = 5000;
    public const DETECTOR_VERSION = '3.0.0';
    private array $datasetBoundary = [];
    private ?int $forcedWarehouseId = null;

    public function scan(string $mode = 'quick', ?\DateTimeInterface $cutoff = null, ?int $forcedWarehouseId = null,
        ?array $datasetBoundary = null, ?array $only = null): array
    {
        abort_if(app(WarehouseAccessService::class)->isPortalIdentity(), 403);
        $cutoff ??= now();
        $this->forcedWarehouseId = $forcedWarehouseId;
        $this->datasetBoundary = $datasetBoundary ?: collect(['accounting_configs', 'accounting_accounts', 'account_mappings', 'currencies', 'sales',
            'returns', 'purchases', 'return_purchases', 'journal_entries', 'journal_lines'])
            ->filter(fn ($table) => \Illuminate\Support\Facades\Schema::hasTable($table))
            ->mapWithKeys(fn ($table) => [$table => (int) DB::table($table)->max('id')])->all();
        $methods = ['output_tax_configuration' => 'configuration', 'output_tax_journal_integrity' => 'journalIntegrity',
            'output_tax_policy_history' => 'policyHistory', 'purchase_tax_classification_coverage' => 'purchaseTaxCoverage'];
        $results = [];
        foreach ($methods as $key => $method) {
            if ($only !== null && !in_array($key, $only, true)) continue;
            try {
                $results[$key] = $this->{$method}($mode, $cutoff);
            } catch (Throwable $exception) {
                report($exception);
                $results[$key] = $this->finding($key, 'scan_failed', 'critical', 0, [],
                    ['reason' => 'detector_failed'], $mode, $cutoff, false);
            }
        }
        return $results;
    }

    public function checkKeys(): array { return $this->keys(); }

    private function configuration(string $mode, \DateTimeInterface $cutoff): array
    {
        $config = AccountingConfig::find(1);
        $inspection = app(OutputTaxProvisioningService::class)->inspect();
        if (!$config?->enabled) return $this->finding(__FUNCTION__, 'not_applicable', 'info', 0, [], [
            'reason' => 'accounting_disabled', 'provisioning' => $inspection,
        ], $mode, $cutoff);

        $policyReady = $config->sales_tax_policy_version === TaxAccountingComponentService::POLICY_V2
            && $config->sales_tax_policy_effective_at && $config->sales_tax_policy_effective_at->lte(now());
        $reasons = $inspection['conflicts'];
        if (!$inspection['account_exists']) $reasons[] = 'missing_account';
        if (!$inspection['mapping_exists']) $reasons[] = 'missing_mapping';
        if (!$policyReady) $reasons[] = 'v2_policy_not_enabled';
        $reasons = array_values(array_unique($reasons));
        return $this->finding(__FUNCTION__, $reasons ? 'critical' : 'healthy', $reasons ? 'critical' : 'info',
            count($reasons), $reasons ? [['reasons' => $reasons]] : [], ['provisioning' => $inspection,
                'policy_version' => $config->sales_tax_policy_version,
                'policy_effective_at' => optional($config->sales_tax_policy_effective_at)->toIso8601String()], $mode, $cutoff);
    }

    private function journalIntegrity(string $mode, \DateTimeInterface $cutoff): array
    {
        $config = AccountingConfig::find(1);
        if (!$config?->enabled) return $this->finding(__FUNCTION__, 'not_applicable', 'info', 0, [], ['reason' => 'accounting_disabled'], $mode, $cutoff);
        $taxAccountId = app(OutputTaxProvisioningService::class)->inspect()['mapped_account_id'] ?? null;
        if (!$taxAccountId) return $this->finding(__FUNCTION__, 'inconclusive', 'warning', 0, [], ['reason' => 'missing_output_tax_mapping'], $mode, $cutoff);

        $query = $this->activeJournals()->where('je.accounting_policy_version', TaxAccountingComponentService::POLICY_V2)
            ->whereIn('je.source_type', [Sale::class, Returns::class])->where('je.created_at', '<=', $cutoff)
            ->select('je.*')->orderBy('je.id')->limit(self::SCAN_LIMIT + 1);
        $this->warehouse($query, 'je.warehouse_id');
        $journals = JournalEntry::hydrate($query->get()->map(fn ($row) => (array) $row)->all());
        $complete = $journals->count() <= self::SCAN_LIMIT;
        $journals = $journals->take(self::SCAN_LIMIT)->values();
        $journals->load(['lines' => function ($query) {
            $this->boundary($query, 'journal_lines.id', 'journal_lines');
        }]);

        $saleIds = $journals->where('source_type', Sale::class)->pluck('source_id');
        $returnIds = $journals->where('source_type', Returns::class)->pluck('source_id');
        $saleQuery = Sale::whereIn('id', $saleIds); $this->boundary($saleQuery, 'sales.id', 'sales');
        $returnQuery = Returns::whereIn('id', $returnIds); $this->boundary($returnQuery, 'returns.id', 'returns');
        $sales = $saleQuery->get()->keyBy('id');
        $returns = $returnQuery->get()->keyBy('id');
        $saleLineQuery = DB::table('product_sales')->whereIn('sale_id', $saleIds); $this->boundary($saleLineQuery, 'product_sales.id', 'product_sales');
        $returnLineQuery = DB::table('product_returns')->whereIn('return_id', $returnIds); $this->boundary($returnLineQuery, 'product_returns.id', 'product_returns');
        $saleLineTax = $saleLineQuery->groupBy('sale_id')->selectRaw('sale_id, SUM(tax) tax')->pluck('tax', 'sale_id');
        $returnLineTax = $returnLineQuery->groupBy('return_id')->selectRaw('return_id, SUM(tax) tax')->pluck('tax', 'return_id');
        $returnSaleIds = $returns->pluck('sale_id')->filter()->unique()->values();
        $recognizedReturnTax = $this->activeTaxBySale($returnSaleIds, (int) $taxAccountId, Sale::class, 'credit');
        $cumulativeReturnTax = $this->activeTaxBySale($returnSaleIds, (int) $taxAccountId, Returns::class, 'debit');
        $currencyCodes = DB::table('currencies')->pluck('code', 'id');
        $samples = []; $currency = []; $affected = 0;
        foreach ($journals as $journal) {
            $source = $journal->source_type === Sale::class ? $sales->get($journal->source_id) : $returns->get($journal->source_id);
            $reason = null; $expected = '0.0000'; $actual = '0.0000';
            if (!$source) $reason = 'missing_source';
            else {
                $header = $this->money($source->total_tax ?? 0);
                $line = $this->money($journal->source_type === Sale::class ? ($saleLineTax[$source->id] ?? 0) : ($returnLineTax[$source->id] ?? 0));
                if (bccomp($header, $line, 4) !== 0) $reason = 'source_tax_snapshot_mismatch';
                $sourceTax = bcadd($header, $this->money($source->order_tax ?? 0), 4);
                $expected = app(CurrencyNormalizationService::class)->normalize($sourceTax, $source->currency_id, $source->exchange_rate);
                $side = $journal->source_type === Sale::class ? 'credit' : 'debit';
                $actual = $journal->lines->where('accounting_account_id', (int) $taxAccountId)
                    ->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->{$side}, 4), '0.0000');
                if (!$reason && bccomp($actual, $expected, 4) !== 0) $reason = bccomp($actual, $expected, 4) > 0 ? 'output_tax_excess_or_duplicate' : 'output_tax_missing_or_incorrect';
                $debits = $journal->lines->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->debit, 4), '0.0000');
                $credits = $journal->lines->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->credit, 4), '0.0000');
                $gross = app(CurrencyNormalizationService::class)->normalize($source->grand_total, $source->currency_id, $source->exchange_rate);
                if (!$reason && (bccomp($debits, $credits, 4) !== 0 || bccomp($debits, $gross, 4) !== 0)) $reason = 'revenue_tax_receivable_mismatch';
                if (!$reason && $journal->source_type === Returns::class
                    && bccomp($cumulativeReturnTax[(int) $source->sale_id] ?? '0.0000', $recognizedReturnTax[(int) $source->sale_id] ?? '0.0000', 4) > 0)
                    $reason = 'cumulative_return_tax_exceeds_original';
            }
            if ($reason) {
                $affected++;
                $code = $currencyCodes[$source?->currency_id] ?? (string) ($source?->currency_id ?? 'unknown');
                $currency[$code] = ($currency[$code] ?? 0) + 1;
                if (count($samples) < self::SAMPLE_LIMIT) $samples[] = ['journal_id' => (int) $journal->id,
                    'source_type' => class_basename($journal->source_type), 'source_id' => (int) $journal->source_id,
                    'warehouse_id' => $journal->warehouse_id, 'reason' => $reason, 'expected_tax_base' => $expected, 'actual_tax_base' => $actual];
            }
        }
        return $this->finding(__FUNCTION__, $affected ? 'critical' : ($complete ? 'healthy' : 'partial'), $affected ? 'critical' : 'info',
            $affected, $samples, ['affected_counts_by_currency' => $currency,
                'limitations' => $complete ? [] : ['The bounded scan reached its record ceiling; run Deep Scan Beta.']], $mode, $cutoff, $complete);
    }

    private function policyHistory(string $mode, \DateTimeInterface $cutoff): array
    {
        $config = AccountingConfig::find(1);
        if (!$config?->enabled) return $this->finding(__FUNCTION__, 'not_applicable', 'info', 0, [], ['reason' => 'accounting_disabled'], $mode, $cutoff);
        $query = $this->activeJournals()->whereIn('je.source_type', [Sale::class, Returns::class])
            ->where('je.created_at', '<=', $cutoff)->where(function ($q) {
                $q->whereNull('je.accounting_policy_version')->orWhere('je.accounting_policy_version', '!=', TaxAccountingComponentService::POLICY_V2);
            })->leftJoin('sales as s', function ($join) { $join->on('s.id', '=', 'je.source_id')->where('je.source_type', Sale::class); })
            ->leftJoin('returns as r', function ($join) { $join->on('r.id', '=', 'je.source_id')->where('je.source_type', Returns::class); })
            ->selectRaw('je.id, je.source_type, je.source_id, je.accounting_policy_version, je.warehouse_id, COALESCE(s.currency_id,r.currency_id) currency_id, COALESCE(s.total_tax,r.total_tax,0)+COALESCE(s.order_tax,r.order_tax,0) tax_exposure')
            ->orderBy('je.id')->limit(self::SCAN_LIMIT + 1);
        $this->warehouse($query, 'je.warehouse_id');
        $rows = $query->get(); $complete = $rows->count() <= self::SCAN_LIMIT;
        $scannedRows = $rows->take(self::SCAN_LIMIT);
        $lineCounts = DB::table('journal_lines')->whereIn('journal_entry_id', $scannedRows->pluck('id'))
            ->groupBy('journal_entry_id')->selectRaw('journal_entry_id, COUNT(*) count');
        $this->boundary($lineCounts, 'journal_lines.id', 'journal_lines');
        $lineCounts = $lineCounts->pluck('count', 'journal_entry_id');
        $taxAccountId = app(OutputTaxProvisioningService::class)->inspect()['mapped_account_id'] ?? null;
        $legacyTaxAmounts = $taxAccountId ? DB::table('journal_lines')->whereIn('journal_entry_id', $scannedRows->pluck('id'))
            ->where('accounting_account_id', $taxAccountId)->groupBy('journal_entry_id')
            ->selectRaw('journal_entry_id, SUM(debit + credit) amount') : null;
        if ($legacyTaxAmounts) {
            $this->boundary($legacyTaxAmounts, 'journal_lines.id', 'journal_lines');
            $legacyTaxAmounts = $legacyTaxAmounts->pluck('amount', 'journal_entry_id');
        } else $legacyTaxAmounts = collect();
        $currencyCodes = DB::table('currencies')->pluck('code', 'id');
        $samples = []; $counts = ['legacy' => 0, 'unknown' => 0, 'legacy_tax_mismatch' => 0]; $byCurrency = [];
        foreach ($scannedRows as $row) {
            $lineCount = (int) ($lineCounts[$row->id] ?? 0);
            $classification = $row->accounting_policy_version === TaxAccountingComponentService::POLICY_LEGACY
                || ($row->accounting_policy_version === null && $lineCount === 2) ? 'legacy' : 'unknown';
            if ($classification === 'legacy' && $row->source_type === Returns::class
                && bccomp($this->money($legacyTaxAmounts[$row->id] ?? 0), '0.0000', 4) > 0)
                $classification = 'legacy_tax_mismatch';
            $counts[$classification]++;
            $code = $currencyCodes[$row->currency_id] ?? (string) ($row->currency_id ?: 'unknown');
            $byCurrency[$code] = bcadd($byCurrency[$code] ?? '0.0000', $this->money($row->tax_exposure), 4);
            if (count($samples) < self::SAMPLE_LIMIT) $samples[] = ['journal_id' => (int) $row->id, 'source_type' => class_basename($row->source_type),
                'source_id' => (int) $row->source_id, 'warehouse_id' => $row->warehouse_id, 'classification' => $classification];
        }
        $affected = array_sum($counts);
        $status = $counts['legacy_tax_mismatch'] ? 'critical' : ($counts['unknown'] ? 'inconclusive' : ($affected ? 'legacy' : ($complete ? 'healthy' : 'partial')));
        return $this->finding(__FUNCTION__, $status, $counts['legacy_tax_mismatch'] ? 'critical' : ($counts['unknown'] ? 'warning' : 'info'), $affected, $samples, ['classifications' => $counts,
                'transaction_time_tax_exposure_by_currency' => $byCurrency,
                'limitations' => array_values(array_filter([
                    'Legacy gross journals are classified, not treated as corruption or automatically adjusted.',
                    $complete ? null : 'The bounded scan reached its record ceiling; run Deep Scan Beta.',
                ]))], $mode, $cutoff, $complete);
    }

    private function purchaseTaxCoverage(string $mode, \DateTimeInterface $cutoff): array
    {
        $config = AccountingConfig::find(1);
        if (!$config?->enabled) return $this->finding(__FUNCTION__, 'not_applicable', 'info', 0, [], ['reason' => 'accounting_disabled'], $mode, $cutoff);
        $samples = []; $byCurrency = []; $count = 0; $complete = true; $remaining = self::SCAN_LIMIT;
        $currencyCodes = DB::table('currencies')->pluck('code', 'id');
        foreach ([['purchases', 'purchase'], ['return_purchases', 'purchase_return']] as [$table, $type]) {
            if ($remaining <= 0) { $complete = false; break; }
            $query = DB::table($table)->where('total_tax', '>', 0)->where('created_at', '<=', $cutoff)
                ->orderBy('id')->limit($remaining + 1);
            $this->boundary($query, "{$table}.id", $table);
            $this->warehouse($query, 'warehouse_id');
            $rows = $query->get(['id', 'reference_no', 'warehouse_id', 'currency_id', 'total_tax']);
            if ($rows->count() > $remaining) $complete = false;
            foreach ($rows->take($remaining) as $row) {
                $count++; $remaining--;
                $code = $currencyCodes[$row->currency_id] ?? (string) ($row->currency_id ?: 'unknown');
                $byCurrency[$code] = bcadd($byCurrency[$code] ?? '0.0000', $this->money($row->total_tax), 4);
                if (count($samples) < self::SAMPLE_LIMIT) $samples[] = ['id' => (int) $row->id,
                    'reference' => $row->reference_no, 'source_type' => $type, 'warehouse_id' => $row->warehouse_id,
                    'currency' => $code, 'preserved_product_tax' => $this->money($row->total_tax)];
            }
        }
        return $this->finding(__FUNCTION__, $count ? 'inconclusive' : ($complete ? 'healthy' : 'partial'),
            $count ? 'warning' : 'info', $count, $samples, [
                'transaction_time_tax_exposure_by_currency' => $byCurrency,
                'limitations' => array_values(array_filter([
                    'Current purchase journals separate order tax only; preserved product-level purchase tax remains embedded in inventory or purchase-return classification and requires a separate tax-accounting phase.',
                    $complete ? null : 'The bounded scan reached its record ceiling; run Deep Scan Beta.',
                ])),
            ], $mode, $cutoff, $complete);
    }

    private function activeTaxBySale($saleIds, int $taxAccountId, string $sourceType, string $side)
    {
        if ($saleIds->isEmpty()) return collect();
        $query = DB::table('journal_entries as tax_je')
            ->join('journal_lines as tax_jl', 'tax_jl.journal_entry_id', '=', 'tax_je.id')
            ->where('tax_je.source_type', $sourceType)
            ->where('tax_je.accounting_policy_version', TaxAccountingComponentService::POLICY_V2)
            ->whereNull('tax_je.related_journal_entry_id')
            ->where('tax_jl.accounting_account_id', $taxAccountId)
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)->from('journal_entries as tax_rev')
                    ->whereColumn('tax_rev.related_journal_entry_id', 'tax_je.id');
                $this->boundary($q, 'tax_rev.id', 'journal_entries');
            });
        $this->boundary($query, 'tax_je.id', 'journal_entries');
        $this->boundary($query, 'tax_jl.id', 'journal_lines');
        if ($sourceType === Sale::class) {
            return $query->whereIn('tax_je.source_id', $saleIds)->groupBy('tax_je.source_id')
                ->selectRaw("tax_je.source_id sale_id, SUM(tax_jl.{$side}) amount")->pluck('amount', 'sale_id');
        }
        return $query->join('returns as tax_return', 'tax_return.id', '=', 'tax_je.source_id')
            ->whereIn('tax_return.sale_id', $saleIds)->groupBy('tax_return.sale_id')
            ->selectRaw("tax_return.sale_id, SUM(tax_jl.{$side}) amount")->pluck('amount', 'sale_id');
    }

    private function activeJournals()
    {
        $query = DB::table('journal_entries as je')->whereNull('je.related_journal_entry_id')
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)->from('journal_entries as rev')->whereColumn('rev.related_journal_entry_id', 'je.id');
                $this->boundary($q, 'rev.id', 'journal_entries');
            });
        $this->boundary($query, 'je.id', 'journal_entries');
        return $query;
    }

    private function warehouse($query, string $column): void
    {
        $access = app(WarehouseAccessService::class);
        $warehouseId = $this->forcedWarehouseId ?: ($access->isRestricted() ? $access->warehouseId() : null);
        if ($warehouseId) $query->where($column, $warehouseId);
    }

    private function boundary($query, string $column, string $table): void
    {
        if (array_key_exists($table, $this->datasetBoundary)) {
            $query->where($column, '<=', (int) $this->datasetBoundary[$table]);
        }
    }

    private function finding(string $key, string $status, string $severity, int $count, array $samples, array $evidence,
        string $mode, \DateTimeInterface $cutoff, bool $exact = true): array
    {
        $status = match ($status) { 'warning', 'legacy' => 'needs_review', 'partial' => 'inconclusive', default => $status };
        if (!$exact && $status === 'healthy') $status = 'inconclusive';
        $checkKey = 'f012.'.$key;
        $fingerprint = hash('sha256', json_encode(['check_key' => $checkKey, 'version' => self::DETECTOR_VERSION,
            'sample_limit' => self::SAMPLE_LIMIT, 'scan_limit' => self::SCAN_LIMIT], JSON_UNESCAPED_SLASHES));
        $warehouseId = $this->forcedWarehouseId ?: app(WarehouseAccessService::class)->warehouseId();
        return ['check_key' => $checkKey, 'detector_version' => self::DETECTOR_VERSION, 'detector_fingerprint' => $fingerprint,
            'status' => $status, 'severity' => $severity,
            'scope' => $warehouseId ? 'warehouse' : 'global',
            'warehouse_id' => $warehouseId, 'affected_count' => $count,
            'authoritative_affected_record_count' => $exact ? $count : null,
            'affected_amount' => null, 'currency' => 'per_currency', 'count_is_exact' => $exact,
            'sample_count' => count($samples), 'sample_limit' => self::SAMPLE_LIMIT,
            'sample_truncated' => !$exact || $count > count($samples), 'scan_mode' => $mode,
            'scan_status' => $status === 'scan_failed' ? 'scan_failed' : ($status === 'inconclusive' ? 'completed_with_warnings' : 'completed'),
            'scan_scope' => $warehouseId ? 'warehouse' : 'global',
            'cutoff' => $cutoff->format(DATE_ATOM), 'dataset_high_water_mark' => $this->datasetBoundary,
            'affected_amounts' => ['transaction_currency' => $evidence['transaction_time_tax_exposure_by_currency'] ?? [],
                'base_currency' => ['amount' => null, 'status' => 'unavailable'], 'included_records' => $count, 'excluded_records' => 0, 'exclusions' => []],
            'amount_is_exact' => false, 'limitations' => $evidence['limitations'] ?? [], 'summary' => __('db.accounting_health_check_'.$key.'_summary'),
            'plain_language_explanation' => __('db.accounting_health_check_'.$key.'_summary'), 'guidance' => __('db.accounting_health_guidance'),
            'recommended_next_action' => __('db.accounting_health_guidance'), 'sample_records' => array_slice($samples, 0, self::SAMPLE_LIMIT),
            'technical_evidence' => $evidence, 'scanned_at' => now()->toIso8601String(), 'repair_available' => false,
            'repair_unavailable_reasons' => [$status === 'inconclusive'
                ? 'Historical tax policy or evidence is not authoritative enough for automatic correction.'
                : ($status === 'scan_failed' ? 'The detector must complete successfully before any correction.' : 'Historical tax entries are diagnostic-only and are never rewritten.')]];
    }

    private function money($amount): string { return CurrencyNormalizationService::roundHalfUp((string) ($amount ?? 0), 4); }
    private function keys(): array { return ['output_tax_configuration', 'output_tax_journal_integrity', 'output_tax_policy_history', 'purchase_tax_classification_coverage']; }
}
