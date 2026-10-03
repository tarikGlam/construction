<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Payment;
use App\Models\PaymentWithCheque;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SupplierDuePaymentService
{
    private const EPSILON = 0.000001;

    public function __construct(
        private AccountingService $accounting,
        private PaymentAccountService $paymentAccounts,
        private WarehouseAccessService $warehouseAccess,
    ) {
    }

    public function dueForSupplier(int $supplierId, ?array $warehouseIds = null, bool $includeOpening = true): float
    {
        $dues = $this->duesForSuppliers([$supplierId], $warehouseIds, $includeOpening);
        return $dues[$supplierId] ?? 0.0;
    }

    /**
     * Batch calculation of dues for multiple suppliers.
     *
     * @param int[] $supplierIds
     * @param array<int>|null $warehouseIds
     * @param bool $includeOpening
     * @return array<int, float>
     */
    public function duesForSuppliers(array $supplierIds = [], ?array $warehouseIds = null, bool $includeOpening = true): array
    {
        if (empty($supplierIds)) {
            return [];
        }

        $query = Purchase::query()
            ->select('id', 'supplier_id', 'warehouse_id', 'currency_id', 'exchange_rate', 'grand_total', 'paid_amount', 'payment_status', 'purchase_type')
            ->whereNull('deleted_at')
            ->where('status', '!=', 3)
            ->where('payment_status', 1)
            ->whereIn('supplier_id', $supplierIds)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($includeOpening === false) {
            $query->where(function ($q) {
                $q->whereNull('purchase_type')
                    ->orWhereRaw('LOWER(purchase_type) != ?', ['opening balance']);
            });
        }

        if ($warehouseIds !== null) {
            if (empty($warehouseIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('purchases.warehouse_id', $warehouseIds);
            }
        } else {
            $this->warehouseAccess->scope($query, 'purchases.warehouse_id');
        }

        $purchasesBySupplier = $query->get()->groupBy('supplier_id');

        $openingMap = [];
        if ($includeOpening && $warehouseIds === null) {
            $suppliersWithOpeningPurchase = DB::table('purchases')
                ->whereIn('supplier_id', $supplierIds)
                ->whereRaw('LOWER(purchase_type) = ?', ['opening balance'])
                ->whereNull('deleted_at')
                ->pluck('supplier_id')
                ->flip()
                ->all();

            $missingOpeningSuppliers = array_filter(
                $supplierIds,
                fn($id) => !isset($suppliersWithOpeningPurchase[$id])
            );

            if (!empty($missingOpeningSuppliers)) {
                $openingMap = DB::table('suppliers')
                    ->whereIn('id', $missingOpeningSuppliers)
                    ->pluck('opening_balance', 'id')
                    ->all();
            }
        }

        $standaloneReturns = DB::table('return_purchases')
            ->whereNull('purchase_id')
            ->whereIn('supplier_id', $supplierIds)
            ->when($warehouseIds !== null, function ($q) use ($warehouseIds) {
                if (empty($warehouseIds)) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn('warehouse_id', $warehouseIds);
                }
            })
            ->selectRaw('supplier_id, SUM(grand_total) as total_returned')
            ->groupBy('supplier_id')
            ->pluck('total_returned', 'supplier_id')
            ->all();

        $standaloneRefunds = DB::table('payments')
            ->join('return_purchases', 'return_purchases.id', '=', 'payments.purchase_return_id')
            ->whereNull('return_purchases.purchase_id')
            ->whereIn('return_purchases.supplier_id', $supplierIds)
            ->when($warehouseIds !== null, function ($q) use ($warehouseIds) {
                if (empty($warehouseIds)) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn('return_purchases.warehouse_id', $warehouseIds);
                }
            })
            ->selectRaw('return_purchases.supplier_id, SUM(payments.amount) as total_refunded')
            ->groupBy('return_purchases.supplier_id')
            ->pluck('total_refunded', 'supplier_id')
            ->all();

        $result = [];
        foreach ($supplierIds as $sId) {
            $purchases = $purchasesBySupplier->get($sId, collect());
            $purchasesDue = (float) $purchases->sum(fn(Purchase $p) => $this->dueForPurchase($p));
            $opening = (float) ($openingMap[$sId] ?? 0.0);
            $ret = (float) ($standaloneReturns[$sId] ?? 0.0);
            $ref = (float) ($standaloneRefunds[$sId] ?? 0.0);

            $result[$sId] = round(max(0.0, $opening + $purchasesDue - $ret + $ref), 4);
        }

        return $result;
    }

    public function reversePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($payment->purchase_id);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ((int) $lockedPayment->purchase_id !== (int) $purchase->id) {
                throw new RuntimeException('Supplier payment purchase changed during reversal.');
            }
            $result = $this->accounting->reverseTransaction(Payment::class, $lockedPayment->id, '_deleted');
            if (!$result->isSuccess()) {
                throw new RuntimeException('Supplier payment accounting reversal failed.');
            }
            PaymentWithCheque::where('payment_id', $lockedPayment->id)->delete();
            $lockedPayment->delete();
            $purchase->paid_amount = (float) Payment::query()->where('purchase_id', $purchase->id)
                ->whereNull('return_id')->whereNull('purchase_return_id')->sum('amount');
            $purchase->payment_status = $this->dueForPurchase($purchase) > self::EPSILON ? 1 : 2;
            $purchase->save();
        }, 3);
    }

    /**
     * @return Collection<int, Payment>
     */
    public function clear(
        Supplier $supplier,
        float $amount,
        int $accountId,
        string $paymentMethod,
        ?string $note = null,
        ?string $chequeNumber = null,
        ?int $cashRegisterId = null,
    ): Collection {
        $this->paymentAccounts->assertValidSupplierPaymentSourceId($accountId);

        return DB::transaction(function () use (
            $supplier,
            $amount,
            $accountId,
            $paymentMethod,
            $note,
            $chequeNumber,
            $cashRegisterId,
        ) {
            $purchases = $this->duePurchases($supplier->id, true);
            $dues = $purchases->mapWithKeys(
                fn (Purchase $purchase) => [$purchase->id => $this->dueForPurchase($purchase)]
            );
            $availableDue = (float) $dues->sum();

            if ($availableDue <= self::EPSILON) {
                throw ValidationException::withMessages([
                    'amount' => __('db.supplier_payment_no_due'),
                ]);
            }

            if ($amount - $availableDue > self::EPSILON) {
                throw ValidationException::withMessages([
                    'amount' => __('db.supplier_payment_exceeds_due', [
                        'amount' => number_format($availableDue, config('decimal', 2), '.', ''),
                    ]),
                ]);
            }

            $remaining = $amount;
            $payments = collect();
            $sequence = 1;

            foreach ($purchases as $purchase) {
                if ($remaining <= self::EPSILON) {
                    break;
                }

                $due = (float) $dues->get($purchase->id, 0);
                if ($due <= self::EPSILON) {
                    continue;
                }

                $paidAmount = min($remaining, $due);
                $registerId = $this->validatedCashRegisterId($cashRegisterId, $purchase);
                $payment = Payment::create([
                    'payment_reference' => sprintf(
                        'ppr-%s-%d-%d',
                        now()->format('Ymd-His'),
                        $supplier->id,
                        $sequence++
                    ),
                    'purchase_id' => $purchase->id,
                    'user_id' => Auth::id(),
                    'cash_register_id' => $registerId,
                    'account_id' => $accountId,
                    'amount' => $paidAmount,
                    'currency_id' => $purchase->currency_id,
                    'exchange_rate' => $purchase->exchange_rate,
                    'change' => 0,
                    'paying_method' => $paymentMethod,
                    'payment_note' => $note,
                    'payment_at' => now(),
                ]);

                if ($paymentMethod === 'Cheque') {
                    PaymentWithCheque::create([
                        'payment_id' => $payment->id,
                        'cheque_no' => $chequeNumber,
                    ]);
                }

                $result = $this->accounting->recordPayment($payment);
                if (!$result->isSuccess()) {
                    throw new RuntimeException('Supplier due accounting posting failed.');
                }

                $purchase->paid_amount = (float) $purchase->paid_amount + $paidAmount;
                $purchase->payment_status = ($due - $paidAmount) <= self::EPSILON ? 2 : 1;
                $purchase->save();

                $remaining -= $paidAmount;
                $payments->push($payment);
            }

            if ($remaining > self::EPSILON) {
                throw new RuntimeException('Supplier due allocation did not consume the requested amount.');
            }

            return $payments;
        }, 3);
    }

    private function duePurchases(int $supplierId, bool $lock = false, ?array $warehouseIds = null): Collection
    {
        $query = Purchase::query()
            ->select('id', 'supplier_id', 'warehouse_id', 'currency_id', 'exchange_rate', 'grand_total', 'paid_amount', 'payment_status')
            ->whereNull('deleted_at')
            ->where('status', '!=', 3)
            ->where('payment_status', 1)
            ->where('supplier_id', $supplierId)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($warehouseIds !== null) {
            if (empty($warehouseIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('purchases.warehouse_id', $warehouseIds);
            }
        } else {
            $this->warehouseAccess->scope($query, 'purchases.warehouse_id');
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function dueForPurchase(Purchase $purchase): float
    {
        $paid = (float) DB::table('payments')
            ->where('purchase_id', $purchase->id)
            ->whereNull('purchase_return_id')
            ->whereNull('return_id')
            ->where(function ($q) {
                $q->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
            })
            ->sum('amount');

        $effectivePaid = max((float) $purchase->paid_amount, $paid);

        return max(
            0,
            (float) $purchase->grand_total
                - $this->actualReturnTotal((int) $purchase->id)
                - $effectivePaid
        );
    }

    private function actualReturnTotal(int $purchaseId): float
    {
        return (float) DB::table('return_purchases')
            ->leftJoin(
                DB::raw('(select purchase_return_id, sum(amount) as refunded_amount from payments where purchase_return_id is not null group by purchase_return_id) as purchase_return_refunds'),
                'purchase_return_refunds.purchase_return_id',
                '=',
                'return_purchases.id'
            )
            ->where('return_purchases.purchase_id', $purchaseId)
            ->sum(DB::raw('GREATEST(return_purchases.grand_total - COALESCE(purchase_return_refunds.refunded_amount, 0), 0)'));
    }

    private function validatedCashRegisterId(?int $cashRegisterId, Purchase $purchase): ?int
    {
        if (!$cashRegisterId) {
            return null;
        }

        $valid = CashRegister::query()
            ->whereKey($cashRegisterId)
            ->where('user_id', Auth::id())
            ->where('warehouse_id', $purchase->warehouse_id)
            ->where('status', 1)
            ->exists();

        if (!$valid) {
            throw ValidationException::withMessages([
                'cash_register' => __('db.supplier_payment_invalid_register'),
            ]);
        }

        return $cashRegisterId;
    }
}
