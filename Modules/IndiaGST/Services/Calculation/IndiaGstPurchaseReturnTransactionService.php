<?php

namespace Modules\IndiaGST\Services\Calculation;

use App\Models\ReturnPurchase;
use App\Models\Purchase;
use App\Models\PurchaseProductReturn;
use Modules\IndiaGST\Entities\IndiaGstPurchaseSnapshot;
use Modules\IndiaGST\Entities\IndiaGstPurchaseLineSnapshot;
use Modules\IndiaGST\Entities\IndiaGstPurchaseReturnSnapshot;
use Modules\IndiaGST\Entities\IndiaGstPurchaseReturnLineSnapshot;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class IndiaGstPurchaseReturnTransactionService
{
    public function recordPurchaseReturnSnapshot(ReturnPurchase $returnPurchase, array $returnData): ?IndiaGstPurchaseReturnSnapshot
    {
        $existing = IndiaGstPurchaseReturnSnapshot::where('return_purchase_id', $returnPurchase->id)->first();
        if ($existing) {
            return $existing;
        }

        $purchaseId = $returnPurchase->purchase_id ?? ($returnData['purchase_id'] ?? null);
        if (!$purchaseId) {
            return null;
        }

        $purchase = Purchase::find($purchaseId);
        $purchaseSnapshot = IndiaGstPurchaseSnapshot::with('lines')->where('purchase_id', $purchaseId)->first();
        if (!$purchaseSnapshot) {
            return null; // Not a GST purchase
        }

        $now = Carbon::now();
        $purchaseProductReturnsList = PurchaseProductReturn::where('return_id', $returnPurchase->id)->orderBy('id')->get();

        $aggTaxable = 0.0;
        $aggCgst = 0.0;
        $aggSgst = 0.0;
        $aggUtgst = 0.0;
        $aggIgst = 0.0;
        $aggCess = 0.0;
        $aggReversedItc = 0.0;
        $aggTotal = 0.0;

        $lineSnapshotsToCreate = [];

        // Check previous returns against this purchase to guard against over-return
        $previousReturnSnapshots = IndiaGstPurchaseReturnSnapshot::with('lines')->where('purchase_id', $purchaseId)->get();

        foreach ($purchaseProductReturnsList as $prodReturn) {
            $productId = $prodReturn->product_id;
            $qty = (float)$prodReturn->qty;

            $purchaseLineSnapshot = $purchaseSnapshot->lines->firstWhere('product_id', $productId);
            if (!$purchaseLineSnapshot) {
                continue;
            }

            $origQty = (float)$purchaseLineSnapshot->quantity;
            if ($origQty <= 0) continue;

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
                    'india_gst' => "Return quantity ({$qty}) exceeds remaining eligible quantity (" . ($origQty - $previouslyReturnedQty) . ") for Product {$purchaseLineSnapshot->product_name}."
                ]);
            }

            $ratio = $qty / $origQty;
            $lineTaxable = round((float)$purchaseLineSnapshot->taxable_value * $ratio, 2);
            $lineCgst = round((float)$purchaseLineSnapshot->cgst_amount * $ratio, 2);
            $lineSgst = round((float)$purchaseLineSnapshot->sgst_amount * $ratio, 2);
            $lineUtgst = round((float)$purchaseLineSnapshot->utgst_amount * $ratio, 2);
            $lineIgst = round((float)$purchaseLineSnapshot->igst_amount * $ratio, 2);
            $lineCess = round((float)$purchaseLineSnapshot->cess_amount * $ratio, 2);
            $lineTotal = round((float)$purchaseLineSnapshot->line_total * $ratio, 2);
            $lineReversedItc = round((float)$purchaseLineSnapshot->eligible_itc_amount * $ratio, 2);

            $aggTaxable += $lineTaxable;
            $aggCgst += $lineCgst;
            $aggSgst += $lineSgst;
            $aggUtgst += $lineUtgst;
            $aggIgst += $lineIgst;
            $aggCess += $lineCess;
            $aggReversedItc += $lineReversedItc;
            $aggTotal += $lineTotal;

            $lineSnapshotsToCreate[] = [
                'purchase_product_return_id' => $prodReturn->id,
                'product_id' => $productId,
                'hsn_sac_code' => $purchaseLineSnapshot->hsn_sac_code,
                'quantity' => $qty,
                'unit_cost' => (float)$purchaseLineSnapshot->unit_cost,
                'taxable_value' => $lineTaxable,
                'cgst_rate' => $purchaseLineSnapshot->cgst_rate,
                'cgst_amount' => $lineCgst,
                'sgst_rate' => $purchaseLineSnapshot->sgst_rate,
                'sgst_amount' => $lineSgst,
                'utgst_rate' => $purchaseLineSnapshot->utgst_rate,
                'utgst_amount' => $lineUtgst,
                'igst_rate' => $purchaseLineSnapshot->igst_rate,
                'igst_amount' => $lineIgst,
                'cess_rate' => $purchaseLineSnapshot->cess_rate,
                'cess_amount' => $lineCess,
                'reversed_itc_amount' => $lineReversedItc,
                'line_total' => $lineTotal,
            ];
        }

        $totalTax = $aggCgst + $aggSgst + $aggUtgst + $aggIgst + $aggCess;

        $returnSnapshot = IndiaGstPurchaseReturnSnapshot::create([
            'return_purchase_id' => $returnPurchase->id,
            'purchase_id' => $purchase->id,
            'india_gst_purchase_snapshot_id' => $purchaseSnapshot->id,
            'gst_registration_id' => $purchaseSnapshot->gst_registration_id,
            'adjustment_type' => 'supplier_credit_note', // Supplier Credit Note / Purchase GST Adjustment reversing Input Tax Credit
            'note_reference' => $returnPurchase->reference_no,
            'note_date' => $returnPurchase->created_at ? $returnPurchase->created_at->toDateString() : $now->toDateString(),
            'original_invoice_reference' => $purchaseSnapshot->invoice_reference,
            'original_invoice_date' => $purchaseSnapshot->invoice_date,
            'financial_year' => $this->getFinancialYear($returnPurchase->created_at ?? $now),

            'supplier_id' => $purchaseSnapshot->supplier_id,
            'supplier_name' => $purchaseSnapshot->supplier_name,
            'supplier_gstin' => $purchaseSnapshot->supplier_gstin,
            'supplier_state_code' => $purchaseSnapshot->supplier_state_code,
            'recipient_state_code' => $purchaseSnapshot->recipient_state_code,
            'place_of_supply_state_code' => $purchaseSnapshot->place_of_supply_state_code,
            'is_inter_state' => $purchaseSnapshot->is_inter_state,

            'adjusted_taxable_value' => $aggTaxable,
            'adjusted_cgst' => $aggCgst,
            'adjusted_sgst' => $aggSgst,
            'adjusted_utgst' => $aggUtgst,
            'adjusted_igst' => $aggIgst,
            'adjusted_cess' => $aggCess,
            'adjusted_total_tax' => $totalTax,
            'reversed_itc_amount' => $aggReversedItc,
            'adjusted_grand_total' => $aggTotal,
            'locked_at' => $now,
        ]);

        foreach ($lineSnapshotsToCreate as $lineData) {
            $lineData['india_gst_purchase_return_snapshot_id'] = $returnSnapshot->id;
            IndiaGstPurchaseReturnLineSnapshot::create($lineData);
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
