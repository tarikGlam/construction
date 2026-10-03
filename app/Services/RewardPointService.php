<?php

namespace App\Services;

use App\Enums\RewardPointTypeEnum;
use App\Exceptions\SaleValidationException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Returns;
use App\Models\RewardPoint;
use App\Models\RewardPointSetting;
use App\Models\Sale;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative operational ledger for SalePro loyalty points.
 *
 * Points are integer units. Currency is normalized to the base currency before
 * earning thresholds and rates are applied. Every automatic balance movement
 * is an immutable, uniquely-keyed event; customers.points is the locked,
 * materialized balance.
 */
class RewardPointService
{
    private const ELIGIBLE_SALE_STATUSES = [1, 5, 6];
    private const SCALE = 4;

    public function reconcileSale(Sale $sale): ?RewardPoint
    {
        return DB::transaction(function () use ($sale) {
            $sale = Sale::withoutGlobalScopes()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
            $events = RewardPoint::where('sale_id', $sale->id)
                ->whereIn('event_type', ['sale_earned', 'sale_earning_adjusted'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $setting = $events->first()?->reward_point_setting_id
                ? RewardPointSetting::whereKey($events->first()->reward_point_setting_id)->first()
                : RewardPointSetting::latest('id')->first();

            $target = $this->earnedPoints($sale, $customer, $setting);
            $current = (int) round($events->sum(fn (RewardPoint $event) => (float) $event->points - (float) $event->deducted_points));
            $delta = $target - $current;

            if ($delta === 0) {
                return $events->last();
            }

            $sequence = $events->count() + 1;
            $eventType = $events->isEmpty() ? 'sale_earned' : 'sale_earning_adjusted';
            $event = $this->record(
                $customer,
                [
                    'reward_point_type' => RewardPointTypeEnum::AUTOMATIC->value,
                    'event_type' => $eventType,
                    'event_key' => "sale:{$sale->id}:earning:{$sequence}",
                    'sale_id' => $sale->id,
                    'points' => max(0, $delta),
                    'deducted_points' => max(0, -$delta),
                    'source_amount' => $this->baseAmount($sale->grand_total, $sale->exchange_rate),
                    'conversion_rate' => $events->first()?->conversion_rate ?? $setting?->redeem_amount_per_unit_rp,
                    'reward_point_setting_id' => $setting?->id,
                    'expired_at' => $delta > 0 ? $this->expiry($sale->created_at, $setting) : null,
                    'note' => $events->isEmpty()
                        ? "Earned for Sale {$sale->reference_no}"
                        : "Earning adjusted for Sale {$sale->reference_no}",
                ],
                $delta,
            );

            $liabilityRate = (string) ($events->first()?->conversion_rate ?? $setting?->redeem_amount_per_unit_rp ?? 0);
            if (bccomp($liabilityRate, '0', 8) > 0) {
                $result = app(AccountingService::class)->recordPointsAdjustment(
                    $sale,
                    $delta,
                    bcmul((string) abs($delta), $liabilityRate, self::SCALE),
                    "reward_earning_{$sequence}",
                );
                if (!$result->success) {
                    throw new SaleValidationException($result->error ?: 'Reward-point accounting could not be posted.');
                }
            }

            return $event;
        });
    }

    public function redeemPayment(Payment $payment, Sale $sale): RewardPoint
    {
        $this->sweepExpiredPoints((int) $sale->customer_id);

        return DB::transaction(function () use ($payment, $sale) {
            $sale = Sale::withoutGlobalScopes()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
            $events = RewardPoint::where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();

            $setting = $this->activeSetting();
            $rate = (string) $setting->redeem_amount_per_unit_rp;
            if (bccomp($rate, '0', 8) <= 0) {
                throw new SaleValidationException('Reward-point redemption rate must be greater than zero.');
            }
            if (bccomp($this->baseAmount($sale->grand_total, $sale->exchange_rate), (string) ($setting->min_order_total_for_redeem ?? 0), self::SCALE) < 0) {
                throw new SaleValidationException('This sale does not meet the minimum total for reward-point redemption.');
            }

            $basePaymentAmount = $this->baseAmount($payment->amount, $payment->exchange_rate);
            $otherPayments = Payment::where('sale_id', $sale->id)
                ->where('id', '!=', $payment->id)
                ->whereNull('return_id')
                ->where(fn ($active) => $active->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $otherPaid = $otherPayments->reduce(
                fn (string $sum, Payment $other): string => bcadd(
                    $sum,
                    $this->baseAmount($other->amount, $other->exchange_rate),
                    self::SCALE,
                ),
                '0',
            );
            $remaining = max(
                0,
                (float) bcsub($this->baseAmount($sale->grand_total, $sale->exchange_rate), $otherPaid, self::SCALE),
            );
            if ((float) $basePaymentAmount > $remaining + 0.0001) {
                throw new SaleValidationException('Reward-point redemption cannot exceed the sale amount still due.');
            }
            $points = (int) ceil((float) bcdiv($basePaymentAmount, $rate, 8));
            if ($points <= 0) {
                throw new SaleValidationException('Reward-point payment must be greater than zero.');
            }
            if ($points < (int) ($setting->min_redeem_point ?? 0)) {
                throw new SaleValidationException('The minimum reward-point redemption has not been met.');
            }
            if ((int) ($setting->max_redeem_point ?? 0) > 0 && $points > (int) $setting->max_redeem_point) {
                throw new SaleValidationException('The maximum reward-point redemption for one sale has been exceeded.');
            }
            $currentlyConsumed = (int) round($events->sum(fn (RewardPoint $event) => (float) $event->deducted_points - (float) $event->points));
            $delta = $points - $currentlyConsumed;
            if ($delta === 0) {
                $payment->used_points = $points;
                $payment->save();
                return $events->last();
            }
            if ($delta > 0 && bccomp((string) ($customer->points ?? 0), (string) $delta, self::SCALE) < 0) {
                throw new SaleValidationException('The customer does not have enough reward points.');
            }

            $payment->used_points = $points;
            $payment->save();

            return $this->record($customer, [
                'reward_point_type' => RewardPointTypeEnum::AUTOMATIC->value,
                'event_type' => $events->isEmpty() ? 'payment_redeemed' : 'payment_redemption_adjusted',
                'event_key' => "payment:{$payment->id}:reward:" . ($events->count() + 1),
                'sale_id' => $sale->id,
                'payment_id' => $payment->id,
                'points' => max(0, -$delta),
                'deducted_points' => max(0, $delta),
                'source_amount' => $basePaymentAmount,
                'conversion_rate' => $rate,
                'reward_point_setting_id' => $setting->id,
                'note' => "Redeemed for Payment {$payment->payment_reference}",
            ], -$delta);
        });
    }

    public function restorePayment(Payment $payment, Sale $sale): ?RewardPoint
    {
        return DB::transaction(function () use ($payment, $sale) {
            $sale = Sale::withoutGlobalScopes()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
            $events = RewardPoint::where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
            $points = (int) round($events->sum(fn (RewardPoint $event) => (float) $event->deducted_points - (float) $event->points));
            if ($points <= 0 && $events->isEmpty()) {
                $points = (int) ($payment->used_points ?: 0);
            }
            if ($points <= 0) {
                return null;
            }

            $original = $events->first();
            $payment->used_points = 0;
            $payment->save();

            return $this->record($customer, [
                'reward_point_type' => RewardPointTypeEnum::AUTOMATIC->value,
                'event_type' => 'payment_restored',
                'event_key' => "payment:{$payment->id}:reward:" . ($events->count() + 1),
                'sale_id' => $sale->id,
                'payment_id' => $payment->id,
                'points' => $points,
                'deducted_points' => 0,
                'source_amount' => $original?->source_amount ?? $this->baseAmount($payment->amount, $payment->exchange_rate),
                'conversion_rate' => $original?->conversion_rate,
                'reward_point_setting_id' => $original?->reward_point_setting_id,
                'note' => "Restored after reversing Payment {$payment->payment_reference}",
            ], $points);
        });
    }

    public function reconcileReturn(Returns $return): ?RewardPoint
    {
        return DB::transaction(function () use ($return) {
            $return = Returns::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $sale = Sale::withoutGlobalScopes()->whereKey($return->sale_id)->lockForUpdate()->firstOrFail();
            $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
            if (RewardPoint::where('event_key', "return:{$return->id}:earning-reversal")->exists()) {
                return RewardPoint::where('event_key', "return:{$return->id}:earning-reversal")->first();
            }

            $earned = (int) RewardPoint::where('sale_id', $sale->id)
                ->whereIn('event_type', ['sale_earned', 'sale_earning_adjusted'])
                ->selectRaw('COALESCE(SUM(points - deducted_points), 0) AS net_points')
                ->value('net_points');
            if ($earned <= 0) {
                return null;
            }

            $allReturnBase = $this->baseAmount(
                Returns::where('sale_id', $sale->id)->sum('grand_total'),
                $sale->exchange_rate,
            );
            $saleBase = $this->baseAmount($sale->grand_total, $sale->exchange_rate);
            $target = bccomp($saleBase, '0', self::SCALE) > 0
                ? min($earned, (int) floor($earned * min(1, (float) bcdiv($allReturnBase, $saleBase, 8))))
                : 0;
            $already = (int) RewardPoint::where('sale_id', $sale->id)
                ->whereIn('event_type', ['return_earning_reversed', 'points_expired'])
                ->sum('deducted_points');
            $points = max(0, $target - $already);
            if ($points === 0) {
                return null;
            }

            $original = RewardPoint::where('sale_id', $sale->id)
                ->whereIn('event_type', ['sale_earned', 'sale_earning_adjusted'])
                ->orderBy('id')
                ->first();
            $event = $this->record($customer, [
                'reward_point_type' => RewardPointTypeEnum::AUTOMATIC->value,
                'event_type' => 'return_earning_reversed',
                'event_key' => "return:{$return->id}:earning-reversal",
                'sale_id' => $sale->id,
                'return_id' => $return->id,
                'points' => 0,
                'deducted_points' => $points,
                'source_amount' => $this->baseAmount($return->grand_total, $return->exchange_rate),
                'conversion_rate' => $original?->conversion_rate,
                'reward_point_setting_id' => $original?->reward_point_setting_id,
                'note' => "Earned points reversed for Return {$return->reference_no}",
            ], -$points);

            $liabilityRate = (string) ($original?->conversion_rate ?? 0);
            if (bccomp($liabilityRate, '0', 8) > 0) {
                $result = app(AccountingService::class)->recordPointsAdjustment(
                    $sale,
                    -$points,
                    bcmul((string) $points, $liabilityRate, self::SCALE),
                    "reward_return_{$return->id}",
                );
                if (!$result->success) {
                    throw new SaleValidationException($result->error ?: 'Returned reward-point accounting could not be posted.');
                }
            }

            return $event;
        });
    }

    public function reverseSaleEarningForVoid(Sale $sale): ?RewardPoint
    {
        return $this->reverseSaleEarning(
            $sale,
            'sale_void_earning_reversed',
            "sale:{$sale->id}:void:earning-reversal",
            "Earned points reversed because Sale {$sale->reference_no} was voided",
        );
    }

    public function reverseSaleEarningForDeletion(Sale $sale): ?RewardPoint
    {
        return $this->reverseSaleEarning(
            $sale,
            'sale_delete_earning_reversed',
            "sale:{$sale->id}:delete:earning-reversal",
            "Earned points reversed because Sale {$sale->reference_no} was deleted",
        );
    }

    private function reverseSaleEarning(Sale $sale, string $eventType, string $eventKey, string $note): ?RewardPoint
    {
        return DB::transaction(function () use ($sale, $eventType, $eventKey, $note) {
            $sale = Sale::withoutGlobalScopes()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
            $events = RewardPoint::where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get();
            if ($events->contains('event_type', $eventType)) {
                return $events->firstWhere('event_type', $eventType);
            }
            $earned = (int) round($events
                ->whereIn('event_type', ['sale_earned', 'sale_earning_adjusted'])
                ->sum(fn (RewardPoint $event) => (float) $event->points - (float) $event->deducted_points));
            $reversed = (int) round($events
                ->whereIn('event_type', ['return_earning_reversed', 'points_expired'])
                ->sum('deducted_points'));
            $points = max(0, $earned - $reversed);
            if ($points === 0) {
                return null;
            }

            return $this->record($customer, [
                'reward_point_type' => RewardPointTypeEnum::AUTOMATIC->value,
                'event_type' => $eventType,
                'event_key' => $eventKey,
                'sale_id' => $sale->id,
                'points' => 0,
                'deducted_points' => $points,
                'source_amount' => $events->firstWhere('event_type', 'sale_earned')?->source_amount,
                'conversion_rate' => $events->firstWhere('event_type', 'sale_earned')?->conversion_rate,
                'reward_point_setting_id' => $events->firstWhere('event_type', 'sale_earned')?->reward_point_setting_id,
                'note' => $note,
            ], -$points);
        });
    }

    private function earnedPoints(Sale $sale, Customer $customer, ?RewardPointSetting $setting): int
    {
        if (!$setting || !$setting->is_active || $sale->deleted_at || $sale->voided_at
            || strtolower((string) $customer->type) === 'walkin') {
            return 0;
        }
        if (!in_array((int) $sale->sale_status, self::ELIGIBLE_SALE_STATUSES, true)) {
            return 0;
        }
        $rate = (string) $setting->per_point_amount;
        $base = $this->baseAmount($sale->grand_total, $sale->exchange_rate);
        if (bccomp($rate, '0', 8) <= 0 || bccomp($base, (string) $setting->minimum_amount, self::SCALE) < 0) {
            return 0;
        }

        return max(0, (int) floor((float) bcdiv($base, $rate, 8)));
    }

    private function activeSetting(): RewardPointSetting
    {
        $setting = RewardPointSetting::latest('id')->first();
        if (!$setting || !$setting->is_active) {
            throw new SaleValidationException('Reward-point redemption is not active.');
        }
        return $setting;
    }

    public function sweepExpiredPoints(int $customerId): void
    {
        DB::transaction(function () use ($customerId): void {
            $customer = Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            $this->expireCustomerPoints($customer);
        });
    }

    private function expireCustomerPoints(Customer $customer): void
    {
        $events = RewardPoint::where('customer_id', $customer->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $buckets = [];
        foreach ($events as $event) {
            $points = (int) round((float) $event->points);
            if ($points > 0) {
                $buckets[] = ['event' => $event, 'remaining' => $points];
            }
            $deduction = (int) round((float) $event->deducted_points);
            foreach ($buckets as &$bucket) {
                if ($deduction <= 0) break;
                $applied = min($deduction, $bucket['remaining']);
                $bucket['remaining'] -= $applied;
                $deduction -= $applied;
            }
            unset($bucket);
        }

        foreach ($buckets as $bucket) {
            /** @var RewardPoint $source */
            $source = $bucket['event'];
            $points = (int) $bucket['remaining'];
            if ($points <= 0 || !$source->expired_at || $source->expired_at->isFuture()) {
                continue;
            }
            $key = "reward:{$source->id}:expired";
            if ($events->contains('event_key', $key)) {
                continue;
            }

            $this->record($customer, [
                'reward_point_type' => RewardPointTypeEnum::AUTOMATIC->value,
                'event_type' => 'points_expired',
                'event_key' => $key,
                'sale_id' => $source->sale_id,
                'points' => 0,
                'deducted_points' => $points,
                'source_amount' => $source->source_amount,
                'conversion_rate' => $source->conversion_rate,
                'reward_point_setting_id' => $source->reward_point_setting_id,
                'note' => "Expired reward event {$source->id}",
            ], -$points);

            $liabilityRate = (string) ($source->conversion_rate ?? 0);
            if ($source->sale_id && bccomp($liabilityRate, '0', 8) > 0) {
                $sale = Sale::withoutGlobalScopes()->whereKey($source->sale_id)->first();
                if ($sale) {
                    $result = app(AccountingService::class)->recordPointsAdjustment(
                        $sale,
                        -$points,
                        bcmul((string) $points, $liabilityRate, self::SCALE),
                        "reward_expired_{$source->id}",
                    );
                    if (!$result->success) {
                        throw new SaleValidationException($result->error ?: 'Expired reward-point accounting could not be posted.');
                    }
                }
            }
        }
    }

    private function record(Customer $customer, array $attributes, int $delta): RewardPoint
    {
        $balance = bcadd((string) ($customer->points ?? 0), (string) $delta, self::SCALE);
        $customer->points = $balance;
        $customer->save();
        $attributes['customer_id'] = $customer->id;
        $attributes['balance_after'] = $balance;
        return RewardPoint::create($attributes);
    }

    private function baseAmount(mixed $amount, mixed $exchangeRate): string
    {
        $rate = (string) (($exchangeRate ?? 1) ?: 1);
        if (bccomp($rate, '0', 8) <= 0) {
            throw new SaleValidationException('A valid exchange rate is required for reward points.');
        }
        return bcdiv((string) ($amount ?? 0), $rate, self::SCALE);
    }

    private function expiry(?CarbonInterface $earnedAt, ?RewardPointSetting $setting): ?CarbonInterface
    {
        if (!$setting || !$setting->duration || !$setting->type) {
            return null;
        }
        $at = ($earnedAt ?: now())->copy();
        return match ($setting->type) {
            'days' => $at->addDays($setting->duration),
            'months' => $at->addMonthsNoOverflow($setting->duration),
            'years' => $at->addYearsNoOverflow($setting->duration),
            default => null,
        };
    }
}
