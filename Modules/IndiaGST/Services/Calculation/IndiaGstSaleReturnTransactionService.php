<?php

namespace Modules\IndiaGST\Services\Calculation;

use App\Models\Returns;
use App\Models\Sale;
use App\Models\ProductReturn;
use Modules\IndiaGST\Entities\IndiaGstSaleSnapshot;
use Modules\IndiaGST\Entities\IndiaGstSaleLineSnapshot;
use Modules\IndiaGST\Entities\IndiaGstReturnSnapshot;
use Modules\IndiaGST\Entities\IndiaGstReturnLineSnapshot;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class IndiaGstSaleReturnTransactionService
{
    public function recordSaleReturnSnapshot(Returns $return, array $returnData): ?IndiaGstReturnSnapshot
    {
        $existing = IndiaGstReturnSnapshot::where('return_id', $return->id)->first();
        if ($existing) {
            return $existing;
        }

        $saleId = $return->sale_id ?? ($returnData['sale_id'] ?? null);
        if (!$saleId) {
            return null;
        }

        $sale = Sale::find($saleId);
        $saleSnapshot = IndiaGstSaleSnapshot::with('lines')->where('sale_id', $saleId)->first();
        if (!$saleSnapshot) {
            return null; // Not a GST sale
        }

        $now = Carbon::now();
        $productReturnsList = ProductReturn::where('return_id', $return->id)->orderBy('id')->get();

        $aggTaxable = 0.0;
        $aggCgst = 0.0;
        $aggSgst = 0.0;
        $aggUtgst = 0.0;
        $aggIgst = 0.0;
        $aggCess = 0.0;
        $aggTotal = 0.0;

        $lineSnapshotsToCreate = [];

        // Check previous returns against this sale to guard against over-return
        $previousReturnSnapshots = IndiaGstReturnSnapshot::with('lines')->where('sale_id', $saleId)->get();

        foreach ($productReturnsList as $prodReturn) {
            $productId = $prodReturn->product_id;
            $qty = (float)$prodReturn->qty;

            // Find original line snapshot
            $saleLineSnapshot = $saleSnapshot->lines->firstWhere('product_id', $productId);
            if (!$saleLineSnapshot) {
                continue;
            }

            $origQty = (float)$saleLineSnapshot->quantity;
            if ($origQty <= 0) continue;

            // Calculate previously returned qty for this product
            $previouslyReturnedQty = 0;
            foreach ($previousReturnSnapshots as $prevRet) {
                foreach ($prevRet->lines as $prevLine) {
                    if ($prevLine->product_id == $productId) {
                        $previouslyReturnedQty += (float)$prevLine->quantity;
                    }
                }
            }

            if (($previouslyReturnedQty + $qty) > ($origQty + 0.0001)) {
                throw ValidationException::withMessages([
                    'india_gst' => "Return quantity ({$qty}) exceeds remaining eligible quantity (" . ($origQty - $previouslyReturnedQty) . ") for Product {$saleLineSnapshot->product_name}."
                ]);
            }

            $ratio = $qty / $origQty;
            $lineTaxable = round((float)$saleLineSnapshot->taxable_value * $ratio, 2);
            $lineCgst = round((float)$saleLineSnapshot->cgst_amount * $ratio, 2);
            $lineSgst = round((float)$saleLineSnapshot->sgst_amount * $ratio, 2);
            $lineUtgst = round((float)$saleLineSnapshot->utgst_amount * $ratio, 2);
            $lineIgst = round((float)$saleLineSnapshot->igst_amount * $ratio, 2);
            $lineCess = round((float)$saleLineSnapshot->cess_amount * $ratio, 2);
            $lineTotal = round((float)$saleLineSnapshot->line_total * $ratio, 2);

            $aggTaxable += $lineTaxable;
            $aggCgst += $lineCgst;
            $aggSgst += $lineSgst;
            $aggUtgst += $lineUtgst;
            $aggIgst += $lineIgst;
            $aggCess += $lineCess;
            $aggTotal += $lineTotal;

            $lineSnapshotsToCreate[] = [
                'product_return_id' => $prodReturn->id,
                'product_id' => $productId,
                'hsn_sac_code' => $saleLineSnapshot->hsn_sac_code,
                'quantity' => $qty,
                'unit_price' => (float)$saleLineSnapshot->unit_price,
                'taxable_value' => $lineTaxable,
                'cgst_rate' => $saleLineSnapshot->cgst_rate,
                'cgst_amount' => $lineCgst,
                'sgst_rate' => $saleLineSnapshot->sgst_rate,
                'sgst_amount' => $lineSgst,
                'utgst_rate' => $saleLineSnapshot->utgst_rate,
                'utgst_amount' => $lineUtgst,
                'igst_rate' => $saleLineSnapshot->igst_rate,
                'igst_amount' => $lineIgst,
                'cess_rate' => $saleLineSnapshot->cess_rate,
                'cess_amount' => $lineCess,
                'line_total' => $lineTotal,
            ];
        }

        $totalTax = $aggCgst + $aggSgst + $aggUtgst + $aggIgst + $aggCess;

        $returnSnapshot = IndiaGstReturnSnapshot::create([
            'return_id' => $return->id,
            'sale_id' => $sale->id,
            'india_gst_sale_snapshot_id' => $saleSnapshot->id,
            'gst_registration_id' => $saleSnapshot->gst_registration_id,
            'adjustment_type' => 'seller_credit_note',
            'note_reference' => $return->reference_no,
            'note_date' => $return->created_at ? $return->created_at->toDateString() : $now->toDateString(),
            'original_invoice_reference' => $saleSnapshot->invoice_reference,
            'original_invoice_date' => $saleSnapshot->invoice_date,
            'financial_year' => $this->getFinancialYear($return->created_at ?? $now),

            'customer_id' => $saleSnapshot->customer_id,
            'customer_name' => $saleSnapshot->customer_name,
            'customer_gstin' => $saleSnapshot->customer_gstin,
            'customer_state_code' => $saleSnapshot->customer_state_code,
            'place_of_supply_state_code' => $saleSnapshot->place_of_supply_state_code,
            'is_inter_state' => $saleSnapshot->is_inter_state,

            'adjusted_taxable_value' => $aggTaxable,
            'adjusted_cgst' => $aggCgst,
            'adjusted_sgst' => $aggSgst,
            'adjusted_utgst' => $aggUtgst,
            'adjusted_igst' => $aggIgst,
            'adjusted_cess' => $aggCess,
            'adjusted_total_tax' => $totalTax,
            'adjusted_grand_total' => $aggTotal,
            'locked_at' => $now,
        ]);

        foreach ($lineSnapshotsToCreate as $lineData) {
            $lineData['india_gst_return_snapshot_id'] = $returnSnapshot->id;
            IndiaGstReturnLineSnapshot::create($lineData);
        }

        return $returnSnapshot;
    }

    private function getFinancialYear(Carbon $date): string
    {
        $year = $date->year;
        if ($date->month < 4) {
            return ($year - 1) . '-' . substr($year, -2);
        }
        return $year . '-' . substr($year + 1, -2);
    }
}
