<?php

namespace Modules\IndiaGST\Services\Calculation;

use App\Models\Sale;
use Illuminate\Validation\ValidationException;
use Modules\IndiaGST\Entities\IndiaGstSaleSnapshot;

class IndiaGstFinalizedSaleGuard
{
    /**
     * Blocks update if the sale has an immutable India GST snapshot and the update affects tax.
     * Note: A true robust check would diff the payload with the snapshot. For Phase 1C, 
     * SalePro's update typically replaces all lines, so we block any update to a finalized GST sale.
     *
     * @param Sale $sale
     * @param array $payload
     * @throws ValidationException
     */
    public function guard(Sale $sale, array $payload = []): void
    {
        $snapshotCount = IndiaGstSaleSnapshot::where('sale_id', $sale->id)->count();

        if ($snapshotCount === 0) {
            return; // Not a finalized GST sale — no restriction applies
        }

        // A GST snapshot is immutable historical data. Once a sale has been
        // captured in a snapshot, edits that would alter tax lines are blocked
        // regardless of whether India GST is currently enabled in config.
        // This prevents silent corruption of filed GST returns.
        $hasLineChanges = isset($payload['product_id']) || isset($payload['qty']) || isset($payload['net_unit_price']);
        $hasHeaderTaxChanges = isset($payload['customer_id']) || isset($payload['warehouse_id']) || isset($payload['order_discount']);

        if ($hasLineChanges || $hasHeaderTaxChanges) {
            throw ValidationException::withMessages([
                'india_gst' => __('indiagst::messages.cannot_update_finalized_gst_sale')
            ]);
        }
    }

    /**
     * Blocks deletion of a finalized GST sale.
     */
    public function guardDelete(Sale $sale): void
    {
        $snapshotCount = IndiaGstSaleSnapshot::where('sale_id', $sale->id)->count();

        if ($snapshotCount > 0) {
            throw ValidationException::withMessages([
                'india_gst' => __('indiagst::messages.cannot_delete_finalized_gst_sale')
            ]);
        }
    }
}
