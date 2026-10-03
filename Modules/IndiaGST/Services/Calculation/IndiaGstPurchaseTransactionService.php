<?php

namespace Modules\IndiaGST\Services\Calculation;

use App\Models\Purchase;
use Illuminate\Validation\ValidationException;
use Modules\IndiaGST\DTOs\PreparedIndiaGstPurchase;

class IndiaGstPurchaseTransactionService
{
    public function __construct(
        private IndiaGstPurchaseCalculationService $calculationService,
        private IndiaGstPurchaseSnapshotPersistenceService $persistenceService,
        private IndiaGstSaleRegistrationResolver $registrationResolver
    ) {
    }

    public function preparePurchaseData(array $data, $user = null): PreparedIndiaGstPurchase
    {
        if (empty($data['warehouse_id'])) {
            return new PreparedIndiaGstPurchase(purchaseData: $data);
        }

        try {
            $registration = $this->registrationResolver->resolve($data);
        } catch (ValidationException $e) {
            return new PreparedIndiaGstPurchase(purchaseData: $data);
        }

        $result = $this->calculationService->calculate($data, $user);

        if (!$result->isSuccessful) {
            throw ValidationException::withMessages([
                'india_gst' => $result->errors
            ]);
        }

        $data['total_tax'] = $result->totalCgst + $result->totalSgst + $result->totalUtgst + $result->totalIgst + $result->totalCess;
        $data['order_tax'] = 0;

        $totalCost = 0;
        foreach ($result->lineResults as $i => $lineRes) {
            $r = $lineRes['result'];
            $input = $lineRes['input'];

            $comps = [];
            foreach ($r->components as $comp) {
                $comps[strtoupper($comp->component_code)] = $comp->amount;
            }

            $lineTax = ($comps['CGST'] ?? 0) + ($comps['SGST'] ?? 0) + ($comps['UTGST'] ?? 0) + ($comps['IGST'] ?? 0) + ($comps['CESS'] ?? 0);
            $data['tax'][$i] = $lineTax;
            $data['subtotal'][$i] = $r->total_value;
            $data['discount'][$i] = $input->line_discount;

            $totalCost += $r->total_value;
        }

        $data['total_cost'] = $totalCost;
        $shipping = (float)($data['shipping_cost'] ?? 0);
        $data['grand_total'] = $result->grandTotal + $shipping;

        return new PreparedIndiaGstPurchase(
            purchaseData: $data,
            gstCalculationResult: $result,
            resolvedRegistration: $registration,
            isFinalized: ((int)($data['status'] ?? 1) !== 3)
        );
    }

    public function finalizePurchase(Purchase $purchase, PreparedIndiaGstPurchase $preparedPurchase): void
    {
        if ($preparedPurchase->isIndiaGstApplicable()) {
            $this->persistenceService->persist(
                $purchase,
                $preparedPurchase->gstCalculationResult,
                $preparedPurchase->resolvedRegistration,
                $preparedPurchase->purchaseData
            );
        }
    }
}
