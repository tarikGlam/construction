<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SupplierOpeningBalanceService
{
    public function __construct(private AccountingService $accounting)
    {
    }

    /** The opening purchase is the payable source; supplier.opening_balance mirrors it for legacy reports. */
    public function change(Supplier $supplier, float $amount): void
    {
        DB::transaction(function () use ($supplier, $amount) {
            $locked = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $this->changeLocked($locked, $amount);
        }, 3);
    }

    public function openingWarehouseId(): int
    {
        $assigned = Auth::user()?->warehouse_id;
        $warehouse = Warehouse::query()->where('is_active', true)
            ->when($assigned, fn ($query) => $query->whereKey($assigned))
            ->orderBy('id')->first();
        if (!$warehouse) {
            throw new RuntimeException('An active warehouse is required for a supplier opening payable.');
        }
        return (int) $warehouse->id;
    }

    private function changeLocked(Supplier $supplier, float $amount): void
    {
        $purchases = Purchase::query()
            ->where('supplier_id', $supplier->id)
            ->whereRaw('LOWER(purchase_type) = ?', ['opening balance'])
            ->lockForUpdate()
            ->get();

        if ($purchases->count() > 1) {
            throw ValidationException::withMessages([
                'opening_balance' => 'Multiple opening purchases exist. Reconcile them before changing this balance.',
            ]);
        }

        $purchase = $purchases->first();
        $oldAmount = (float) $supplier->opening_balance;
        if (($purchase && abs((float) $purchase->grand_total - $oldAmount) > 0.000001)
            || (!$purchase && $oldAmount > 0.000001)) {
            throw ValidationException::withMessages([
                'opening_balance' => 'The existing opening balance disagrees with its purchase. Reconcile historical data first.',
            ]);
        }

        if (abs($amount - $oldAmount) < 0.000001) {
            return;
        }

        $paid = $purchase ? (float) DB::table('payments')
            ->where('purchase_id', $purchase->id)
            ->whereNull('return_id')
            ->whereNull('purchase_return_id')
            ->sum('amount') : 0;
        if ($amount + 0.000001 < $paid) {
            throw ValidationException::withMessages([
                'opening_balance' => 'The opening balance cannot be less than payments already made against it.',
            ]);
        }

        if ($amount > 0 && !$purchase) {
            $purchase = Purchase::create([
                'reference_no' => 'sob-' . $supplier->id . '-' . \Illuminate\Support\Str::uuid(),
                'supplier_id' => $supplier->id,
                'user_id' => Auth::id(),
                'warehouse_id' => $this->openingWarehouseId(),
                'currency_id' => app(\App\Services\Accounting\CurrencyNormalizationService::class)->getBaseCurrencyId(),
                'exchange_rate' => 1,
                'item' => 0,
                'total_qty' => 0,
                'total_discount' => 0,
                'total_tax' => 0,
                'total_cost' => $amount,
                'grand_total' => $amount,
                'status' => 1,
                'payment_status' => 1,
                'paid_amount' => 0,
                'purchase_type' => 'Opening balance',
            ]);
        } elseif ($purchase) {
            $purchase->total_cost = $amount;
            $purchase->grand_total = $amount;
            $purchase->paid_amount = $paid;
            $purchase->payment_status = $amount > $paid ? 1 : 2;
            if ($amount == 0.0) {
                $purchase->delete();
            } else {
                $purchase->save();
            }
        }

        $reversal = $this->accounting->reverseTransaction(Supplier::class, $supplier->id);
        if (!$reversal->isSuccess()) {
            throw new RuntimeException('Supplier opening balance accounting reversal failed.');
        }

        $supplier->opening_balance = $amount;
        $supplier->save();
        if ($amount > 0) {
            $revision = (int) DB::table('journal_entries')
                ->where('source_type', Supplier::class)
                ->where('source_id', $supplier->id)
                ->where('event_type', 'like', 'supplier_opening_balance_updated_%')
                ->count() + 1;
            $posting = $this->accounting->recordSupplierOpeningBalance($supplier, 'supplier_opening_balance_updated_' . $revision);
            if (!$posting->isSuccess()) {
                throw new RuntimeException('Supplier opening balance accounting posting failed: ' . $posting->getMessage());
            }
        }
    }
}
