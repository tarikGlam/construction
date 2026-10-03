<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Returns;
use App\Models\Sale;
use App\Services\Accounting\CurrencyNormalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Sale row is the serialization point for receipts and refunds. */
class SalePaymentIntegrity
{
    public function financialChanged(Payment $payment): bool
    {
        foreach (['amount', 'exchange_rate'] as $field) {
            $scale = $field === 'exchange_rate' ? 8 : 4;
            if (bccomp((string) ($payment->getRawOriginal($field) ?? 0), (string) ($payment->$field ?? 0), $scale) !== 0) return true;
        }
        foreach (['paying_method', 'account_id', 'currency_id'] as $field) {
            if ((string) $payment->getRawOriginal($field) !== (string) $payment->$field) return true;
        }
        return $payment->isDirty('payment_at');
    }

    public function money(mixed $value): string
    {
        try {
            return app(CurrencyNormalizationService::class)->normalizeBaseAmount($value);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['amount' => __('integrity.payment_amount')]);
        }
    }

    public function lockSale(int $id): Sale
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Payment validation requires a transaction.');
        }
        $sale = Sale::whereKey($id)->lockForUpdate()->firstOrFail();
        app(WarehouseAccessService::class)->authorizeWarehouse((int) $sale->warehouse_id);
        return $sale;
    }

    public function validate(Payment $payment, Sale $sale): void
    {
        $amount = $this->money($payment->amount);
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('integrity.payment_amount')]);
        }
        $returns = Returns::where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get();
        $payments = Payment::where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get();
        $collected = $refunded = $allocated = '0.0000';
        foreach ($payments as $existing) {
            if ($existing->id === $payment->id || in_array($existing->accounting_status, ['reversed', 'voided'], true)) continue;
            // GL failure does not mean cash was not collected. Operational
            // payments remain effective until explicitly reversed or removed.
            $base = $this->base($existing->amount, $existing->currency_id ?? $sale->currency_id, $existing->exchange_rate ?? $sale->exchange_rate);
            if ($existing->return_id) {
                $refunded = bcadd($refunded, $base, 4);
                if ((int) $existing->return_id === (int) $payment->return_id) $allocated = bcadd($allocated, $base, 4);
            } else {
                $collected = bcadd($collected, $base, 4);
            }
        }
        $proposed = $this->base($amount, $payment->currency_id ?? $sale->currency_id, $payment->exchange_rate ?? $sale->exchange_rate);
        $returned = '0.0000';
        foreach ($returns as $return) {
            if ($return->accounting_status !== 'reversed') {
                $returned = bcadd($returned, $this->base($return->grand_total, $return->currency_id, $return->exchange_rate), 4);
            }
        }
        if ($payment->return_id) {
            $current = $returns->firstWhere('id', $payment->return_id);
            if (!$current || $current->accounting_status === 'reversed' || strcasecmp((string) $payment->paying_method, 'Deposit') === 0) {
                throw ValidationException::withMessages(['refund_amount' => __('integrity.refund_amount')]);
            }
            $limits = [
                bcsub($this->base($current->grand_total, $current->currency_id, $current->exchange_rate), $allocated, 4),
                bcsub($returned, $refunded, 4),
                bcsub($collected, $refunded, 4),
            ];
            foreach ($limits as $limit) {
                if (bccomp($proposed, $limit, 4) > 0) throw ValidationException::withMessages(['refund_amount' => __('integrity.refund_amount')]);
            }
        } else {
            if (bccomp(bcadd($collected, $proposed, 4), $refunded, 4) < 0) {
                throw ValidationException::withMessages(['amount' => __('integrity.payment_amount')]);
            }
            $due = bcadd(bcsub(bcsub($this->base($sale->grand_total, $sale->currency_id, $sale->exchange_rate), $returned, 4), $collected, 4), $refunded, 4);
            if (bccomp($proposed, $due, 4) > 0 || bccomp($this->money($payment->change ?? 0), '0', 4) < 0
                || (strcasecmp((string) $payment->paying_method, 'Cash') !== 0 && bccomp($this->money($payment->change ?? 0), '0', 4) !== 0)) {
                throw ValidationException::withMessages(['amount' => __('integrity.payment_amount')]);
            }
        }
    }

    private function base(mixed $amount, mixed $currency, mixed $rate): string
    {
        $norm = app(CurrencyNormalizationService::class);
        $currencyId = $currency ? (int) $currency : $norm->getBaseCurrencyId();
        $rate = $rate ?? '1.00000000';
        return $norm->normalize($amount, $currencyId, $rate);
    }

    public function refreshTotals(Sale $sale): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Sale payment totals require a transaction.');
        }
        $total = '0.0000';
        foreach (Payment::where('sale_id', $sale->id)->whereNull('return_id')->orderBy('id')->lockForUpdate()->get() as $payment) {
            if (in_array($payment->accounting_status, ['reversed', 'voided'], true)) continue;
            // paid_amount is in the invoice currency, not the base currency.
            if ((int) ($payment->currency_id ?? $sale->currency_id) === (int) $sale->currency_id) {
                $total = bcadd($total, $this->money($payment->amount), 4);
                continue;
            }
            $base = $this->base($payment->amount, $payment->currency_id ?? $sale->currency_id, $payment->exchange_rate ?? $sale->exchange_rate);
            $rate = (int) $sale->currency_id === app(CurrencyNormalizationService::class)->getBaseCurrencyId() ? '1' : app(TransactionExchangeRate::class)->validate($sale->exchange_rate);
            $total = bcadd($total, bcmul($base, $rate, 4), 4);
        }
        $sale->paid_amount = $total;
        $balance = bcsub($this->money($sale->grand_total), $total, 4);
        $sale->payment_status = bccomp($balance, '0', 4) === 0 ? 4 : (bccomp($total, '0', 4) > 0 ? 2 : 1);
        $sale->save();
    }
}
