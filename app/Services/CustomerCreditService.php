<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Sale;

class CustomerCreditService
{
    public function __construct(private ReceivableReconciliationService $receivables)
    {
    }

    /**
     * Calculates the true total due for a customer based on the canonical formula:
     * (sales + opening balance) - (sale payments + sale returns)
     * 
     * @param int $customerId
     * @param int|null $excludeSaleId A sale ID to exclude from calculations (used during Edit Sale).
     * @return float
     */
    public function calculateCustomerDue($customerId, $excludeSaleId = null): float
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
            return 0.0;
        }

        return $this->receivables->operationalBalance(
            (int) $customerId,
            $excludeSaleId ? (int) $excludeSaleId : null
        );
    }

    /**
     * Validates if a proposed new sale or edit is permitted by the customer's credit limit.
     * Must be called INSIDE a DB transaction where the customer row is locked.
     * 
     * @param int $customerId
     * @param float $grandTotal The total price of the new/edited sale.
     * @param float $paidAmount The amount paid upfront for the new/edited sale.
     * @param int|null $excludeSaleId The sale ID to exclude if editing an existing sale.
     * @param bool $isDraft Whether this sale is being saved as a draft.
     * @return array ['allowed' => bool, 'message' => string]
     */
    public function validateCreditLimit($customerId, $grandTotal, $paidAmount, $excludeSaleId = null, $isDraft = false): array
    {
        // Lock the customer row to prevent concurrent sales from bypassing limits
        $customer = Customer::where('id', $customerId)->lockForUpdate()->first();
        
        if (!$customer || !$customer->is_active) {
            return ['allowed' => false, 'message' => 'Invalid or inactive customer.'];
        }

        if ($isDraft) {
            return ['allowed' => true, 'message' => ''];
        }

        $newSaleDue = max(0, (float)$grandTotal - (float)$paidAmount);

        // A fully paid sale creates no new credit and must remain allowed
        if ($newSaleDue <= 0) {
            return ['allowed' => true, 'message' => ''];
        }

        // Walk-in Customer is strictly prohibited from credit sales
        $customerType = strtolower(trim((string)$customer->type));
        if ($customerType === 'walkin' || $customerType === \App\Enums\CustomerTypeEnum::WALKIN->value) {
            return [
                'allowed' => false,
                'message' => 'Credit sale is not permitted for Walk-in Customer. Sale must be fully paid.'
            ];
        }

        // Canonical 3-State Credit Policy:
        // 1. NULL / blank => Unlimited credit
        if ($customer->credit_limit === null || $customer->credit_limit === '') {
            return ['allowed' => true, 'message' => ''];
        }

        $creditLimit = (float)$customer->credit_limit;

        // 2. 0 => Credit sales disabled (no new customer due allowed)
        if ($creditLimit <= 0) {
            return [
                'allowed' => false,
                'message' => 'Customer has no credit limit. Sale must be fully paid.'
            ];
        }

        // 3. Positive amount => Maximum outstanding customer credit
        $currentDue = $this->calculateCustomerDue($customerId, $excludeSaleId);
        $resultingDue = $currentDue + $newSaleDue;

        // Ensure rounding issues don't trigger false positives
        $resultingDue = round($resultingDue, 2);
        $creditLimit = round($creditLimit, 2);

        if ($resultingDue > $creditLimit) {
            return [
                'allowed' => false,
                'message' => "Credit limit exceeded. Limit: " . number_format($creditLimit, 2) . ", Current Due: " . number_format($currentDue, 2) . ", Resulting Due: " . number_format($resultingDue, 2)
            ];
        }

        return ['allowed' => true, 'message' => ''];
    }
}
