<?php

namespace App\Services;

use App\Models\{Sale, Returns, Payment, Product_Sale, Product, ProductVariant, ProductBatch, Product_Warehouse, AccountingPeriod, JournalEntry};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleDeletionGuard
{
    public function lockAndValidate(int $id): Sale
    {
        $sale = app(SalePaymentIntegrity::class)->lockSale($id);
        // The controller's early warning is not sufficient: recheck under the
        // sale row lock so an invoice cannot be issued between check and delete.
        $zatca = app(ZatcaIntegrationService::class);
        if ($zatca->isSourceLocked('sale', $id)
            || ($zatca->isPhase2Configured() && (int) $sale->sale_status !== 3)) {
            $this->reject('A Phase 2 fiscal sale cannot be deleted. Keep the original invoice and issue a Sale Return/credit note instead.');
        }
        $lines = Product_Sale::where('sale_id', $id)->orderBy('id')->lockForUpdate()->get();
        $returns = Returns::where('sale_id', $id)->orderBy('id')->lockForUpdate()->get();
        $payments = Payment::where('sale_id', $id)->orderBy('id')->lockForUpdate()->get();
        if ($returns->isNotEmpty() || $payments->contains(fn ($p) => $p->return_id)
            || $lines->contains(fn ($line) => (float) $line->return_qty > 0)) {
            $this->reject('A linked return or refund exists. Keep the audit trail and use the approved return correction workflow.');
        }
        if ($lines->contains(fn ($line) => !empty($line->imei_number))) {
            $this->reject('This sale contains IMEI/serial evidence. Use Void Sale so the exact serial and financial history can be reversed safely.');
        }
        if (AccountingPeriod::where('is_closed', true)->where('start_date', '<=', $sale->created_at->toDateString())
            ->where('end_date', '>=', $sale->created_at->toDateString())->exists()) {
            $this->reject('This sale belongs to a closed accounting period. It cannot be deleted or voided.');
        }
        // Payment rows have no archive/void state. Preserve journal sources
        // until that broader lifecycle is available.
        if ($payments->isNotEmpty()) {
            $this->reject('This sale has posted payments. Use Void Sale to preserve and reverse its financial history.');
        }
        $products = Product::whereIn('id', $lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
        if ($products->contains('type', 'combo')) {
            $this->reject('This sale contains combo components whose stock history must be preserved. Contact an administrator for an approved correction.');
        }
        if ($products->contains(fn ($p) => $p->is_imei)) {
            $this->reject('This sale contains an IMEI product. Use Void Sale; destructive deletion is not permitted.');
        }
        ProductVariant::whereIn('product_id', $products->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        ProductBatch::whereIn('id', $lines->pluck('product_batch_id')->filter())->orderBy('id')->lockForUpdate()->get();
        $stocks = Product_Warehouse::where('warehouse_id', $sale->warehouse_id)->whereIn('product_id', $products->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get();
        if (in_array((int) $sale->sale_status, [1, 5, 6], true)) {
            foreach ($lines as $line) {
                $product = $products->firstWhere('id', $line->product_id);
                if (!$product) $this->reject('A sold product no longer exists, so deletion cannot restore stock safely.');
                if (in_array($product->type, ['service', 'digital'], true)) continue;
                if (!$line->sale_unit_id || !$stocks->contains(fn ($stock) =>
                    (int) $stock->product_id === (int) $line->product_id
                    && (int) $stock->variant_id === (int) $line->variant_id
                    && (int) $stock->product_batch_id === (int) $line->product_batch_id)) {
                    $this->reject('Authoritative unit or warehouse stock evidence is missing. Contact an administrator before correcting this sale.');
                }
            }
        }
        return $sale;
    }

    private function reject(?string $message = null): never
    {
        throw ValidationException::withMessages(['sale' => $message ?: __('integrity.delete_sale')]);
    }
}
