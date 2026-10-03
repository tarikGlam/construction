<?php

namespace Modules\IndiaGST\Services\Calculation;

use Illuminate\Validation\ValidationException;
use App\Models\Product;
use App\Models\Supplier;
use Modules\IndiaGST\Entities\IndiaGstSupplierProfile;
use Modules\IndiaGST\Entities\IndiaGstProductProfile;
use Modules\IndiaGST\Services\Calculation\IndiaGstSaleRegistrationResolver;
use Modules\IndiaGST\Services\Calculation\InvoiceDiscountAllocator;
use Modules\IndiaGST\Services\Calculation\IndiaGstCalculationInputAssembler;
use Modules\IndiaGST\Services\Calculation\IndiaGstCalculationStrategy;
use Modules\IndiaGST\Services\Calculation\Resolvers\IndiaGstPlaceOfSupplyResolver;
use Modules\IndiaGST\Services\Calculation\Resolvers\IndiaGstJurisdictionResolver;
use Modules\IndiaGST\DTOs\IndiaGstPurchaseCalculationResult;

class IndiaGstPurchaseCalculationService
{
    public function __construct(
        private IndiaGstSaleRegistrationResolver $registrationResolver,
        private InvoiceDiscountAllocator $discountAllocator,
        private IndiaGstCalculationInputAssembler $inputAssembler,
        private IndiaGstCalculationStrategy $calculationStrategy,
        private IndiaGstPlaceOfSupplyResolver $posResolver,
        private IndiaGstJurisdictionResolver $jurisdictionResolver
    ) {
    }

    public function calculate(array $purchaseData, $user = null): IndiaGstPurchaseCalculationResult
    {
        // 1. Resolve Warehouse / Recipient Registration
        $registration = $this->registrationResolver->resolve($purchaseData);
        $recipientStateCode = $registration->state->state_code;

        // 2. Resolve Supplier Profile
        $supplierId = $purchaseData['supplier_id'] ?? null;
        $supplierProfile = null;
        if ($supplierId) {
            $supplierProfile = IndiaGstSupplierProfile::with('state')->where('supplier_id', $supplierId)->first();
        }

        $supplierStateCode = $supplierProfile?->state?->state_code ?? $recipientStateCode;
        $supplierRegistrationType = $supplierProfile?->registration_type ?? 'regular';

        // 3. Reverse Charge & ITC
        $isReverseCharge = !empty($purchaseData['is_reverse_charge']) || $supplierRegistrationType === 'unregistered_rcm';
        $itcEligibility = $purchaseData['itc_eligibility'] ?? 'eligible';
        if (!in_array($itcEligibility, ['eligible', 'ineligible', 'blocked'], true)) {
            throw ValidationException::withMessages(['itc_eligibility' => 'Invalid GST ITC eligibility.']);
        }
        $supplierCannotChargeGst = in_array($supplierRegistrationType, ['composition', 'unregistered'], true)
            && !$isReverseCharge;

        // 4. Extract Invoice Discount & Lines
        $totalInvoiceDiscount = (float)($purchaseData['order_discount'] ?? 0);
        $productIds = $purchaseData['product_id'] ?? [];
        $quantities = $purchaseData['qty'] ?? [];
        $netUnitCosts = $purchaseData['net_unit_cost'] ?? ($purchaseData['net_unit_price'] ?? []);
        $lineDiscounts = $purchaseData['discount'] ?? [];
        $taxIds = $purchaseData['tax_id'] ?? [];

        if (empty($productIds)) {
            throw ValidationException::withMessages(['product_id' => 'Products are required for GST calculation.']);
        }

        // Preload Product Profiles
        $productProfiles = IndiaGstProductProfile::whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');
        $products = Product::whereIn('id', $productIds)->get(['id', 'tax_method'])->keyBy('id');

        // Prepare line data for discount allocation
        $allocationLines = [];
        foreach ($productIds as $i => $pid) {
            $qty = (float)($quantities[$i] ?? 0);
            $netCost = (float)($netUnitCosts[$i] ?? 0);
            $lineDiscount = (float)($lineDiscounts[$i] ?? 0);
            $grossAmount = ($qty * $netCost) - $lineDiscount;
            
            $allocationLines[] = [
                'id' => $i,
                'gross_amount' => max(0, $grossAmount),
                'is_eligible' => true
            ];
        }

        $allocatedDiscounts = $this->discountAllocator->allocate($allocationLines, $totalInvoiceDiscount);

        // 5. Calculate line by line
        $lineResults = [];
        $errors = [];
        $requiresManualReview = false;

        $aggGross = 0.0;
        $aggLineDiscount = 0.0;
        $aggInvDiscount = 0.0;
        $aggTaxableCharges = 0.0;
        $aggNonTaxableCharges = 0.0;
        $aggTaxableValue = 0.0;
        $aggCgst = 0.0;
        $aggSgst = 0.0;
        $aggUtgst = 0.0;
        $aggIgst = 0.0;
        $aggCess = 0.0;
        $aggRounding = 0.0;
        $aggTotal = 0.0;

        foreach ($productIds as $i => $pid) {
            $productProfile = $productProfiles->get($pid);
            if (!$productProfile) {
                $errors[] = "Product ID {$pid} is missing an India GST Profile.";
                continue;
            }

            $genericTaxId = $taxIds[$i] ?? null;

            $rawData = [
                'transaction_date' => $purchaseData['created_at'] ?? now()->format('Y-m-d H:i:s'),
                'gst_registration_id' => $registration->id,
                'supplier_state_code' => $supplierStateCode,
                
                // For purchase, recipient is the business registration
                'customer_gst_profile_id' => null,
                'customer_registration_category' => 'registered',
                'billing_state_code' => $recipientStateCode,
                'shipping_state_code' => $recipientStateCode,
                
                'product_id' => $pid,
                'product_gst_profile_id' => $productProfile->id,
                'goods_service_classification' => $productProfile->type ?? 'goods',
                'tax_id' => $genericTaxId,
                
                'supply_rule_code' => !empty($purchaseData['supply_rule_code']) ? $purchaseData['supply_rule_code'] : (($productProfile->type ?? 'goods') === 'goods' ? 'goods_movement' : 'service_default_b2b'),
                'place_of_supply_override' => $purchaseData['place_of_supply_override'] ?? null,
                'override_reason' => $purchaseData['override_reason'] ?? null,
                'delivery_destination_state_code' => $recipientStateCode,
                
                'unit_price' => (float)($netUnitCosts[$i] ?? 0),
                'quantity' => (float)($quantities[$i] ?? 0),
                'is_tax_inclusive' => false,
                'line_discount' => (float)($lineDiscounts[$i] ?? 0),
                'allocated_invoice_discount' => (float)($allocatedDiscounts[$i] ?? 0),
            ];

            try {
                $input = $this->inputAssembler->assemble($rawData, $user);
                
                $posResult = $this->posResolver->resolve($input);
                if (!$posResult['is_successful']) {
                    $errors[] = "Line {$i}: " . ($posResult['error_message'] ?? 'Unknown POS error');
                    $requiresManualReview = true;
                    continue;
                }

                $jurisdiction = $this->jurisdictionResolver->resolve($input->supplier_state_code, $posResult['state_code']);
                if (!$jurisdiction['is_successful']) {
                    $errors[] = "Line {$i}: Jurisdiction error: {$jurisdiction['error_message']}";
                    $requiresManualReview = true;
                    continue;
                }

                $result = $this->calculationStrategy->calculate($input, $posResult, $jurisdiction['jurisdiction']);

                // Composition and unregistered suppliers cannot charge ordinary GST.
                // RCM remains an explicit, separate recipient-liability path.
                if ($supplierCannotChargeGst) {
                    $result->components = [];
                    $result->total_tax = 0.0;
                    $result->total_cess = 0.0;
                    $result->total_value = $result->taxable_value;
                }

                $comps = [];
                foreach ($result->components as $comp) {
                    $comps[strtoupper($comp->component_code)] = $comp->amount;
                }

                $lineTaxTotal = ($comps['CGST'] ?? 0) + ($comps['SGST'] ?? 0) + ($comps['UTGST'] ?? 0) + ($comps['IGST'] ?? 0) + ($comps['CESS'] ?? 0);

                $lineEligibleItc = ($itcEligibility === 'eligible') ? $lineTaxTotal : 0.0;
                $lineIneligibleItc = ($itcEligibility !== 'eligible') ? $lineTaxTotal : 0.0;

                $lineResults[$i] = [
                    'product_id' => $pid,
                    'input' => $input,
                    'posResult' => $posResult,
                    'jurisdiction' => $jurisdiction,
                    'result' => $result,
                    'product_profile' => $productProfile,
                    'is_reverse_charge' => $isReverseCharge,
                    'itc_eligibility' => $itcEligibility,
                    'eligible_itc_amount' => $lineEligibleItc,
                    'ineligible_itc_amount' => $lineIneligibleItc,
                    'source_tax_inclusive' => (int) ($products->get($pid)?->tax_method ?? 1) === 2,
                ];

                $aggGross += ($input->unit_price * $input->quantity);
                $aggLineDiscount += $input->line_discount;
                $aggInvDiscount += $input->allocated_invoice_discount;
                $aggTaxableCharges += $input->taxable_additional_charges;
                $aggNonTaxableCharges += $input->non_taxable_additional_charges;
                $aggTaxableValue += $result->taxable_value;

                $aggCgst += $comps['CGST'] ?? 0.0;
                $aggSgst += $comps['SGST'] ?? 0.0;
                $aggUtgst += $comps['UTGST'] ?? 0.0;
                $aggIgst += $comps['IGST'] ?? 0.0;
                $aggCess += $comps['CESS'] ?? 0.0;
                
                $aggRounding += $result->rounding_adjustment;
                $aggTotal += $result->total_value;

            } catch (ValidationException $e) {
                $errors[] = "Line {$i}: " . $e->getMessage();
                $requiresManualReview = true;
            } catch (\Exception $e) {
                $errors[] = "Line {$i}: " . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            return new IndiaGstPurchaseCalculationResult(
                isSuccessful: false,
                lineResults: [],
                totalGrossValue: 0,
                totalLineDiscount: 0,
                totalInvoiceDiscount: 0,
                totalTaxableCharges: 0,
                totalNonTaxableCharges: 0,
                totalTaxableValue: 0,
                totalCgst: 0,
                totalSgst: 0,
                totalUtgst: 0,
                totalIgst: 0,
                totalCess: 0,
                roundingAdjustment: 0,
                grandTotal: 0,
                errors: $errors,
                requiresManualReview: $requiresManualReview
            );
        }

        $totalTaxAmount = $aggCgst + $aggSgst + $aggUtgst + $aggIgst + $aggCess;

        $rcmCgst = $isReverseCharge ? $aggCgst : 0.0;
        $rcmSgst = $isReverseCharge ? $aggSgst : 0.0;
        $rcmUtgst = $isReverseCharge ? $aggUtgst : 0.0;
        $rcmIgst = $isReverseCharge ? $aggIgst : 0.0;
        $rcmCess = $isReverseCharge ? $aggCess : 0.0;
        $rcmTotal = $isReverseCharge ? $totalTaxAmount : 0.0;

        $totalEligibleItc = ($itcEligibility === 'eligible') ? $totalTaxAmount : 0.0;
        $totalIneligibleItc = ($itcEligibility !== 'eligible') ? $totalTaxAmount : 0.0;

        return new IndiaGstPurchaseCalculationResult(
            isSuccessful: true,
            lineResults: $lineResults,
            totalGrossValue: $aggGross,
            totalLineDiscount: $aggLineDiscount,
            totalInvoiceDiscount: $aggInvDiscount,
            totalTaxableCharges: $aggTaxableCharges,
            totalNonTaxableCharges: $aggNonTaxableCharges,
            totalTaxableValue: $aggTaxableValue,
            totalCgst: $aggCgst,
            totalSgst: $aggSgst,
            totalUtgst: $aggUtgst,
            totalIgst: $aggIgst,
            totalCess: $aggCess,
            roundingAdjustment: $aggRounding,
            grandTotal: $aggTotal,
            isReverseCharge: $isReverseCharge,
            rcmLiabilityCgst: $rcmCgst,
            rcmLiabilitySgst: $rcmSgst,
            rcmLiabilityUtgst: $rcmUtgst,
            rcmLiabilityIgst: $rcmIgst,
            rcmLiabilityCess: $rcmCess,
            rcmTotalLiability: $rcmTotal,
            itcEligibility: $itcEligibility,
            totalEligibleItc: $totalEligibleItc,
            totalIneligibleItc: $totalIneligibleItc
        );
    }
}
