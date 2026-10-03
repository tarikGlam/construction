<?php

namespace Modules\IndiaGST\Services\Calculation;

use App\Models\Purchase;
use Illuminate\Validation\ValidationException;
use Modules\IndiaGST\Entities\IndiaGstPurchaseSnapshot;

class IndiaGstFinalizedPurchaseGuard
{
    public function guard(Purchase $purchase, array $payload = []): void
    {
        $snapshotCount = IndiaGstPurchaseSnapshot::where('purchase_id', $purchase->id)->count();
        if ($snapshotCount === 0) {
            return;
        }

        $hasLineChanges = isset($payload['product_id']) || isset($payload['qty']) || isset($payload['net_unit_cost']);
        $hasHeaderTaxChanges = isset($payload['supplier_id']) || isset($payload['warehouse_id']) || isset($payload['order_discount']);

        if ($hasLineChanges || $hasHeaderTaxChanges) {
            throw ValidationException::withMessages([
                'india_gst' => 'Cannot modify tax or line items of a finalized India GST purchase.'
            ]);
        }
    }

    public function guardDelete(Purchase $purchase): void
    {
        $snapshotCount = IndiaGstPurchaseSnapshot::where('purchase_id', $purchase->id)->count();
        if ($snapshotCount > 0) {
            throw ValidationException::withMessages([
                'india_gst' => 'Cannot delete a finalized India GST purchase.'
            ]);
        }
    }
}
