<?php

namespace Modules\IndiaGST\DTOs;

class IndiaGstPurchaseCalculationResult
{
    public function __construct(
        public readonly bool $isSuccessful,
        public readonly array $lineResults,
        public readonly float $totalGrossValue,
        public readonly float $totalLineDiscount,
        public readonly float $totalInvoiceDiscount,
        public readonly float $totalTaxableCharges,
        public readonly float $totalNonTaxableCharges,
        public readonly float $totalTaxableValue,
        public readonly float $totalCgst,
        public readonly float $totalSgst,
        public readonly float $totalUtgst,
        public readonly float $totalIgst,
        public readonly float $totalCess,
        public readonly float $roundingAdjustment,
        public readonly float $grandTotal,
        public readonly bool $isReverseCharge = false,
        public readonly float $rcmLiabilityCgst = 0.0,
        public readonly float $rcmLiabilitySgst = 0.0,
        public readonly float $rcmLiabilityUtgst = 0.0,
        public readonly float $rcmLiabilityIgst = 0.0,
        public readonly float $rcmLiabilityCess = 0.0,
        public readonly float $rcmTotalLiability = 0.0,
        public readonly string $itcEligibility = 'eligible',
        public readonly float $totalEligibleItc = 0.0,
        public readonly float $totalIneligibleItc = 0.0,
        public readonly array $errors = [],
        public readonly bool $requiresManualReview = false
    ) {
    }
}
