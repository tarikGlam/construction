<?php

namespace App\Services;

use App\Models\ProductPurchase;
use App\Models\PurchaseProductReturn;
use App\Models\ReturnPurchase;
use Illuminate\Validation\ValidationException;

class PurchaseReturnQuantityService
{
    private const EPSILON = 0.000001;

    public function lockLineById(int $purchaseId, int $purchaseLineId, int $productId): ProductPurchase
    {
        $line = ProductPurchase::query()
            ->whereKey($purchaseLineId)
            ->where('purchase_id', $purchaseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (!$line) {
            throw ValidationException::withMessages([
                'qty' => 'The original purchase line could not be identified for this return.',
            ]);
        }

        return $line;
    }

    public function resolveLine(
        int $purchaseId,
        int $productId,
        int $purchaseUnitId = 0,
        ?int $variantId = null,
        ?int $productBatchId = null
    ): ProductPurchase {
        $query = ProductPurchase::query()
            ->where('purchase_id', $purchaseId)
            ->where('product_id', $productId)
            ->when($purchaseUnitId > 0, fn ($q) => $q->where('purchase_unit_id', $purchaseUnitId))
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId), fn ($q) => $q->whereNull('variant_id'))
            ->when($productBatchId !== null, fn ($q) => $q->where('product_batch_id', $productBatchId), fn ($q) => $q->whereNull('product_batch_id'))
            ->lockForUpdate();

        $matches = $query->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages([
                'qty' => 'The original purchase line is missing or ambiguous; review the purchase before returning stock.',
            ]);
        }

        return $matches->first();
    }

    public function assertCanReturn(ProductPurchase $line, float $requestedQty): void
    {
        if ($requestedQty <= self::EPSILON) {
            throw ValidationException::withMessages([
                'qty' => 'Return quantity must be greater than zero.',
            ]);
        }

        $remaining = $this->remainingQty($line);
        if ($requestedQty - $remaining > self::EPSILON) {
            throw ValidationException::withMessages([
                'qty' => sprintf(
                    'Return quantity %.4f exceeds the remaining returnable quantity %.4f.',
                    $requestedQty,
                    $remaining
                ),
            ]);
        }
    }

    public function assertStockIdentity(
        ProductPurchase $line,
        ?int $variantId,
        ?int $productBatchId
    ): void {
        $lineVariantId = $line->variant_id !== null ? (int) $line->variant_id : null;
        $lineBatchId = $line->product_batch_id !== null ? (int) $line->product_batch_id : null;

        if ($lineVariantId !== $variantId || $lineBatchId !== $productBatchId) {
            throw ValidationException::withMessages([
                'product_purchase_id' => 'The selected variant or batch does not match the original purchase line.',
            ]);
        }
    }

    public function remainingQty(ProductPurchase $line): float
    {
        return round(max(0.0, (float) $line->qty - (float) $line->return_qty), 4);
    }

    public function addReturnedQty(ProductPurchase $line, float $qty): void
    {
        $line->return_qty = round((float) $line->return_qty + $qty, 4);
        $line->save();
    }

    public function removeReturnedQty(ProductPurchase $line, float $qty): void
    {
        $line->return_qty = round(max(0.0, (float) $line->return_qty - $qty), 4);
        $line->save();
    }

    public function releaseReturnLine(ReturnPurchase $return, PurchaseProductReturn $returnLine): void
    {
        // Imported purchases synchronize return_qty from their allocation rows.
        if ($return->purchase?->import_batch_id) {
            return;
        }

        $purchaseLine = $this->resolveLine(
            (int) $return->purchase_id,
            (int) $returnLine->product_id,
            (int) $returnLine->purchase_unit_id,
            $returnLine->variant_id !== null ? (int) $returnLine->variant_id : null,
            $returnLine->product_batch_id !== null ? (int) $returnLine->product_batch_id : null
        );

        $this->removeReturnedQty($purchaseLine, (float) $returnLine->qty);
    }
}
