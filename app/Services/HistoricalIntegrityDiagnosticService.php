<?php

namespace App\Services;

use App\Models\AccountingConfig;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Returns;
use App\Models\Sale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only, bounded historical detectors for F-012. */
class HistoricalIntegrityDiagnosticService
{
    public const SAMPLE_LIMIT = 25;
    public const SCAN_LIMIT = 5000;
    public const DETECTOR_VERSION = '3.0.0';
    private array $datasetBoundary = [];
    private ?int $forcedWarehouseId = null;

    public function scan(string $mode = 'quick', ?\DateTimeInterface $cutoff = null, ?array $only = null,
        ?int $forcedWarehouseId = null, ?array $datasetBoundary = null): array
    {
        abort_if(app(WarehouseAccessService::class)->isPortalIdentity(), 403);
        $cutoff ??= now();
        $this->forcedWarehouseId = $forcedWarehouseId;
        $this->datasetBoundary = $datasetBoundary ?: $this->captureBoundary();

        $config = AccountingConfig::find(1);
        if (!$config?->enabled) {
            return collect($this->keys())->mapWithKeys(fn ($key) => [$key => $this->finding(
                $key, 'not_applicable', 'info', 0, null, [], ['reason' => 'accounting_disabled'], $mode, $cutoff
            )])->all();
        }

        $checks = [];
        foreach ($only ?: $this->keys() as $key) {
            try {
                $checks[$key] = $this->{$key}($config);
            } catch (Throwable $exception) {
                report($exception);
                $checks[$key] = $this->finding($key, 'scan_failed', 'critical', 0, null, [], [
                    'reason' => 'detector_failed',
                ], $mode, $cutoff, false);
            }
        }
        foreach ($checks as &$check) {
            $check['scan_mode'] = $mode;
            $check['cutoff'] = $cutoff->format(DATE_ATOM);
        }
        return $checks;
    }

    public function checkKeys(): array { return $this->keys(); }

    private function excess_cumulative_refunds(AccountingConfig $config): array
    {
        return $this->scanSalePaymentIntegrity($config, true);
    }

    private function over_applied_sale_payments(AccountingConfig $config): array
    {
        return $this->scanSalePaymentIntegrity($config, false);
    }

    private function scanSalePaymentIntegrity(AccountingConfig $config, bool $refundCheck): array
    {
        $salesQuery = $this->saleScope(DB::table('sales as s'))->where($this->eligibleDate('s', $config));
        $this->boundary($salesQuery, 's.id', 'sales');
        $sales = $salesQuery
            ->orderBy('s.id')->limit(self::SCAN_LIMIT + 1)
            ->get(['s.id', 's.reference_no', 's.warehouse_id', 's.grand_total', 's.currency_id', 's.exchange_rate']);
        $complete = $sales->count() <= self::SCAN_LIMIT;
        $sales = $sales->take(self::SCAN_LIMIT); $ids = $sales->pluck('id');
        $paymentQuery = DB::table('payments')->whereIn('sale_id', $ids)->whereNotIn('accounting_status', $this->inactiveStatuses());
        $this->boundary($paymentQuery, 'payments.id', 'payments');
        $payments = $paymentQuery
            ->orderBy('id')->limit(self::SCAN_LIMIT + 1)->get();
        $returnQuery = DB::table('returns')->whereIn('sale_id', $ids)->whereNotIn('accounting_status', ['reversed', 'voided', 'failed']);
        $this->boundary($returnQuery, 'returns.id', 'returns');
        $returns = $returnQuery
            ->orderBy('id')->limit(self::SCAN_LIMIT + 1)->get();
        if ($payments->count() > self::SCAN_LIMIT || $returns->count() > self::SCAN_LIMIT) $complete = false;
        $payments = $payments->take(self::SCAN_LIMIT)->groupBy('sale_id');
        $returns = $returns->take(self::SCAN_LIMIT)->groupBy('sale_id');
        $samples = []; $affected = 0; $baseExposure = '0.0000'; $rateMissing = 0; $currencyExposure = [];
        $currencyCodes = DB::table('currencies')->pluck('code', 'id')->all();
        $baseCurrencyId = app(\App\Services\Accounting\CurrencyNormalizationService::class)->getBaseCurrencyId();
        foreach ($sales as $sale) {
            try {
                $saleBase = $this->baseAmount($sale->grand_total, $sale->currency_id, $sale->exchange_rate);
                $collections = $refunds = $returned = '0.0000'; $byReturn = []; $returnValues = [];
                foreach ($returns[$sale->id] ?? [] as $return) {
                    $base = $this->baseAmount($return->grand_total, $return->currency_id, $return->exchange_rate);
                    $returned = bcadd($returned, $base, 4); $returnValues[(int) $return->id] = $base;
                }
                foreach ($payments[$sale->id] ?? [] as $payment) {
                    $applied = (string) $payment->amount;
                    if (!$payment->return_id && strcasecmp((string) $payment->paying_method, 'Cash') === 0 && bccomp((string) ($payment->change ?? 0), '0', 4) > 0) {
                        $applied = bcsub($applied, (string) $payment->change, 4);
                    }
                    if (bccomp($applied, '0', 4) < 0) $applied = '0.0000';
                    $base = $this->baseAmount($applied, $payment->currency_id, $payment->exchange_rate);
                    if ($payment->return_id) {
                        $refunds = bcadd($refunds, $base, 4);
                        $byReturn[(int) $payment->return_id] = bcadd($byReturn[(int) $payment->return_id] ?? '0.0000', $base, 4);
                    } else $collections = bcadd($collections, $base, 4);
                }
                if ($refundCheck) {
                    $perReturn = '0.0000';
                    foreach ($byReturn as $returnId => $value) $perReturn = bcadd($perReturn,
                        bccomp($value, $returnValues[$returnId] ?? '0.0000', 4) > 0 ? bcsub($value, $returnValues[$returnId] ?? '0.0000', 4) : '0.0000', 4);
                    $exposure = max([0.0, (float) bcsub($refunds, $collections, 4), (float) bcsub($refunds, $returned, 4), (float) $perReturn]);
                } else {
                    $receivable = bcadd(bcsub($saleBase, $returned, 4), $refunds, 4);
                    $exposure = max(0.0, (float) bcsub($collections, $receivable, 4));
                }
                if ($exposure > 0) {
                    $affected++; $formatted = number_format($exposure, 4, '.', '');
                    $baseExposure = bcadd($baseExposure, $formatted, 4);
                    $code = $currencyCodes[(int) $sale->currency_id] ?? 'CURRENCY_'.(int) $sale->currency_id;
                    $sourceExposure = (int) $sale->currency_id === $baseCurrencyId ? $formatted
                        : \App\Services\Accounting\CurrencyNormalizationService::roundHalfUp(bcmul($formatted, (string) $sale->exchange_rate, 8), 4);
                    $currencyExposure[$code] = bcadd($currencyExposure[$code] ?? '0.0000', $sourceExposure, 4);
                    $this->sample($samples, $sale, ['reason' => $refundCheck ? 'refund_exceeds_authoritative_limits' : 'applied_amount_exceeds_receivable',
                        'source_currency' => $code, 'source_currency_exposure' => $sourceExposure,
                        'base_currency_exposure' => $formatted]);
                }
            } catch (\Throwable $exception) {
                $rateMissing++; $complete = false;
                $this->sample($samples, $sale, ['reason' => 'missing_or_invalid_transaction_time_rate']);
            }
        }
        $key = $refundCheck ? 'excess_cumulative_refunds' : 'over_applied_sale_payments';
        $status = $affected ? 'critical' : ($complete ? 'healthy' : 'inconclusive');
        $finding = $this->finding($key, $status, $affected ? 'critical' : ($complete ? 'info' : 'warning'), $affected,
            $affected ? $baseExposure : null, $samples, ['missing_rate_records' => $rateMissing,
                'limitations' => $complete ? [] : ['One or more source, payment, return, or currency records exceeded the bounded scan or lacked an authoritative rate.']],
            'quick', null, $complete, $complete);
        $finding['affected_amounts']['base_currency'] = ['amount' => $affected ? $baseExposure : null,
            'status' => $complete ? 'exact' : 'partial'];
        $finding['affected_amounts']['transaction_currency'] = $currencyExposure;
        $finding['affected_amounts']['excluded_records'] = $rateMissing;
        $finding['affected_amounts']['exclusions'] = $rateMissing ? ['missing_or_invalid_transaction_time_rate' => $rateMissing] : [];
        return $finding;
    }

    private function baseAmount($amount, $currencyId, $exchangeRate): string
    {
        return app(\App\Services\Accounting\CurrencyNormalizationService::class)->normalize($amount,
            $currencyId ? (int) $currencyId : null, $exchangeRate);
    }

    private function payment_to_journal_disagreement(AccountingConfig $config): array
    {
        $active = DB::table('journal_entries as je')->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->where('je.source_type', Payment::class)->whereNull('je.related_journal_entry_id')
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)->from('journal_entries as rev')->whereColumn('rev.related_journal_entry_id', 'je.id');
                $this->boundary($q, 'rev.id', 'journal_entries');
            })
            ->groupBy('je.source_id')->selectRaw('je.source_id, COUNT(DISTINCT je.id) AS active_count, COALESCE(SUM(jl.debit),0) AS active_debit');
        $this->boundary($active, 'je.id', 'journal_entries');
        if (array_key_exists('journal_lines', $this->datasetBoundary)) {
            $active->where(fn ($q) => $q->whereNull('jl.id')->orWhere('jl.id', '<=', (int) $this->datasetBoundary['journal_lines']));
        }
        $payments = DB::table('payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoinSub($active, 'aj', 'aj.source_id', '=', 'p.id')->where($this->eligibleDate('p', $config));
        $this->boundary($payments, 'p.id', 'payments');
        $this->warehouse($payments, 's.warehouse_id');
        $samples = []; $affected = 0;
        $rows = $payments->select('p.*', 's.reference_no as sale_reference', 's.warehouse_id', 'aj.active_count', 'aj.active_debit')->orderBy('p.id')->limit(self::SCAN_LIMIT + 1)->get();
        $complete = $rows->count() <= self::SCAN_LIMIT;
        foreach ($rows->take(self::SCAN_LIMIT) as $payment) {
            if (in_array($payment->accounting_status, $this->inactiveStatuses(), true)) continue;
            $reason = null;
            $activeCount = (int) ($payment->active_count ?? 0);
            if ($activeCount === 0 && $payment->accounting_status === 'posted') $reason = 'missing_active_journal';
            elseif ($activeCount > 1) $reason = 'multiple_active_journals';
            elseif ($activeCount === 1) {
                $base = app(\App\Services\Accounting\CurrencyNormalizationService::class)
                    ->normalize($payment->amount, $payment->currency_id, $payment->exchange_rate);
                if (bccomp($base, (string) $payment->active_debit, 4) !== 0) $reason = 'journal_amount_mismatch';
            }
            if ($reason) { $affected++; $this->sample($samples, $payment, ['warehouse_id' => $payment->warehouse_id, 'reason' => $reason]); }
        }
        return $this->finding(__FUNCTION__, $affected ? 'critical' : ($complete ? 'healthy' : 'partial'), $affected ? 'critical' : 'info', $affected, null, $samples,
            $complete ? [] : ['limitations' => ['Source scan reached the quick-scan record ceiling; run a deep scan.']], 'quick', null, $complete, false);
    }

    private function orphaned_return_refund_journals(AccountingConfig $config): array
    {
        $samples = []; $affected = 0;
        foreach ([[Returns::class, 'returns'], [Payment::class, 'payments']] as [$type, $table]) {
            $query = $this->activeJournalQuery()->where('je.source_type', $type)
                ->leftJoin("{$table} as src", 'src.id', '=', 'je.source_id')->whereNull('src.id')
                ->select(['je.id', 'je.source_id', 'je.event_type', 'je.warehouse_id']);
            $this->warehouse($query, 'je.warehouse_id');
            $affected += (clone $query)->count();
            foreach ($query->orderBy('je.id')->limit(self::SAMPLE_LIMIT)->get() as $row) $this->sample($samples, $row, ['reason' => 'active_missing_source', 'source_type' => $type]);
        }
        $postedReturns = DB::table('returns as r')->where('r.accounting_status', 'posted')->where($this->eligibleDate('r', $config))
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)->from('journal_entries as je')->whereColumn('je.source_id', 'r.id')->where('je.source_type', Returns::class)
                    ->whereNull('je.related_journal_entry_id')->whereNotExists(function ($rev) {
                        $rev->selectRaw(1)->from('journal_entries as reversal')->whereColumn('reversal.related_journal_entry_id', 'je.id');
                        $this->boundary($rev, 'reversal.id', 'journal_entries');
                    });
                $this->boundary($q, 'je.id', 'journal_entries');
            });
        $this->boundary($postedReturns, 'r.id', 'returns');
        $this->warehouse($postedReturns, 'r.warehouse_id');
        $affected += (clone $postedReturns)->count();
        foreach ($postedReturns->select(['r.id', 'r.reference_no', 'r.warehouse_id'])->orderBy('r.id')->limit(self::SAMPLE_LIMIT)->get() as $row) {
            $this->sample($samples, $row, ['reason' => 'posted_source_missing_active_journal', 'source_type' => Returns::class]);
        }
        return $this->finding(__FUNCTION__, $affected ? 'critical' : 'healthy', $affected ? 'critical' : 'info', $affected, null, $samples);
    }

    private function historical_sale_deletion_stock_indicators(AccountingConfig $config): array
    {
        $returnEvidence = $this->activeJournalQuery()->where('je.source_type', Returns::class)
            ->leftJoin('returns as r', 'r.id', '=', 'je.source_id')->leftJoin('sales as s', 's.id', '=', 'r.sale_id')
            ->where(fn ($q) => $q->whereNull('r.id')->orWhereNull('s.id'));
        $this->warehouse($returnEvidence, 'je.warehouse_id');
        $refundEvidence = $this->activeJournalQuery()->where('je.source_type', Payment::class)
            ->where('je.event_type', 'like', 'sale_refund%')->leftJoin('payments as p', 'p.id', '=', 'je.source_id')
            ->leftJoin('sales as s', 's.id', '=', 'p.sale_id')->where(fn ($q) => $q->whereNull('p.id')->orWhereNull('s.id'));
        $this->warehouse($refundEvidence, 'je.warehouse_id');
        $orphans = $returnEvidence->count() + $refundEvidence->count();
        return $this->finding(__FUNCTION__, $orphans ? 'inconclusive' : 'healthy', $orphans ? 'warning' : 'info', $orphans, null, [], [
            'reason' => $orphans ? 'retained journals exist but deleted stock movement cannot be reconstructed safely' : 'no retained evidence',
        ]);
    }

    private function suspicious_exchange_rates(AccountingConfig $config): array
    {
        $base = app(\App\Services\Accounting\CurrencyNormalizationService::class)->getBaseCurrencyId();
        $samples = []; $affected = 0;
        foreach ([['sales', 's'], ['purchases', 'p'], ['returns', 'r'], ['return_purchases', 'rp']] as [$table, $alias]) {
            $query = DB::table("{$table} as {$alias}")->whereNotNull("{$alias}.currency_id")
                ->where("{$alias}.currency_id", '!=', $base)
                ->where(fn ($q) => $q->whereNull("{$alias}.exchange_rate")->orWhere("{$alias}.exchange_rate", '<=', 0))
                ->selectRaw("{$alias}.id, {$alias}.currency_id, {$alias}.exchange_rate");
            $this->boundary($query, "{$alias}.id", $table);
            $this->warehouse($query, "{$alias}.warehouse_id");
            $affected += (clone $query)->count();
            foreach ($query->orderBy("{$alias}.id")->limit(self::SAMPLE_LIMIT)->get() as $row) $this->sample($samples, $row, ['source_table' => $table, 'reason' => 'invalid_foreign_rate']);
        }
        $invalidPayments = DB::table('payments as pay')->leftJoin('sales as ps', 'ps.id', '=', 'pay.sale_id')
            ->leftJoin('purchases as pp', 'pp.id', '=', 'pay.purchase_id')->whereNotNull('pay.currency_id')->where('pay.currency_id', '!=', $base)
            ->where(fn ($q) => $q->whereNull('pay.exchange_rate')->orWhere('pay.exchange_rate', '<=', 0));
        $this->boundary($invalidPayments, 'pay.id', 'payments');
        $effectiveWarehouseId = $this->forcedWarehouseId ?: (app(WarehouseAccessService::class)->isRestricted()
            ? app(WarehouseAccessService::class)->warehouseId() : null);
        if ($effectiveWarehouseId) $invalidPayments->whereRaw('COALESCE(ps.warehouse_id, pp.warehouse_id) = ?', [$effectiveWarehouseId]);
        $affected += (clone $invalidPayments)->count();
        foreach ($invalidPayments->selectRaw('pay.id, pay.currency_id, pay.exchange_rate, COALESCE(ps.warehouse_id, pp.warehouse_id) warehouse_id')
            ->orderBy('pay.id')->limit(self::SAMPLE_LIMIT)->get() as $row) {
            $this->sample($samples, $row, ['source_table' => 'payments', 'reason' => 'invalid_foreign_rate']);
        }
        $mismatch = DB::table('payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->whereColumn('p.currency_id', 's.currency_id')->whereRaw('ROUND(p.exchange_rate,8) <> ROUND(s.exchange_rate,8)');
        $this->boundary($mismatch, 'p.id', 'payments');
        $this->warehouse($mismatch, 's.warehouse_id');
        $affected += (clone $mismatch)->count();
        foreach ($mismatch->select(['p.id', 's.warehouse_id', 'p.exchange_rate'])->orderBy('p.id')->limit(self::SAMPLE_LIMIT)->get() as $row) {
            $this->sample($samples, $row, ['reason' => 'parent_payment_rate_mismatch']);
        }
        return $this->finding(__FUNCTION__, $affected ? 'needs_review' : 'healthy', $affected ? 'warning' : 'info', $affected, null, $samples);
    }

    private function unsafe_complex_sale_deletion_exposure(AccountingConfig $config): array
    {
        $query = $this->saleScope(DB::table('sales as s'))->where($this->eligibleDate('s', $config))
            ->where(function ($q) {
                $q->whereExists(function ($x) {
                    $x->selectRaw(1)->from('returns as r')->whereColumn('r.sale_id', 's.id');
                    $this->boundary($x, 'r.id', 'returns');
                })->orWhereExists(function ($x) {
                    $x->selectRaw(1)->from('payments as p')->whereColumn('p.sale_id', 's.id');
                    $this->boundary($x, 'p.id', 'payments');
                })->orWhereExists(function ($x) {
                    $x->selectRaw(1)->from('product_sales as ps')->join('products as pr', 'pr.id', '=', 'ps.product_id')
                        ->whereColumn('ps.sale_id', 's.id')->where(fn ($z) => $z->where('pr.type', 'combo')->orWhere('pr.is_imei', true));
                    $this->boundary($x, 'ps.id', 'product_sales');
                    $this->boundary($x, 'pr.id', 'products');
                });
            })->select(['s.id', 's.reference_no', 's.warehouse_id'])->orderBy('s.id')->limit(self::SAMPLE_LIMIT);
        $this->boundary($query, 's.id', 'sales');
        $count = (clone $query)->reorder()->limit(null)->count();
        $samples = $query->get()->map(fn ($row) => $this->safeRecord($row, ['reason' => 'protected_by_sale_deletion_guard']))->all();
        return $this->finding(__FUNCTION__, $count ? 'warning' : 'healthy', 'info', $count, null, $samples, ['informational' => true]);
    }

    private function activeJournals(string $type, int $id)
    {
        return JournalEntry::where('source_type', $type)->where('source_id', $id)
            ->whereNull('related_journal_entry_id')->whereNotExists(fn ($q) => $q->selectRaw(1)->from('journal_entries as rev')->whereColumn('rev.related_journal_entry_id', 'journal_entries.id'));
    }

    private function activeJournalQuery(): Builder
    {
        $query = DB::table('journal_entries as je')->whereNull('je.related_journal_entry_id')
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)->from('journal_entries as rev')->whereColumn('rev.related_journal_entry_id', 'je.id');
                $this->boundary($q, 'rev.id', 'journal_entries');
            });
        $this->boundary($query, 'je.id', 'journal_entries');
        return $query;
    }

    private function effectivePayments(int $saleId, bool $refund)
    {
        return Payment::where('sale_id', $saleId)->when($refund, fn ($q) => $q->whereNotNull('return_id'), fn ($q) => $q->whereNull('return_id'))
            ->whereNotIn('accounting_status', $this->inactiveStatuses());
    }

    private function eligibleDate(string $alias, AccountingConfig $config): \Closure
    {
        return fn ($q) => $q->where("{$alias}.created_at", '>=', $config->cutover_at ?: $config->start_date ?: '1970-01-01');
    }

    private function saleScope(Builder $query): Builder { $this->warehouse($query, 's.warehouse_id'); return $query; }
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
    private function inactiveStatuses(): array { return ['reversed', 'voided', 'failed']; }
    private function keys(): array { return ['excess_cumulative_refunds', 'over_applied_sale_payments', 'payment_to_journal_disagreement', 'orphaned_return_refund_journals', 'historical_sale_deletion_stock_indicators', 'suspicious_exchange_rates', 'unsafe_complex_sale_deletion_exposure']; }
    private function sample(array &$samples, object $row, array $extra = []): void { if (count($samples) < self::SAMPLE_LIMIT) $samples[] = $this->safeRecord($row, $extra); }
    private function safeRecord(object $row, array $extra = []): array
    {
        return array_filter(array_merge(['id' => (int) $row->id, 'reference' => $row->reference_no ?? null, 'warehouse_id' => isset($row->warehouse_id) ? (int) $row->warehouse_id : null], $extra), fn ($v) => $v !== null);
    }
    private function finding(string $key, string $status, string $severity, int $count, ?string $amount, array $samples, array $evidence = [], string $mode = 'quick', ?\DateTimeInterface $cutoff = null, bool $countExact = true, bool $amountExact = true): array
    {
        $status = match ($status) {
            'warning' => 'needs_review',
            'partial' => 'inconclusive',
            default => $status,
        };
        if (!$countExact && $status === 'healthy') $status = 'inconclusive';
        $sampleCount = count($samples);
        $checkKey = 'f012.'.$key;
        $fingerprint = hash('sha256', json_encode(['check_key' => $checkKey, 'version' => self::DETECTOR_VERSION,
            'sample_limit' => self::SAMPLE_LIMIT, 'scan_limit' => self::SCAN_LIMIT], JSON_UNESCAPED_SLASHES));
        $repairUnavailable = $status === 'healthy' || $status === 'not_applicable'
            ? ['No correction is required.']
            : ['No evidence-safe automated repair is certified for this detector.'];
        if ($status === 'inconclusive') $repairUnavailable = ['Authoritative historical evidence is incomplete or the scan boundary was not exhaustive.'];
        if ($status === 'scan_failed') $repairUnavailable = ['The detector failed; rerun it successfully before considering any correction.'];
        $warehouseId = $this->forcedWarehouseId ?: app(WarehouseAccessService::class)->warehouseId();
        return ['check_key' => $checkKey, 'detector_version' => self::DETECTOR_VERSION,
            'detector_fingerprint' => $fingerprint, 'status' => $status, 'severity' => $severity,
            'scope' => $warehouseId ? 'warehouse' : 'global',
            'warehouse_id' => $warehouseId, 'affected_count' => $count,
            'authoritative_affected_record_count' => $countExact ? $count : null, 'affected_amount' => $amount, 'currency' => 'base',
            'count_is_exact' => $countExact, 'sample_count' => $sampleCount, 'sample_limit' => self::SAMPLE_LIMIT,
            'sample_truncated' => $countExact ? $count > $sampleCount : $sampleCount >= self::SAMPLE_LIMIT,
            'scan_mode' => $mode, 'scan_status' => $status === 'scan_failed' ? 'scan_failed' : ($status === 'inconclusive' ? 'completed_with_warnings' : 'completed'),
            'scan_scope' => $warehouseId ? 'warehouse' : 'global', 'cutoff' => ($cutoff ?: now())->format(DATE_ATOM),
            'dataset_high_water_mark' => $this->datasetBoundary,
            'affected_amounts' => ['transaction_currency' => [], 'base_currency' => ['amount' => $amount, 'status' => $amount === null ? 'unavailable' : ($amountExact ? 'exact' : 'partial')],
                'included_records' => $amount === null ? 0 : $count, 'excluded_records' => 0, 'exclusions' => []],
            'amount_is_exact' => $amountExact, 'limitations' => $evidence['limitations'] ?? [],
            'summary' => __('db.accounting_health_check_'.$key.'_summary'), 'plain_language_explanation' => __('db.accounting_health_check_'.$key.'_summary'),
            'guidance' => __('db.accounting_health_guidance'), 'recommended_next_action' => __('db.accounting_health_guidance'),
            'sample_records' => array_slice($samples, 0, self::SAMPLE_LIMIT), 'technical_evidence' => $evidence,
            'scanned_at' => now()->toIso8601String(), 'repair_available' => false,
            'repair_unavailable_reasons' => $repairUnavailable];
    }

    private function captureBoundary(): array
    {
        return collect(['accounting_configs', 'currencies', 'sales', 'purchases', 'returns', 'return_purchases',
            'payments', 'products', 'product_sales', 'journal_entries', 'journal_lines'])
            ->filter(fn ($table) => \Illuminate\Support\Facades\Schema::hasTable($table))
            ->mapWithKeys(fn ($table) => [$table => (int) DB::table($table)->max('id')])->all();
    }
}
