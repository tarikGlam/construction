<?php

namespace Modules\IndiaGST\DTOs;

use Modules\IndiaGST\Entities\IndiaGstRegistration;

class PreparedIndiaGstPurchase
{
    public function __construct(
        public array $purchaseData,
        public ?IndiaGstPurchaseCalculationResult $gstCalculationResult = null,
        public ?IndiaGstRegistration $resolvedRegistration = null,
        public bool $isFinalized = false,
        public array $validationWarnings = []
    ) {}

    public function isIndiaGstApplicable(): bool
    {
        return $this->gstCalculationResult !== null && $this->resolvedRegistration !== null;
    }
}
