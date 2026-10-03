<?php

namespace App\Services;

use App\DTOs\NotificationEventData;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class StockThresholdTransitionService
{
    protected NotificationService $notificationService;

    public function __construct(?NotificationService $notificationService = null)
    {
        $this->notificationService = $notificationService ?? app(NotificationService::class);
    }

    /**
     * Check if an inventory mutation represents a strict downward threshold crossing:
     * - Previous quantity was strictly above the alert threshold (> threshold)
     * - New quantity is at or below the alert threshold (<= threshold)
     *
     * Invariant guarantees:
     * 1. Above threshold -> at/below threshold: notify once.
     * 2. Remaining below: do not repeat (since previousQty was not > threshold).
     * 3. Replenished above -> later recrossing: notifies again on subsequent crossing.
     * 4. Warehouses remain independent.
     * 5. Variants remain independent where stock is variant-specific.
     * 6. Duplicate processing is idempotent.
     * 7. No arbitrary date cooldown suppresses legitimate recrossings.
     *
     * @return ?NotificationEventData The dispatched DTO or null if no crossing occurred.
     */
    public function checkAndDispatch(
        Product $product,
        float $previousQty,
        float $newQty,
        int $warehouseId = 0,
        ?int $variantId = null,
        string $contextType = 'mutation',
        int|string $contextId = 0
    ): ?NotificationEventData {
        // Digital and service items do not track physical stock
        if (in_array($product->type, ['digital', 'service'], true)) {
            return null;
        }

        if ($product->alert_quantity === null || $product->alert_quantity === '') {
            return null;
        }

        $threshold = (float) $product->alert_quantity;

        // Authoritative transition check: strictly above -> at or below
        if ($previousQty > $threshold && $newQty <= $threshold) {
            $versionKey = sprintf(
                'crossing_%s_%s_%d_%d_%d',
                $contextType,
                $contextId,
                $product->id,
                $warehouseId,
                $variantId ?? 0
            );

            $dto = NotificationEventData::forLowStock(
                $product,
                $newQty,
                $previousQty,
                $warehouseId,
                $versionKey
            );

            try {
                $this->notificationService->dispatch($dto);
                return $dto;
            } catch (\Throwable $e) {
                Log::error('Low stock notification dispatch failed: ' . $e->getMessage(), [
                    'product_id'   => $product->id,
                    'warehouse_id' => $warehouseId,
                ]);
            }
        }

        return null;
    }
}
