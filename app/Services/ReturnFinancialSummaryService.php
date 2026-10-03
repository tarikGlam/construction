<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Support\Collection;

class ReturnFinancialSummaryService
{
    /**
     * Build financial summary and detailed linked returns list for a Sale.
     *
     * @param Sale $sale
     * @param Collection|array|null $returns Preloaded returns collection or array or null
     * @return array
     */
    public function forSale(Sale $sale, $returns = null): array
    {
        if (is_array($returns)) {
            $returns = collect($returns);
        } elseif ($returns === null) {
            $returns = $sale->relationLoaded('returns')
                ? $sale->returns
                : $sale->returns()->with(['refundPayments', 'products.product', 'products.variant', 'products.unit'])->get();
        }

        $originalGrandTotal = (float) $sale->grand_total;
        $grossPaidAmount = (float) $sale->paid_amount;
        $exchangeRate = (float) ($sale->exchange_rate ?: 1);

        $totalReturnedAmount = 0.0;
        $totalRefundedAmount = 0.0;
        $returnsList = [];

        foreach ($returns as $ret) {
            $retTotal = (float) ($ret->grand_total ?? 0);
            $totalReturnedAmount += $retTotal;

            $refunds = $ret->relationLoaded('refundPayments')
                ? $ret->refundPayments
                : $ret->refundPayments()->get();

            $retRefunded = 0.0;
            if ($refunds) {
                foreach ($refunds as $rf) {
                    $retRefunded += (float) ($rf->amount ?? 0);
                }
            }
            $totalRefundedAmount += $retRefunded;

            // Product lines for this return
            $items = [];
            $productReturns = $ret->relationLoaded('products')
                ? $ret->products
                : $ret->products()->with(['product', 'variant', 'unit'])->get();

            if ($productReturns) {
                foreach ($productReturns as $pr) {
                    $productName = $pr->product ? $pr->product->name : 'Product';
                    $variantName = $pr->variant ? $pr->variant->name : null;
                    $unitCode = $pr->unit ? $pr->unit->unit_code : ($pr->saleUnit ? $pr->saleUnit->unit_code : '');

                    $items[] = [
                        'product_name' => $productName,
                        'variant_name' => $variantName,
                        'qty'          => (float) ($pr->qty ?? 0),
                        'unit_code'    => $unitCode,
                        'total'        => (float) ($pr->total ?? 0),
                    ];
                }
            }

            $returnsList[] = [
                'id'              => $ret->id,
                'reference_no'    => $ret->reference_no,
                'date'            => $ret->created_at ? date(config('date_format', 'd-m-Y'), strtotime($ret->created_at)) : '',
                'returned_amount' => $retTotal,
                'refunded_amount' => $retRefunded,
                'items'           => $items,
            ];
        }

        $netGrandTotal = max(0.0, $originalGrandTotal - $totalReturnedAmount);
        $netPaidAmount = max(0.0, $grossPaidAmount - $totalRefundedAmount);
        $nonRefundedReturnAmount = max(0.0, $totalReturnedAmount - $totalRefundedAmount);
        $remainingDue = max(0.0, $originalGrandTotal - $grossPaidAmount - $nonRefundedReturnAmount);

        return [
            'has_returns'           => count($returnsList) > 0,
            'returns'               => $returnsList,
            'original_grand_total'  => $originalGrandTotal,
            'total_returned_amount' => $totalReturnedAmount,
            'net_grand_total'       => $netGrandTotal,
            'gross_paid_amount'     => $grossPaidAmount,
            'total_refunded_amount' => $totalRefundedAmount,
            'net_paid_amount'       => $netPaidAmount,
            'return_due_offset'     => $nonRefundedReturnAmount,
            'remaining_due'         => $remainingDue,
            'exchange_rate'         => $exchangeRate,
        ];
    }

    /**
     * Build financial summary and detailed linked returns list for a Purchase.
     *
     * @param Purchase $purchase
     * @param Collection|array|null $returns Preloaded return purchases collection or array or null
     * @return array
     */
    public function forPurchase(Purchase $purchase, $returns = null): array
    {
        if (is_array($returns)) {
            $returns = collect($returns);
        } elseif ($returns === null) {
            $returns = $purchase->relationLoaded('returns')
                ? $purchase->returns
                : $purchase->returns()->with(['refundPayments', 'products.product', 'products.variant', 'products.unit'])->get();
        }

        $originalGrandTotal = (float) $purchase->grand_total;
        $grossPaidAmount = (float) $purchase->paid_amount;
        $exchangeRate = (float) ($purchase->exchange_rate ?: 1);

        $totalReturnedAmount = 0.0;
        $totalRefundedAmount = 0.0;
        $returnsList = [];

        foreach ($returns as $ret) {
            $retTotal = (float) ($ret->grand_total ?? 0);
            $totalReturnedAmount += $retTotal;

            $refunds = $ret->relationLoaded('refundPayments')
                ? $ret->refundPayments
                : $ret->refundPayments()->get();

            $retRefunded = (float) $refunds->sum(fn ($p) => abs((float) $p->amount));
            $totalRefundedAmount += $retRefunded;

            $items = [];
            if ($ret->relationLoaded('products')) {
                foreach ($ret->products as $pr) {
                    $prodName = $pr->product?->name ?? 'Product';
                    $varName = $pr->variant?->name ?? '';
                    $unitCode = $pr->unit?->unit_code ?? '';
                    $items[] = [
                        'product_id' => $pr->product_id,
                        'product_name' => $prodName,
                        'variant_name' => $varName,
                        'qty' => (float) $pr->qty,
                        'unit_code' => $unitCode,
                        'unit_cost' => (float) ($pr->net_unit_cost ?? 0),
                        'discount' => (float) $pr->discount,
                        'tax' => (float) $pr->tax,
                        'total' => (float) $pr->total,
                    ];
                }
            }

            $returnsList[] = [
                'id' => $ret->id,
                'reference_no' => $ret->reference_no,
                'date' => $ret->created_at ? $ret->created_at->format('d-m-Y') : '',
                'created_at' => $ret->created_at ? $ret->created_at->toDateTimeString() : '',
                'returned_amount' => $retTotal,
                'refunded_amount' => $retRefunded,
                'return_note' => $ret->return_note ?? '',
                'items' => $items,
            ];
        }

        $netGrandTotal = max(0.0, $originalGrandTotal - $totalReturnedAmount);
        $netPaidAmount = max(0.0, $grossPaidAmount - $totalRefundedAmount);
        $returnDueOffset = max(0.0, $totalReturnedAmount - $totalRefundedAmount);
        $remainingDue = max(0.0, $netGrandTotal - $netPaidAmount);

        return [
            'original_grand_total' => $originalGrandTotal,
            'total_returned_amount' => $totalReturnedAmount,
            'net_grand_total' => $netGrandTotal,
            'gross_paid_amount' => $grossPaidAmount,
            'total_refunded_amount' => $totalRefundedAmount,
            'net_paid_amount' => $netPaidAmount,
            'return_due_offset' => $returnDueOffset,
            'remaining_due' => $remainingDue,
            'exchange_rate' => $exchangeRate,
            'has_returns' => $totalReturnedAmount > 0,
            'returns' => $returnsList,
        ];
    }
}
