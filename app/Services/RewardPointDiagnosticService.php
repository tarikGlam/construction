<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Read-only, bounded F-012 loyalty and void-integrity diagnostics. */
class RewardPointDiagnosticService
{
    private const SAMPLE_LIMIT = 25;
    private const DETECTOR_VERSION = '3.0.0';

    public function scan(): array
    {
        abort_if(app(WarehouseAccessService::class)->isPortalIdentity(), 403);

        return [
            'reward_missing_award' => $this->missingAwards(),
            'reward_duplicate_award' => $this->duplicateAwards(),
            'reward_balance_disagreement' => $this->balanceDisagreement(),
            'reward_redemption_integrity' => $this->redemptionIntegrity(),
            'reward_payment_cash_misclassification' => $this->cashMisclassification(),
            'reward_payment_ar_integrity' => $this->arSettlementIntegrity(),
            'reward_payment_restoration_integrity' => $this->paymentRestorationIntegrity(),
            'void_financial_integrity' => $this->voidFinancialIntegrity(),
            'void_stored_value_integrity' => $this->voidStoredValueIntegrity(),
            'void_imei_integrity' => $this->voidImeiIntegrity(),
        ];
    }

    private function missingAwards(): array
    {
        $setting = DB::table('reward_point_settings')->orderByDesc('id')->first();
        if (!$setting || !$setting->is_active || (float) $setting->per_point_amount <= 0) {
            return $this->finding(__FUNCTION__, 0, [], 'not_applicable');
        }
        $query = DB::table('sales as s')->join('customers as c', 'c.id', '=', 's.customer_id')
            ->whereNull('s.deleted_at')->whereIn('s.sale_status', [1, 5, 6])->where('c.type', '!=', 'walkin')
            ->whereRaw('s.grand_total / COALESCE(NULLIF(s.exchange_rate,0),1) >= ?', [(float) $setting->minimum_amount])
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('reward_points as rp')->whereColumn('rp.sale_id', 's.id')->where('rp.event_type', 'sale_earned'));
        $this->warehouse($query, 's.warehouse_id');
        return $this->fromQuery(__FUNCTION__, $query, ['s.id', 's.reference_no', 's.warehouse_id'], 'eligible_sale_has_no_award_event');
    }

    private function duplicateAwards(): array
    {
        $query = DB::table('reward_points as rp')->join('sales as s', 's.id', '=', 'rp.sale_id')
            ->where('rp.event_type', 'sale_earned')->groupBy('s.id', 's.reference_no', 's.warehouse_id')
            ->havingRaw('COUNT(*) > 1')->selectRaw('s.id, s.reference_no, s.warehouse_id, COUNT(*) AS event_count');
        $this->warehouse($query, 's.warehouse_id');
        return $this->fromSelectedQuery(__FUNCTION__, $query, 'duplicate_sale_earned_events');
    }

    private function balanceDisagreement(): array
    {
        $ledger = DB::table('reward_points')->groupBy('customer_id')
            ->selectRaw('customer_id, ROUND(SUM(points-deducted_points),4) AS ledger_balance');
        $query = DB::table('customers as c')->leftJoinSub($ledger, 'rp', 'rp.customer_id', '=', 'c.id')
            ->whereRaw('ROUND(COALESCE(c.points,0),4) <> ROUND(COALESCE(rp.ledger_balance,0),4)')
            ->selectRaw('c.id, COALESCE(c.points,0) customer_balance, COALESCE(rp.ledger_balance,0) ledger_balance');
        $access = app(WarehouseAccessService::class);
        if ($access->isRestricted()) {
            $warehouseId = $access->warehouseId() ?: -1;
            $query->whereExists(function ($sale) use ($warehouseId) {
                $sale->selectRaw(1)->from('sales as scoped_sale')
                    ->whereColumn('scoped_sale.customer_id', 'c.id')
                    ->where('scoped_sale.warehouse_id', $warehouseId);
            });
        }
        return $this->fromSelectedQuery(__FUNCTION__, $query, 'customer_balance_differs_from_reward_ledger');
    }

    private function redemptionIntegrity(): array
    {
        $events = DB::table('reward_points')->whereNotNull('payment_id')->groupBy('payment_id')
            ->selectRaw('payment_id, SUM(deducted_points-points) AS net_consumed');
        $query = DB::table('payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoinSub($events, 'rp', 'rp.payment_id', '=', 'p.id')->where('p.paying_method', 'Points')
            ->where(fn ($active) => $active->whereNull('p.accounting_status')
                ->orWhereNotIn('p.accounting_status', ['reversed', 'voided']))
            ->where(fn ($q) => $q->whereNull('rp.payment_id')->orWhereRaw('ROUND(COALESCE(p.used_points,0),4) <> ROUND(COALESCE(rp.net_consumed,0),4)'))
            ->selectRaw('p.id, p.payment_reference AS reference_no, s.warehouse_id, p.used_points, COALESCE(rp.net_consumed,0) net_consumed');
        $this->warehouse($query, 's.warehouse_id');
        return $this->fromSelectedQuery(__FUNCTION__, $query, 'point_payment_missing_or_disagrees_with_ledger');
    }

    private function cashMisclassification(): array
    {
        $query = DB::table('payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->join('journal_entries as je', function ($join) {
                $join->on('je.source_id', '=', 'p.id')->where('je.source_type', Payment::class)->whereNull('je.related_journal_entry_id');
            })->join('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'jl.accounting_account_id')
            ->where('p.paying_method', 'Points')->where('aa.is_cash_account', true)->where('jl.debit', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('journal_entries as rev')->whereColumn('rev.related_journal_entry_id', 'je.id'))
            ->select(['p.id', 'p.payment_reference as reference_no', 's.warehouse_id']);
        $this->warehouse($query, 's.warehouse_id');
        return $this->fromSelectedQuery(__FUNCTION__, $query, 'reward_payment_debited_cash_or_bank');
    }

    private function arSettlementIntegrity(): array
    {
        $query = DB::table('payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('p.paying_method', 'Points')
            ->where(fn ($active) => $active->whereNull('p.accounting_status')
                ->orWhereNotIn('p.accounting_status', ['reversed', 'voided']))
            ->whereNotExists(function ($journal) {
                $journal->selectRaw(1)->from('journal_entries as je')
                    ->whereColumn('je.source_id', 'p.id')
                    ->where('je.source_type', Payment::class)
                    ->where('je.source_subtype', 'reward_point_redemption')
                    ->whereNull('je.related_journal_entry_id')
                    ->whereNotExists(fn ($reversal) => $reversal->selectRaw(1)->from('journal_entries as reversed')
                        ->whereColumn('reversed.related_journal_entry_id', 'je.id'));
            })
            ->select(['p.id', 'p.payment_reference as reference_no', 's.warehouse_id']);
        $this->warehouse($query, 's.warehouse_id');
        return $this->fromSelectedQuery(__FUNCTION__, $query, 'reward_payment_has_no_active_ar_settlement_journal');
    }

    private function paymentRestorationIntegrity(): array
    {
        $events = DB::table('reward_points')->whereNotNull('payment_id')->groupBy('payment_id')
            ->selectRaw('payment_id, SUM(deducted_points-points) AS net_consumed, MIN(customer_id) AS customer_id');
        $query = DB::query()->fromSub($events, 'rp')
            ->leftJoin('payments as p', 'p.id', '=', 'rp.payment_id')
            ->leftJoin('sales as s', 's.id', '=', 'p.sale_id')
            ->where('rp.net_consumed', '>', 0)
            ->where(function ($state) {
                $state->whereNull('p.id')
                    ->orWhereIn('p.accounting_status', ['reversed', 'voided'])
                    ->orWhereNotNull('s.voided_at');
            })
            ->selectRaw('rp.payment_id AS id, p.payment_reference AS reference_no, s.warehouse_id');
        $this->warehouse($query, 's.warehouse_id');
        return $this->fromSelectedQuery(__FUNCTION__, $query, 'reversed_or_missing_reward_payment_still_consumes_points');
    }

    private function voidFinancialIntegrity(): array
    {
        $query = DB::table('sales as s')->whereNotNull('s.voided_at')->where(function ($q) {
            $q->whereExists(fn ($x) => $x->selectRaw(1)->from('payments as p')->whereColumn('p.sale_id', 's.id')
                ->where(fn ($active) => $active->whereNull('p.accounting_status')->orWhereNotIn('p.accounting_status', ['reversed', 'voided'])))
                ->orWhereExists(function ($x) {
                    $x->selectRaw(1)->from('journal_entries as je')->whereColumn('je.source_id', 's.id')->where('je.source_type', Sale::class)
                        ->whereNull('je.related_journal_entry_id')->whereNotExists(fn ($rev) => $rev->selectRaw(1)->from('journal_entries as r')->whereColumn('r.related_journal_entry_id', 'je.id'));
                })->orWhereExists(function ($x) {
                    $x->selectRaw(1)->from('reward_points as rp')->whereColumn('rp.sale_id', 's.id')
                        ->groupBy('rp.sale_id')->havingRaw("SUM(CASE WHEN event_type IN ('sale_earned','sale_earning_adjusted','return_earning_reversed','sale_void_earning_reversed') THEN points-deducted_points ELSE 0 END) > 0");
                });
        });
        $this->warehouse($query, 's.warehouse_id');
        return $this->fromQuery(__FUNCTION__, $query, ['s.id', 's.reference_no', 's.warehouse_id'], 'void_has_active_financial_or_reward_effect');
    }

    private function voidStoredValueIntegrity(): array
    {
        $query = DB::table('sales as s')->whereNotNull('s.voided_at')
            ->whereExists(function ($payment) {
                $payment->selectRaw(1)->from('payments as p')
                    ->whereColumn('p.sale_id', 's.id')
                    ->whereIn('p.paying_method', ['Deposit', 'Gift Card', 'Points'])
                    ->where(function ($state) {
                        $state->whereNull('p.accounting_status')
                            ->orWhereNotIn('p.accounting_status', ['reversed', 'voided'])
                            ->orWhere(function ($points) {
                                $points->where('p.paying_method', 'Points')->where('p.used_points', '>', 0);
                            });
                    });
            });
        $this->warehouse($query, 's.warehouse_id');
        return $this->fromQuery(__FUNCTION__, $query, ['s.id', 's.reference_no', 's.warehouse_id'], 'void_has_unresolved_deposit_gift_card_or_reward_effect');
    }

    private function voidImeiIntegrity(): array
    {
        $query = DB::table('sales as s')->join('product_sales as ps', 'ps.sale_id', '=', 's.id')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('product_warehouse as pw', function ($join) {
                $join->on('pw.product_id', '=', 'ps.product_id')->on('pw.warehouse_id', '=', 's.warehouse_id')
                    ->whereRaw('COALESCE(pw.variant_id,0)=COALESCE(ps.variant_id,0)')
                    ->whereRaw('COALESCE(pw.product_batch_id,0)=COALESCE(ps.product_batch_id,0)');
            })->whereNotNull('s.voided_at')->where('p.is_imei', true)
            ->where(function ($q) {
                $q->whereNull('pw.id')->orWhereNull('ps.imei_number')->orWhereRaw("TRIM(ps.imei_number) = ''")
                    ->orWhereRaw("FIND_IN_SET(REPLACE(TRIM(ps.imei_number),' ',''), REPLACE(COALESCE(pw.imei_number,''),' ','')) = 0");
            })->select(['s.id', 's.reference_no', 's.warehouse_id']);
        $this->warehouse($query, 's.warehouse_id');
        return $this->fromSelectedQuery(__FUNCTION__, $query, 'voided_imei_sale_stock_evidence_disagrees_or_is_inconclusive');
    }

    private function fromQuery(string $key, $query, array $select, string $reason): array
    {
        return $this->fromSelectedQuery($key, (clone $query)->select($select), $reason);
    }

    private function fromSelectedQuery(string $key, $query, string $reason): array
    {
        $count = DB::query()->fromSub(clone $query, 'affected')->count();
        $samples = (clone $query)->limit(self::SAMPLE_LIMIT)->get()->map(fn ($row) => array_filter([
            'id' => (int) $row->id,
            'reference' => $row->reference_no ?? null,
            'warehouse_id' => isset($row->warehouse_id) ? (int) $row->warehouse_id : null,
            'reason' => $reason,
        ], fn ($value) => $value !== null))->all();
        return $this->finding($key, $count, $samples);
    }

    private function warehouse($query, string $column): void
    {
        $access = app(WarehouseAccessService::class);
        if ($access->isRestricted()) {
            $query->where($column, $access->warehouseId() ?: -1);
        }
    }

    private function finding(string $key, int $count, array $samples, ?string $status = null): array
    {
        $key = Str::snake($key);
        $status ??= $count ? 'needs_review' : 'healthy';
        $status = match ($status) { 'warning' => 'needs_review', 'partial' => 'inconclusive', default => $status };
        $checkKey = 'f012.' . $key;
        $fingerprint = hash('sha256', json_encode(['check_key' => $checkKey, 'version' => self::DETECTOR_VERSION,
            'sample_limit' => self::SAMPLE_LIMIT], JSON_UNESCAPED_SLASHES));
        return [
            'check_key' => $checkKey,
            'detector_version' => self::DETECTOR_VERSION,
            'detector_fingerprint' => $fingerprint,
            'status' => $status,
            'severity' => $count ? 'warning' : 'info',
            'scope' => app(WarehouseAccessService::class)->isRestricted() ? 'warehouse' : 'global',
            'warehouse_id' => app(WarehouseAccessService::class)->warehouseId(),
            'affected_count' => $count,
            'authoritative_affected_record_count' => $count,
            'affected_amount' => null,
            'count_is_exact' => true,
            'sample_count' => count($samples),
            'sample_limit' => self::SAMPLE_LIMIT,
            'sample_truncated' => $count > count($samples),
            'scan_mode' => 'quick',
            'scan_status' => 'completed',
            'scan_scope' => app(WarehouseAccessService::class)->isRestricted() ? 'warehouse' : 'global',
            'cutoff' => now()->format(DATE_ATOM),
            'dataset_high_water_mark' => collect(['sales', 'payments', 'reward_points', 'journal_entries', 'journal_lines'])
                ->filter(fn ($table) => \Illuminate\Support\Facades\Schema::hasTable($table))
                ->mapWithKeys(fn ($table) => [$table => (int) DB::table($table)->max('id')])->all(),
            'affected_amounts' => ['transaction_currency' => [], 'base_currency' => ['amount' => null, 'status' => 'unavailable'], 'included_records' => 0, 'excluded_records' => 0, 'exclusions' => []],
            'amount_is_exact' => false,
            'limitations' => ['Reward settings are mutable; missing-award eligibility is evaluated under the latest stored policy. CSV IMEI checks are exact only for one serial per sale line.'],
            'summary' => __('db.accounting_health_check_' . $key . '_summary'),
            'plain_language_explanation' => __('db.accounting_health_check_' . $key . '_summary'),
            'guidance' => __('db.accounting_health_guidance'),
            'recommended_next_action' => __('db.accounting_health_guidance'),
            'sample_records' => $samples,
            'technical_evidence' => ['read_only' => true],
            'scanned_at' => now()->toIso8601String(),
            'repair_available' => false,
            'repair_unavailable_reasons' => [$status === 'inconclusive'
                ? 'Authoritative reward history is incomplete.'
                : 'No evidence-safe automatic reward correction is certified for this finding.'],
        ];
    }
}
