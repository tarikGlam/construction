<?php

namespace App\Services\Accounting;

use App\Exceptions\CurrencyRateResolutionException;
use App\Exceptions\MissingCurrencyMetadataException;
use App\Models\MoneyTransfer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\ReturnPurchase;
use App\Models\Returns;
use App\Models\Sale;

class CurrencyRateResolver
{
    public function __construct(
        private CurrencyNormalizationService $normalizationService
    ) {}

    /**
     * Resolve currency ID and exchange rate for a Sale.
     *
     * @return array{currency_id: int, exchange_rate: string}
     */
    public function resolveForSale(Sale $sale): array
    {
        return $this->resolveFromDirectAttributes(
            $sale->currency_id,
            $sale->exchange_rate,
            "Sale #{$sale->reference_no}"
        );
    }

    /**
     * Resolve currency ID and exchange rate for a Purchase.
     *
     * @return array{currency_id: int, exchange_rate: string}
     */
    public function resolveForPurchase(Purchase $purchase): array
    {
        return $this->resolveFromDirectAttributes(
            $purchase->currency_id,
            $purchase->exchange_rate,
            "Purchase #{$purchase->reference_no}"
        );
    }

    /**
     * Resolve currency ID and exchange rate for a Payment.
     * Checks payment's own recorded attributes first.
     * If absent, resolves from the authoritative parent transaction.
     *
     * @return array{currency_id: int, exchange_rate: string}
     */
    public function resolveForPayment(Payment $payment): array
    {
        $baseCurrencyId = $this->normalizationService->getBaseCurrencyId();

        // 1. Payment's own explicit currency and rate
        if (!empty($payment->currency_id)) {
            $paymentRate = (string) ($payment->exchange_rate ?? '');
            if ($paymentRate !== '' && bccomp($paymentRate, '0', 8) > 0) {
                // If payment's own rate is positive, it is authoritative
                return [
                    'currency_id' => (int) $payment->currency_id,
                    'exchange_rate' => $paymentRate,
                ];
            }
        }

        // 2. Authoritative parent fallback
        if (!empty($payment->sale_id)) {
            $sale = Sale::find($payment->sale_id);
            if ($sale) {
                return $this->resolveForSale($sale);
            }
        }

        if (!empty($payment->purchase_id)) {
            $purchase = Purchase::find($payment->purchase_id);
            if ($purchase) {
                return $this->resolveForPurchase($purchase);
            }
        }

        if (!empty($payment->return_id)) {
            $return = Returns::find($payment->return_id);
            if ($return) {
                return $this->resolveForReturn($return);
            }
        }

        if (!empty($payment->purchase_return_id)) {
            $returnPurchase = ReturnPurchase::find($payment->purchase_return_id);
            if ($returnPurchase) {
                return $this->resolveForReturnPurchase($returnPurchase);
            }
        }

        // If payment explicitly specifies currency_id as base currency with no parent
        if (isset($payment->currency_id) && (int) $payment->currency_id === $baseCurrencyId) {
            return [
                'currency_id' => $baseCurrencyId,
                'exchange_rate' => '1.00000000',
            ];
        }

        throw new MissingCurrencyMetadataException(
            "Unable to authoritatively resolve currency metadata for Payment #{$payment->id}."
        );
    }

    /**
     * Resolve currency ID and exchange rate for a Sale Return.
     *
     * @return array{currency_id: int, exchange_rate: string}
     */
    public function resolveForReturn(Returns $return): array
    {
        // If Return has its own positive rate and currency
        if (!empty($return->currency_id) && !empty($return->exchange_rate) && bccomp((string)$return->exchange_rate, '0', 8) > 0) {
            return [
                'currency_id' => (int) $return->currency_id,
                'exchange_rate' => (string) $return->exchange_rate,
            ];
        }

        // Fall back to parent Sale
        if (!empty($return->sale_id)) {
            $sale = Sale::find($return->sale_id);
            if ($sale) {
                return $this->resolveForSale($sale);
            }
        }

        return $this->resolveFromDirectAttributes(
            $return->currency_id,
            $return->exchange_rate,
            "Sale Return #{$return->reference_no}"
        );
    }

    /**
     * Resolve currency ID and exchange rate for a Purchase Return.
     *
     * @return array{currency_id: int, exchange_rate: string}
     */
    public function resolveForReturnPurchase(ReturnPurchase $returnPurchase): array
    {
        if (!empty($returnPurchase->currency_id) && !empty($returnPurchase->exchange_rate) && bccomp((string)$returnPurchase->exchange_rate, '0', 8) > 0) {
            return [
                'currency_id' => (int) $returnPurchase->currency_id,
                'exchange_rate' => (string) $returnPurchase->exchange_rate,
            ];
        }

        if (!empty($returnPurchase->purchase_id)) {
            $purchase = Purchase::find($returnPurchase->purchase_id);
            if ($purchase) {
                return $this->resolveForPurchase($purchase);
            }
        }

        return $this->resolveFromDirectAttributes(
            $returnPurchase->currency_id,
            $returnPurchase->exchange_rate,
            "Purchase Return #{$returnPurchase->reference_no}"
        );
    }

    /**
     * Resolve currency ID and exchange rate for a Money Transfer.
     *
     * @return array{currency_id: int, exchange_rate: string}
     */
    public function resolveForMoneyTransfer(MoneyTransfer $transfer): array
    {
        return $this->resolveFromDirectAttributes(
            $transfer->currency_id,
            $transfer->exchange_rate,
            "Money Transfer #{$transfer->reference_no}"
        );
    }

    /**
     * Helper to resolve direct attributes and validate.
     *
     * @return array{currency_id: int, exchange_rate: string}
     */
    private function resolveFromDirectAttributes($currencyId, $exchangeRate, string $context): array
    {
        if ($currencyId === null) {
            throw new MissingCurrencyMetadataException("Currency ID is missing for {$context}.");
        }

        $baseCurrencyId = $this->normalizationService->getBaseCurrencyId();

        if ((int) $currencyId === $baseCurrencyId) {
            return [
                'currency_id' => $baseCurrencyId,
                'exchange_rate' => '1.00000000',
            ];
        }

        if ($exchangeRate === null || $exchangeRate === '' || bccomp((string) $exchangeRate, '0', 8) <= 0) {
            throw new CurrencyRateResolutionException("Exchange rate for {$context} is missing or invalid: '{$exchangeRate}'.");
        }

        return [
            'currency_id' => (int) $currencyId,
            'exchange_rate' => (string) $exchangeRate,
        ];
    }
}
