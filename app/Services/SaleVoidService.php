<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\CashRegister;
use App\Models\Coupon;
use App\Models\GiftCard;
use App\Models\Payment;
use App\Models\PaymentWithGiftCard;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductReturn;
use App\Models\ProductTransfer;
use App\Models\ProductVariant;
use App\Models\Product_Sale;
use App\Models\Product_Warehouse;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleVoidService
{
    public function void(int $saleId, User $user, string $reason): Sale
    {
        $reason = trim($reason);
        if ($reason === '') {
            $this->reject('A reason is required to void a sale.');
        }
        if (!$user->can('sales-delete')) {
            abort(403, 'You do not have permission to void sales.');
        }
        $access = app(WarehouseAccessService::class);
        if (in_array($access->classification($user), [WarehouseAccessService::PORTAL_IDENTITY, WarehouseAccessService::INVALID_OPERATIONAL], true)) {
            abort(403, 'This account cannot void operational sales.');
        }

        return DB::transaction(function () use ($saleId, $user, $reason, $access) {
            $sale = Sale::withoutGlobalScopes()->withTrashed()->whereKey($saleId)->lockForUpdate()->firstOrFail();
            if ($sale->voided_at) {
                return $sale;
            }
            if ($sale->trashed()) {
                $this->reject('This sale was already deleted and cannot be converted into a void.');
            }
            $zatca = app(ZatcaIntegrationService::class);
            if ($zatca->isPhase2Configured() || $zatca->isSourceLocked('sale', (int) $sale->id)) {
                $this->reject('A Phase 2 fiscal sale cannot be voided directly. Use Sale Return to issue a credit note; keep the original invoice and its audit history.');
            }
            $access->authorizeWarehouse((int) $sale->warehouse_id);
            if ((int) $sale->sale_status === 3) {
                $this->reject('Drafts do not require a financial void; use Delete Draft.');
            }
            if ($sale->returns()->lockForUpdate()->exists()) {
                $this->reject('A linked return or refund exists. Reverse it through the approved return workflow before voiding this sale.');
            }
            if (AccountingPeriod::where('is_closed', true)
                ->where('start_date', '<=', $sale->created_at->toDateString())
                ->where('end_date', '>=', $sale->created_at->toDateString())->exists()) {
                $this->reject('This sale belongs to a closed accounting period and cannot be voided.');
            }

            $lines = Product_Sale::where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get();
            $payments = Payment::where('sale_id', $sale->id)->whereNull('return_id')->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $payment) {
                if ($payment->cash_register_id) {
                    $register = CashRegister::withoutGlobalScopes()->whereKey($payment->cash_register_id)->lockForUpdate()->first();
                    if (!$register || !$register->status || $register->reconciliation()->exists()) {
                        $this->reject('A payment is attached to a closed or reconciled register. Contact an administrator for an approved correction.');
                    }
                }
                if (in_array(strtolower((string) $payment->paying_method), ['credit card', 'paypal', 'upi', 'razorpay', 'pesapal'], true)) {
                    $this->reject('This sale has an externally settled payment. Complete the provider refund before an operational void.');
                }
            }

            $this->restoreInventory($sale, $lines);
            app(\App\Services\ImportCostAllocationService::class)->reverseSaleCost($sale);

            foreach ($payments as $payment) {
                $result = app(AccountingService::class)->reverseTransaction(Payment::class, $payment->id, '_voided');
                $this->requireAccountingReversal($result, 'A payment journal could not be reversed safely.');

                if ($payment->paying_method === 'Deposit') {
                    app(CustomerDepositService::class)->restore((int) $sale->customer_id, $payment->amount);
                } elseif ($payment->paying_method === 'Points') {
                    app(RewardPointService::class)->restorePayment($payment, $sale);
                } elseif ($payment->paying_method === 'Gift Card') {
                    $link = PaymentWithGiftCard::where('payment_id', $payment->id)->lockForUpdate()->first();
                    $card = $link ? GiftCard::whereKey($link->gift_card_id)->lockForUpdate()->first() : null;
                    if (!$card || (float) $card->expense + 0.0001 < (float) $payment->amount) {
                        $this->reject('Gift-card consumption cannot be restored from authoritative payment evidence.');
                    }
                    $card->expense = bcsub((string) $card->expense, (string) $payment->amount, 4);
                    $card->save();
                }
                $payment->accounting_status = 'reversed';
                $payment->saveQuietly();
            }

            app(RewardPointService::class)->reverseSaleEarningForVoid($sale);
            $saleResult = app(AccountingService::class)->reverseTransaction(Sale::class, $sale->id, '_voided');
            $this->requireAccountingReversal($saleResult, 'The sale journal could not be reversed safely.');

            if ($sale->coupon_id) {
                $coupon = Coupon::whereKey($sale->coupon_id)->lockForUpdate()->first();
                if ($coupon && (int) $coupon->used > 0) {
                    $coupon->used = (int) $coupon->used - 1;
                    $coupon->save();
                }
            }

            $sale->forceFill([
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $reason,
                'deleted_by' => $user->id,
                'accounting_status' => 'reversed',
            ])->saveQuietly();
            \App\Models\ActivityLog::create([
                'date' => now()->toDateString(),
                'user_id' => $user->id,
                'action' => 'Sale Voided',
                'reference_no' => $sale->reference_no,
                'item_description' => 'Void reason: ' . $reason,
            ]);
            $sale->delete();

            return $sale;
        }, 3);
    }

    private function restoreInventory(Sale $sale, $lines): void
    {
        $productIds = $lines->pluck('product_id')->unique()->sort()->values();
        $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== $productIds->count()) {
            $this->reject('One or more sale products no longer exist, so stock cannot be restored safely.');
        }
        if ($products->contains('type', 'combo')) {
            $this->reject('Combo-component reversal is not yet provable for this sale. Use an administrator-approved correction.');
        }

        $variantIds = $lines->pluck('product_variant_id')->filter()->unique()->sort()->values();
        $variants = ProductVariant::whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $batchIds = $lines->pluck('product_batch_id')->filter()->unique()->sort()->values();
        $batches = ProductBatch::whereIn('id', $batchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $warehouseRows = Product_Warehouse::where('warehouse_id', $sale->warehouse_id)
            ->whereIn('product_id', $productIds)->orderBy('id')->lockForUpdate()->get();

        $productDemand = [];
        $variantDemand = [];
        $batchDemand = [];
        $warehouseDemand = [];
        foreach ($lines as $line) {
            $product = $products->get($line->product_id);
            if (in_array($product->type, ['service', 'digital'], true)) {
                continue;
            }
            $unit = Unit::whereKey($line->sale_unit_id)->lockForUpdate()->first();
            if (!$unit || (float) $unit->operation_value <= 0) {
                $this->reject('A sale unit is missing or invalid, so stock cannot be restored safely.');
            }
            $qty = $unit->operator === '*'
                ? (float) $line->qty * (float) $unit->operation_value
                : (float) $line->qty / (float) $unit->operation_value;

            $warehouse = $warehouseRows->first(function (Product_Warehouse $row) use ($line): bool {
                return (int) $row->product_id === (int) $line->product_id
                    && (int) $row->variant_id === (int) $line->variant_id
                    && (int) $row->product_batch_id === (int) $line->product_batch_id;
            });
            if (!$warehouse) {
                $this->reject('The original warehouse stock row is missing, so stock cannot be restored safely.');
            }

            $productDemand[$product->id] = ($productDemand[$product->id] ?? 0) + $qty;
            if ($line->product_variant_id) {
                if (!$variants->has($line->product_variant_id)) {
                    $this->reject('The sold variant no longer exists, so stock cannot be restored safely.');
                }
                $variantDemand[$line->product_variant_id] = ($variantDemand[$line->product_variant_id] ?? 0) + $qty;
            }
            if ($line->product_batch_id) {
                if (!$batches->has($line->product_batch_id)) {
                    $this->reject('The sold batch no longer exists, so stock cannot be restored safely.');
                }
                $batchDemand[$line->product_batch_id] = ($batchDemand[$line->product_batch_id] ?? 0) + $qty;
            }
            $warehouseDemand[$warehouse->id]['row'] = $warehouse;
            $warehouseDemand[$warehouse->id]['qty'] = ($warehouseDemand[$warehouse->id]['qty'] ?? 0) + $qty;

            $serials = $this->serials($line->imei_number);
            if ($product->is_imei || $serials) {
                if (!$serials || abs($qty - count($serials)) > 0.0001) {
                    $this->reject('IMEI evidence is missing or does not match the sold quantity.');
                }
                foreach ($serials as $serial) {
                    $this->assertSerialCanReturn($serial, $line, $sale);
                    $warehouseDemand[$warehouse->id]['serials'][] = $serial;
                }
            }
        }

        foreach ($productDemand as $id => $qty) {
            $product = $products->get($id); $product->qty += $qty; $product->save();
        }
        foreach ($variantDemand as $id => $qty) {
            $variant = $variants->get($id); $variant->qty += $qty; $variant->save();
        }
        foreach ($batchDemand as $id => $qty) {
            $batch = $batches->get($id); $batch->qty += $qty; $batch->save();
        }
        foreach ($warehouseDemand as $item) {
            $warehouse = $item['row'];
            $existing = $this->serials($warehouse->imei_number);
            $serials = $item['serials'] ?? [];
            if (array_intersect($existing, $serials)) {
                $this->reject('An IMEI is already present in inventory; no stock was restored.');
            }
            $warehouse->qty += $item['qty'];
            $warehouse->imei_number = array_merge($existing, $serials) ? implode(',', array_merge($existing, $serials)) : null;
            $warehouse->save();
        }
    }

    private function assertSerialCanReturn(string $serial, Product_Sale $line, Sale $sale): void
    {
        $needle = str_replace(' ', '', $serial);
        $inStock = Product_Warehouse::where('product_id', $line->product_id)
            ->whereRaw("FIND_IN_SET(?, REPLACE(COALESCE(imei_number, ''), ' ', '')) > 0", [$needle])->exists();
        $resold = Product_Sale::where('product_id', $line->product_id)->where('id', '!=', $line->id)
            ->where('created_at', '>=', $sale->created_at)
            ->whereRaw("FIND_IN_SET(?, REPLACE(COALESCE(imei_number, ''), ' ', '')) > 0", [$needle])->exists();
        $returned = ProductReturn::where('product_id', $line->product_id)
            ->whereRaw("FIND_IN_SET(?, REPLACE(COALESCE(imei_number, ''), ' ', '')) > 0", [$needle])->exists();
        $transferred = ProductTransfer::where('product_id', $line->product_id)
            ->where('created_at', '>=', $sale->created_at)
            ->whereRaw("FIND_IN_SET(?, REPLACE(COALESCE(imei_number, ''), ' ', '')) > 0", [$needle])->exists();
        if ($inStock || $resold || $returned || $transferred) {
            $this->reject('An IMEI was already restored, returned, transferred, or reused after this sale.');
        }
    }

    private function requireAccountingReversal($result, string $message): void
    {
        if (!$result->success || (app(AccountingModeService::class)->isDoubleEntryAuthoritative() && !$result->isPosted())) {
            $this->reject($message);
        }
    }

    private function serials(?string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $csv)), fn ($value) => $value !== '' && strtolower($value) !== 'null')));
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['sale' => $message]);
    }
}
