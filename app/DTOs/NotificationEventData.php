<?php

namespace App\DTOs;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Transfer;

class NotificationEventData
{
    public function __construct(
        public readonly string $event,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly string $eventVersion,
        public readonly ?string $reference = null,
        public readonly ?float $amount = null,
        public readonly array $products = [],
        public readonly ?float $totalQty = null,
        public readonly ?int $warehouseId = null,
        public readonly ?int $fromWarehouseId = null,
        public readonly ?int $toWarehouseId = null,
        public readonly ?Customer $customer = null,
        public readonly ?Supplier $supplier = null,
        public readonly ?float $previousQty = null,
        public readonly ?string $date = null,
        public readonly array $extra = []
    ) {}

    public static function forSale(Sale $sale, array $products = [], ?float $totalQty = null): self
    {
        $customer = $sale->customer ?? Customer::find($sale->customer_id);

        return new self(
            event: 'sale_created',
            subjectType: Sale::class,
            subjectId: (int) $sale->id,
            eventVersion: 'created_v1',
            reference: $sale->reference_no,
            amount: (float) $sale->grand_total,
            products: $products,
            totalQty: $totalQty ?? (float) ($sale->total_qty ?? 0),
            warehouseId: (int) $sale->warehouse_id,
            customer: $customer,
            date: $sale->created_at ? $sale->created_at->toDateString() : date('Y-m-d')
        );
    }

    public static function forPayment(Sale $sale, Payment $payment, ?Customer $customer = null): self
    {
        $customer ??= $sale->customer ?? Customer::find($sale->customer_id);

        return new self(
            event: 'payment_received',
            subjectType: Payment::class,
            subjectId: (int) $payment->id,
            eventVersion: 'payment_' . $payment->id,
            reference: $sale->reference_no,
            amount: (float) $payment->amount,
            products: [],
            totalQty: null,
            warehouseId: (int) $sale->warehouse_id,
            customer: $customer,
            date: $payment->created_at ? $payment->created_at->toDateString() : date('Y-m-d'),
            extra: [
                'sale_id' => $sale->id,
                'payment_reference' => $payment->payment_reference ?? null,
            ]
        );
    }

    public static function forPurchase(Purchase $purchase, array $products = [], ?float $totalQty = null): self
    {
        $supplier = $purchase->supplier ?? Supplier::find($purchase->supplier_id);

        return new self(
            event: 'purchase_created',
            subjectType: Purchase::class,
            subjectId: (int) $purchase->id,
            eventVersion: 'created_v1',
            reference: $purchase->reference_no,
            amount: (float) $purchase->grand_total,
            products: $products,
            totalQty: $totalQty ?? (float) ($purchase->total_qty ?? 0),
            warehouseId: (int) $purchase->warehouse_id,
            supplier: $supplier,
            date: $purchase->created_at ? $purchase->created_at->toDateString() : date('Y-m-d')
        );
    }

    public static function forQuotation(Quotation $quotation, array $products = [], ?float $totalQty = null): self
    {
        $customer = $quotation->customer ?? Customer::find($quotation->customer_id);

        return new self(
            event: 'quotation_created',
            subjectType: Quotation::class,
            subjectId: (int) $quotation->id,
            eventVersion: 'created_v1',
            reference: $quotation->reference_no,
            amount: (float) $quotation->grand_total,
            products: $products,
            totalQty: $totalQty ?? (float) ($quotation->total_qty ?? 0),
            warehouseId: (int) $quotation->warehouse_id,
            customer: $customer,
            date: $quotation->created_at ? $quotation->created_at->toDateString() : date('Y-m-d')
        );
    }

    public static function forLowStock(
        Product $product,
        float $currentQty,
        float $previousQty,
        int $warehouseId,
        string $crossingVersion
    ): self {
        return new self(
            event: 'low_stock',
            subjectType: Product::class,
            subjectId: (int) $product->id,
            eventVersion: $crossingVersion,
            reference: $product->code,
            amount: null,
            products: [['name' => $product->name, 'qty' => $currentQty, 'alert_qty' => $product->alert_quantity]],
            totalQty: $currentQty,
            warehouseId: $warehouseId,
            previousQty: $previousQty,
            date: date('Y-m-d'),
            extra: [
                'product_name' => $product->name,
                'alert_quantity' => $product->alert_quantity,
            ]
        );
    }

    public static function forTransfer(Transfer $transfer, array $products = [], ?float $totalQty = null): self
    {
        return new self(
            event: 'stock_transfer',
            subjectType: Transfer::class,
            subjectId: (int) $transfer->id,
            eventVersion: 'created_v1',
            reference: $transfer->reference_no,
            amount: (float) ($transfer->grand_total ?? $transfer->total_cost ?? 0),
            products: $products,
            totalQty: $totalQty ?? (float) ($transfer->total_qty ?? 0),
            fromWarehouseId: (int) $transfer->from_warehouse_id,
            toWarehouseId: (int) $transfer->to_warehouse_id,
            date: $transfer->created_at ? $transfer->created_at->toDateString() : date('Y-m-d')
        );
    }

    public static function forExpiry(Product $product, string $windowDate, ?string $batchInfo = null): self
    {
        return new self(
            event: 'expiry_alert',
            subjectType: Product::class,
            subjectId: (int) $product->id,
            eventVersion: 'exp_' . $windowDate,
            reference: $product->code,
            products: [['name' => $product->name, 'batch' => $batchInfo]],
            date: date('Y-m-d'),
            extra: [
                'window_date' => $windowDate,
                'product_name' => $product->name,
            ]
        );
    }

    public static function forBatchExpiry(ProductBatch $batch, Product $product, string $windowDate): self
    {
        return new self(
            event: 'expiry_alert',
            subjectType: ProductBatch::class,
            subjectId: (int) $batch->id,
            eventVersion: 'exp_' . $windowDate . '_' . $batch->expired_date,
            reference: $product->code,
            products: [['name' => $product->name, 'batch' => $batch->batch_no, 'expiry_date' => $batch->expired_date]],
            date: date('Y-m-d'),
            extra: [
                'window_date' => $windowDate,
                'product_name' => $product->name,
                'batch_no' => $batch->batch_no,
                'expiry_date' => $batch->expired_date,
            ]
        );
    }
}
