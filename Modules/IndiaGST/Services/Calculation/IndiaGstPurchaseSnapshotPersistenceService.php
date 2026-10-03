<?php

namespace Modules\IndiaGST\Services\Calculation;

use App\Models\Purchase;
use App\Models\ProductPurchase;
use App\Models\Supplier;
use Modules\IndiaGST\Entities\IndiaGstPurchaseSnapshot;
use Modules\IndiaGST\Entities\IndiaGstPurchaseLineSnapshot;
use Modules\IndiaGST\DTOs\IndiaGstPurchaseCalculationResult;
use Modules\IndiaGST\Entities\IndiaGstRegistration;
use Modules\IndiaGST\Entities\IndiaGstSupplierProfile;
use Carbon\Carbon;

class IndiaGstPurchaseSnapshotPersistenceService
{
    public function persist(
        Purchase $purchase,
        IndiaGstPurchaseCalculationResult $calcResult,
        IndiaGstRegistration $registration,
        array $purchaseData
    ): void {
        $now = Carbon::now();
        $isDraft = (isset($purchase->status) && (int)$purchase->status === 3);

        $existingSnapshot = IndiaGstPurchaseSnapshot::where('purchase_id', $purchase->id)->first();
        if ($existingSnapshot || $isDraft) {
            return;
        }

        $supplier = null;
        $supplierProfile = null;
        if ($purchase->supplier_id) {
            $supplier = Supplier::find($purchase->supplier_id);
            $supplierProfile = IndiaGstSupplierProfile::with('state')->where('supplier_id', $purchase->supplier_id)->first();
        }

        // Get POS info from first line result
        $firstLine = $calcResult->lineResults[0] ?? null;
        $posStateCode = $firstLine['posResult']['state_code'] ?? $registration->state->state_code;
        $posStateName = $firstLine['posResult']['state_name'] ?? $registration->state->name;
        $supplyRuleCode = $firstLine['posResult']['rule_code'] ?? 'goods_movement';
        $jurisdictionCode = $firstLine['jurisdiction']['jurisdiction'] ?? 'intra_state_sgst';
        $isInterState = ($jurisdictionCode === 'inter_state');

        $supplierStateCode = $supplierProfile?->state?->state_code ?? $supplier?->state ?? $registration->state->state_code;
        $supplierStateName = $supplierProfile?->state?->name ?? $supplier?->state ?? $registration->state->name;

        $snapshot = IndiaGstPurchaseSnapshot::create([
            'purchase_id' => $purchase->id,
            'gst_registration_id' => $registration->id,
            'invoice_reference' => $purchase->reference_no,
            'invoice_date' => $purchase->created_at ? $purchase->created_at->toDateString() : $now->toDateString(),
            'transaction_date' => $purchase->created_at ? $purchase->created_at->toDateString() : $now->toDateString(),
            'financial_year' => $this->getFinancialYear($purchase->created_at ?? $now),

            'supplier_id' => $supplier?->id,
            'supplier_name' => $supplier?->name,
            'supplier_legal_name' => $supplier?->company_name ?? $supplier?->name,
            'supplier_trade_name' => $supplier?->company_name,
            'supplier_gstin' => $supplierProfile?->gstin ?? $supplier?->vat_number,
            'supplier_registration_type' => $supplierProfile?->registration_type ?? 'regular',
            'supplier_address' => $supplier?->address,
            'supplier_state_code' => $supplierStateCode,
            'supplier_state_name' => $supplierStateName,

            'recipient_legal_name' => $registration->legal_name,
            'recipient_trade_name' => $registration->trade_name,
            'recipient_gstin' => $registration->gstin,
            'recipient_address' => null,
            'recipient_state_code' => $registration->state->state_code,
            'recipient_state_name' => $registration->state->name,

            'place_of_supply_state_code' => $posStateCode,
            'place_of_supply_state_name' => $posStateName,
            'supply_rule_code' => $supplyRuleCode,
            'jurisdiction_code' => $jurisdictionCode,
            'is_inter_state' => $isInterState,

            'is_reverse_charge' => $calcResult->isReverseCharge,
            'rcm_liability_cgst' => $calcResult->rcmLiabilityCgst,
            'rcm_liability_sgst' => $calcResult->rcmLiabilitySgst,
            'rcm_liability_utgst' => $calcResult->rcmLiabilityUtgst,
            'rcm_liability_igst' => $calcResult->rcmLiabilityIgst,
            'rcm_liability_cess' => $calcResult->rcmLiabilityCess,
            'rcm_total_liability' => $calcResult->rcmTotalLiability,

            'itc_eligibility' => $calcResult->itcEligibility,
            'itc_cgst' => ($calcResult->itcEligibility === 'eligible') ? $calcResult->totalCgst : 0.0,
            'itc_sgst' => ($calcResult->itcEligibility === 'eligible') ? $calcResult->totalSgst : 0.0,
            'itc_utgst' => ($calcResult->itcEligibility === 'eligible') ? $calcResult->totalUtgst : 0.0,
            'itc_igst' => ($calcResult->itcEligibility === 'eligible') ? $calcResult->totalIgst : 0.0,
            'itc_cess' => ($calcResult->itcEligibility === 'eligible') ? $calcResult->totalCess : 0.0,
            'total_eligible_itc' => $calcResult->totalEligibleItc,
            'total_ineligible_itc' => $calcResult->totalIneligibleItc,

            'manual_pos_override_used' => !empty($purchaseData['place_of_supply_override']),
            'manual_pos_override_reason' => $purchaseData['override_reason'] ?? null,
            'manual_pos_override_user_id' => !empty($purchaseData['place_of_supply_override']) ? ($purchaseData['user_id'] ?? auth()->id()) : null,

            'currency_code' => 'INR',
            'exchange_rate' => $purchase->exchange_rate ?? 1,

            'total_gross_value' => $calcResult->totalGrossValue,
            'total_line_discount' => $calcResult->totalLineDiscount,
            'total_invoice_discount' => $calcResult->totalInvoiceDiscount,
            'total_taxable_charges' => $calcResult->totalTaxableCharges,
            'total_non_taxable_charges' => $calcResult->totalNonTaxableCharges,
            'total_taxable_value' => $calcResult->totalTaxableValue,

            'total_cgst' => $calcResult->totalCgst,
            'total_sgst' => $calcResult->totalSgst,
            'total_utgst' => $calcResult->totalUtgst,
            'total_igst' => $calcResult->totalIgst,
            'total_cess' => $calcResult->totalCess,

            'rounding_adjustment' => $calcResult->roundingAdjustment,
            'grand_total' => $calcResult->grandTotal,

            'snapshot_version' => 1,
            'locked_at' => $now,
        ]);

        $productPurchasesList = ProductPurchase::where('purchase_id', $purchase->id)->orderBy('id')->get();

        foreach ($calcResult->lineResults as $i => $lineRes) {
            $productPurchase = $productPurchasesList[$i] ?? null;
            if (!$productPurchase) continue;

            $input = $lineRes['input'];
            $result = $lineRes['result'];
            $productProfile = $lineRes['product_profile'];

            $comps = [];
            foreach ($result->components as $comp) {
                $comps[strtoupper($comp->component_code)] = $comp->amount;
            }

            IndiaGstPurchaseLineSnapshot::create([
                'india_gst_purchase_snapshot_id' => $snapshot->id,
                'product_purchase_id' => $productPurchase->id,

                'product_id' => $productPurchase->product_id,
                'variant_id' => $productPurchase->variant_id,
                'product_name' => $productPurchase->product?->name,
                'product_code' => $productPurchase->product?->code,
                'variant_name' => null,

                'classification' => $productProfile->type ?? 'goods',
                'hsn_sac_code' => $productProfile->hsn_sac_code,
                'uqc_code' => null,

                'purchase_unit_id' => $productPurchase->purchase_unit_id,
                'purchase_unit_name' => null,

                'quantity' => $input->quantity,
                'unit_cost' => $input->unit_price,
                'tax_inclusive' => (bool) ($lineRes['source_tax_inclusive'] ?? false),

                'gross_value' => ($input->unit_price * $input->quantity),
                'line_discount' => $input->line_discount,
                'allocated_invoice_discount' => $input->allocated_invoice_discount,
                'taxable_charges' => $input->taxable_additional_charges,
                'non_taxable_charges' => $input->non_taxable_additional_charges,
                'taxable_value' => $result->taxable_value,

                'taxability_type' => $result->taxability_type,
                'gst_tax_profile_id' => $result->tax_profile_id,
                'gst_tax_profile_code' => $result->tax_profile_code,
                'gst_tax_profile_version' => 1,
                'profile_resolution_source' => $result->profile_resolution_source,

                'cgst_rate' => isset($comps['CGST']) ? ($input->resolved_tax_profile?->total_gst_rate / 2) : 0,
                'cgst_amount' => $comps['CGST'] ?? 0,
                'sgst_rate' => isset($comps['SGST']) ? ($input->resolved_tax_profile?->total_gst_rate / 2) : 0,
                'sgst_amount' => $comps['SGST'] ?? 0,
                'utgst_rate' => isset($comps['UTGST']) ? ($input->resolved_tax_profile?->total_gst_rate / 2) : 0,
                'utgst_amount' => $comps['UTGST'] ?? 0,
                'igst_rate' => isset($comps['IGST']) ? $input->resolved_tax_profile?->total_gst_rate : 0,
                'igst_amount' => $comps['IGST'] ?? 0,

                'cess_calculation_type' => $input->resolved_tax_profile?->cess_calculation_type,
                'cess_rate' => $input->resolved_tax_profile?->cess_rate ?? 0,
                'cess_amount_per_unit' => $input->resolved_tax_profile?->cess_amount_per_unit ?? 0,
                'cess_amount' => $comps['CESS'] ?? 0,

                'is_reverse_charge' => $lineRes['is_reverse_charge'] ?? false,
                'itc_eligibility' => $lineRes['itc_eligibility'] ?? 'eligible',
                'eligible_itc_amount' => $lineRes['eligible_itc_amount'] ?? 0,
                'ineligible_itc_amount' => $lineRes['ineligible_itc_amount'] ?? 0,

                'rounding_adjustment' => $result->rounding_adjustment,
                'line_total' => $result->total_value,
                'calculation_rule_code' => null,
            ]);
        }
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
