<?php

namespace Modules\IndiaGST\Services\Calculation;

use App\Models\Expense;
use Modules\IndiaGST\Entities\IndiaGstExpenseSnapshot;
use Modules\IndiaGST\Entities\IndiaGstRegistration;
use Modules\IndiaGST\Entities\IndiaGstState;
use Modules\IndiaGST\Services\Calculation\Resolvers\IndiaGstJurisdictionResolver;
use Carbon\Carbon;

class IndiaGstExpenseTransactionService
{
    public function __construct(
        private IndiaGstJurisdictionResolver $jurisdictionResolver,
        private IndiaGstSaleRegistrationResolver $registrationResolver
    ) {
    }

    public function recordExpenseSnapshot(Expense $expense, array $data): ?IndiaGstExpenseSnapshot
    {
        $existingSnapshot = IndiaGstExpenseSnapshot::where('expense_id', $expense->id)->first();
        if ($existingSnapshot) {
            return $existingSnapshot;
        }

        // Only create snapshot if GST details are provided or warehouse has GST registration
        $hasGstData = !empty($data['vendor_gstin']) || !empty($data['gst_rate']) || !empty($data['gst_tax_profile_id']) || !empty($data['hsn_sac_code']) || !empty($data['is_reverse_charge']);
        if (!$hasGstData) {
            return null;
        }

        $registration = null;
        if (!empty($expense->warehouse_id) || !empty($data['warehouse_id'])) {
            try {
                $registration = $this->registrationResolver->resolve($data + ['warehouse_id' => $expense->warehouse_id ?? ($data['warehouse_id'] ?? null)]);
            } catch (\Exception $e) {
                // Non-GST warehouse
            }
        }

        $recipientStateCode = $registration?->state?->state_code ?? '27';
        $recipientStateName = $registration?->state?->name ?? 'Maharashtra';

        $vendorStateCode = $data['vendor_state_code'] ?? $recipientStateCode;
        $vendorState = IndiaGstState::where('state_code', $vendorStateCode)->first();
        $vendorStateName = $vendorState?->name ?? $vendorStateCode;

        $posStateCode = $data['place_of_supply_state_code'] ?? $recipientStateCode;
        $posState = IndiaGstState::where('state_code', $posStateCode)->first();
        $posStateName = $posState?->name ?? $posStateCode;

        $jurisdiction = $this->jurisdictionResolver->resolve($vendorStateCode, $posStateCode);
        $jurisdictionCode = $jurisdiction['jurisdiction'] ?? 'intra_state_sgst';
        $isInterState = ($jurisdictionCode === 'inter_state');

        $taxableAmount = (float)($data['taxable_amount'] ?? ($data['amount'] ?? $expense->amount));
        $gstRate = (float)($data['gst_rate'] ?? 0);
        $cessRate = (float)($data['cess_rate'] ?? 0);

        $cgstRate = 0.0;
        $cgstAmount = 0.0;
        $sgstRate = 0.0;
        $sgstAmount = 0.0;
        $utgstRate = 0.0;
        $utgstAmount = 0.0;
        $igstRate = 0.0;
        $igstAmount = 0.0;
        $cessAmount = 0.0;

        if ($gstRate > 0) {
            if ($isInterState) {
                $igstRate = $gstRate;
                $igstAmount = round(($taxableAmount * $gstRate) / 100, 2);
            } else {
                $halfRate = $gstRate / 2;
                if ($jurisdictionCode === 'intra_state_utgst') {
                    $cgstRate = $halfRate;
                    $cgstAmount = round(($taxableAmount * $halfRate) / 100, 2);
                    $utgstRate = $halfRate;
                    $utgstAmount = round(($taxableAmount * $halfRate) / 100, 2);
                } else {
                    $cgstRate = $halfRate;
                    $cgstAmount = round(($taxableAmount * $halfRate) / 100, 2);
                    $sgstRate = $halfRate;
                    $sgstAmount = round(($taxableAmount * $halfRate) / 100, 2);
                }
            }
        }

        if ($cessRate > 0) {
            $cessAmount = round(($taxableAmount * $cessRate) / 100, 2);
        }

        $totalTax = $cgstAmount + $sgstAmount + $utgstAmount + $igstAmount + $cessAmount;
        $totalAmount = $taxableAmount + $totalTax;

        $isReverseCharge = !empty($data['is_reverse_charge']);
        $isItcEligible = isset($data['is_itc_eligible']) ? (bool)$data['is_itc_eligible'] : true;

        $rcmLiability = $isReverseCharge ? $totalTax : 0.0;
        $eligibleItc = $isItcEligible ? $totalTax : 0.0;
        $ineligibleItc = (!$isItcEligible) ? $totalTax : 0.0;

        $now = Carbon::now();

        return IndiaGstExpenseSnapshot::create([
            'expense_id' => $expense->id,
            'gst_registration_id' => $registration?->id,
            'expense_category_id' => $expense->expense_category_id,
            'reference_no' => $expense->reference_no,
            'expense_date' => $expense->created_at ? $expense->created_at->toDateString() : $now->toDateString(),
            'financial_year' => $this->getFinancialYear($expense->created_at ?? $now),

            'vendor_name' => $data['vendor_name'] ?? null,
            'vendor_gstin' => $data['vendor_gstin'] ?? null,
            'vendor_state_code' => $vendorStateCode,
            'vendor_state_name' => $vendorStateName,

            'recipient_state_code' => $recipientStateCode,
            'recipient_state_name' => $recipientStateName,

            'place_of_supply_state_code' => $posStateCode,
            'place_of_supply_state_name' => $posStateName,
            'jurisdiction_code' => $jurisdictionCode,
            'is_inter_state' => $isInterState,

            'hsn_sac_code' => $data['hsn_sac_code'] ?? null,
            'gst_tax_profile_id' => $data['gst_tax_profile_id'] ?? null,
            'gst_rate' => $gstRate,

            'is_reverse_charge' => $isReverseCharge,
            'is_itc_eligible' => $isItcEligible,
            'rcm_liability' => $rcmLiability,
            'eligible_itc' => $eligibleItc,
            'ineligible_itc' => $ineligibleItc,

            'taxable_amount' => $taxableAmount,
            'cgst_rate' => $cgstRate,
            'cgst_amount' => $cgstAmount,
            'sgst_rate' => $sgstRate,
            'sgst_amount' => $sgstAmount,
            'utgst_rate' => $utgstRate,
            'utgst_amount' => $utgstAmount,
            'igst_rate' => $igstRate,
            'igst_amount' => $igstAmount,
            'cess_rate' => $cessRate,
            'cess_amount' => $cessAmount,
            'total_tax' => $totalTax,
            'total_amount' => $totalAmount,
            'locked_at' => $now,
        ]);
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
