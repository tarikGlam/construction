<?php

namespace App\Services\Domain;

use InvalidArgumentException;

use App\Models\Returns;
use App\Models\Sale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Product_Warehouse;
use App\Models\ProductBatch;
use App\Models\ProductReturn;
use App\Models\Product_Sale;
use App\Models\Payment;
use App\Models\Account;
use App\Models\CashRegister;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variant;
use App\Services\AccountingService;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ReturnDomainService
{
    protected InvoiceService $invoiceService;
    protected AccountingService $accountingService;

    public function __construct(?InvoiceService $invoiceService = null, ?AccountingService $accountingService = null)
    {
        $this->invoiceService = $invoiceService ?? app(InvoiceService::class);
        $this->accountingService = $accountingService ?? app(AccountingService::class);
    }

    /**
     * Create a Sale Return transaction, restore stock, issue refund if applicable, and post GL entries.
     *
     * @param array $data
     * @param User|null $user
     * @return Returns
     */
    public function createReturn(array $data, ?User $user = null): Returns
    {
        return DB::transaction(function () use ($data, $user) {
            $userId = $user ? $user->id : (Auth::id() ?? 1);

            $sale = app(\App\Services\SalePaymentIntegrity::class)->lockSale((int) $data['sale_id']);

            $zatcaTicket = app(\App\Services\ZatcaIntegrationService::class)->beginReturnRequest($sale, $data, (int) $userId);
            if ($zatcaTicket !== null && $zatcaTicket['return'] !== null) { return $zatcaTicket['return']; }
            $data = app(\App\Services\ZatcaIntegrationService::class)->prepareReturnData($sale, $data);
            $sourceLines = $this->resolveReturnSourceLines($sale, $data);
            $fiscalLines = null;
            if (isset($data['_zatca_return_plan'])) {
                $fiscalLines = app(SaleReturnImeiService::class)->validateAndPrepareStore($data, $sale);
                app(SaleReturnImeiService::class)->validateFiscalStock($fiscalLines, $data);
            }

            // A fully discounted coupon sale has no monetary value to return.
            // Its physical item still goes back into stock below.
            if ($fiscalLines === null && (float) $sale->grand_total === 0.0 && (float) $sale->coupon_discount > 0) {
                if (!empty($data['refund'])) {
                    throw new InvalidArgumentException('A zero-value sale cannot issue a cash refund.');
                }
                $data['total_discount'] = (float) ($data['total_price'] ?? 0) + (float) ($data['order_tax'] ?? 0);
                $data['grand_total'] = 0;
            }

            $returnReference = $data['reference_no'] ?? $this->invoiceService->generateInvoiceName('rr-');

            $refund = $data['refund'] ?? 0;
            $hasRefund = (bool) $refund;

            if ($hasRefund && empty(trim((string) ($data['paying_method'] ?? '')))) {
                throw new InvalidArgumentException('Refund payment method is required.');
            }

            $accountId = null;
            if ($hasRefund) {
                $accountId = $data['account_id'] ?? Account::where('is_default', true)->value('id') ?? 1;
            }

            $returnData = [
                'reference_no' => $returnReference,
                'sale_id' => $sale->id,
                'user_id' => $userId,
                'customer_id' => $sale->customer_id,
                'warehouse_id' => $sale->warehouse_id,
                'biller_id' => $sale->biller_id,
                'currency_id' => $sale->currency_id,
                'exchange_rate' => $sale->exchange_rate,
                'account_id' => $accountId,
                'item' => count($data['product_id']),
                'total_qty' => array_sum($data['qty']),
                'total_discount' => $data['total_discount'] ?? 0,
                'total_tax' => $data['total_tax'] ?? 0,
                'total_price' => $data['total_price'] ?? 0,
                'order_tax_rate' => $data['order_tax_rate'] ?? 0,
                'order_tax' => $data['order_tax'] ?? 0,
                'grand_total' => $data['grand_total'],
                'return_note' => $data['return_note'] ?? null,
                'staff_note' => $data['staff_note'] ?? null,
                'created_at' => isset($data['created_at']) ? normalize_to_sql_datetime($data['created_at']) : date('Y-m-d H:i:s'),
            ];

            $returnObj = Returns::create($returnData);

            // Refund payment logic if applicable
            if ($hasRefund) {
                $cashRegister = CashRegister::where([
                    ['user_id', $userId],
                    ['warehouse_id', $sale->warehouse_id],
                    ['status', true]
                ])->first();

                $refundAmount = $data['refund_amount'] ?? $data['grand_total'];
                $payingMethod = trim((string) $data['paying_method']);
                $paymentRef = $this->invoiceService->generateInvoiceName('spr-');

                $paymentData = [
                    'payment_reference' => $paymentRef,
                    'sale_id' => $sale->id,
                    'return_id' => $returnObj->id,
                    'cash_register_id' => $cashRegister ? $cashRegister->id : null,
                    'user_id' => $userId,
                    'account_id' => $accountId,
                    'amount' => $refundAmount,
                    'paying_method' => $payingMethod,
                    'currency_id' => $sale->currency_id,
                    'exchange_rate' => $sale->exchange_rate ?: 1,
                    'created_at' => $returnObj->created_at,
                    'updated_at' => now(),
                ];

                $refundPayment = Payment::create($paymentData);
                $resPayment = $this->accountingService->recordPayment($refundPayment);
                if (!$resPayment->success || (isset($data['_zatca_return_plan']) && !$resPayment->isPosted())) {
                    if (isset($data['_zatca_return_plan'])) {
                        throw new \App\Exceptions\AccountingException($resPayment->error ?? 'Fiscal refund journal failed.');
                    }
                    throw new \RuntimeException($resPayment->error ?: 'Sale return refund accounting posting failed.');
                }
            }

            // Restore product stock and record ProductReturn
            foreach ($data['product_id'] as $key => $proId) {
                $productSale = $sourceLines[$key];
                $product = Product::findOrFail($proId);
                $saleUnitName = $data['sale_unit'][$key] ?? 'n/a';
                $saleUnit = null;
                $variantId = null;
                $quantity = (float) $data['qty'][$key];

                if ($fiscalLines !== null) {
                    $authoritative = $fiscalLines[$key];
                    $saleUnit = $authoritative['unit'];
                    $variantId = $authoritative['variant_id'];
                    if (!$authoritative['product']->is_imei) {
                        app(SaleReturnImeiService::class)->restoreFiscalStock($authoritative);
                    }
                } elseif ($saleUnitName !== 'n/a') {
                    $saleUnit = Unit::where('unit_name', $saleUnitName)->first();
                    if ($saleUnit) {
                        if ($saleUnit->operator == '*') {
                            $quantity = $quantity * $saleUnit->operation_value;
                        } elseif ($saleUnit->operator == '/') {
                            $quantity = $quantity / $saleUnit->operation_value;
                        }
                    }

                    if ($product->is_variant) {
                        $productCode = $data['product_code'][$key] ?? '';
                        $productVariant = ProductVariant::select('id', 'variant_id', 'qty')
                            ->FindExactProductWithCode($proId, $productCode)
                            ->first();

                        if ($productVariant) {
                            $variantId = $productVariant->variant_id;
                            $productWarehouse = Product_Warehouse::FindProductWithVariant($proId, $variantId, $sale->warehouse_id)->first();
                            $productVariant->qty += $quantity;
                            $productVariant->save();
                        } else {
                            $productWarehouse = Product_Warehouse::FindProductWithoutVariant($proId, $sale->warehouse_id)->first();
                        }
                    } elseif (!empty($data['product_batch_id'][$key])) {
                        $batchId = $data['product_batch_id'][$key];
                        $productWarehouse = Product_Warehouse::where([
                            ['product_batch_id', $batchId],
                            ['warehouse_id', $sale->warehouse_id]
                        ])->first();
                        $productBatch = ProductBatch::find($batchId);
                        if ($productBatch) {
                            $productBatch->qty += $quantity;
                            $productBatch->save();
                        }
                    } else {
                        $productWarehouse = Product_Warehouse::FindProductWithoutVariant($proId, $sale->warehouse_id)->first();
                    }

                    if (!$productWarehouse) {
                        $productWarehouse = Product_Warehouse::create([
                            'product_id' => $proId,
                            'warehouse_id' => $sale->warehouse_id,
                            'qty' => 0,
                        ]);
                    }

                    $product->qty += $quantity;
                    $productWarehouse->qty += $quantity;
                    $product->save();
                    $productWarehouse->save();
                } elseif ($product->type === 'combo') {
                    // Combo product child stock restoration
                    $productList = explode(',', $product->product_list);
                    $variantList = !empty($product->variant_list) ? explode(',', $product->variant_list) : [];
                    $qtyList = explode(',', $product->qty_list);

                    foreach ($productList as $idx => $childId) {
                        $childProduct = Product::find($childId);
                        if (!$childProduct) continue;

                        $req = (float) $qtyList[$idx];
                        $returnQty = $data['qty'][$key] * $req;

                        if (count($variantList) && !empty($variantList[$idx])) {
                            $childPV = ProductVariant::where([
                                ['product_id', $childId],
                                ['variant_id', $variantList[$idx]]
                            ])->first();
                            $childPW = Product_Warehouse::where([
                                ['product_id', $childId],
                                ['variant_id', $variantList[$idx]],
                                ['warehouse_id', $sale->warehouse_id]
                            ])->first();

                            if ($childPV) {
                                $childPV->qty += $returnQty;
                                $childPV->save();
                            }
                        } else {
                            $childPW = Product_Warehouse::where([
                                ['product_id', $childId],
                                ['warehouse_id', $sale->warehouse_id]
                            ])->first();
                        }

                        $childProduct->qty += $returnQty;
                        if ($childPW) {
                            $childPW->qty += $returnQty;
                            $childPW->save();
                        }
                        $childProduct->save();
                    }
                }

                $createdProductReturn = ProductReturn::create([
                    'return_id' => $returnObj->id,
                    'product_sale_id' => $productSale->id,
                    'product_id' => $proId,
                    'product_batch_id' => $fiscalLines !== null ? $fiscalLines[$key]['product_batch_id'] : ($data['product_batch_id'][$key] ?? null),
                    'variant_id' => $variantId,
                    'imei_number' => $fiscalLines !== null ? $fiscalLines[$key]['canonical_imei_str'] : ($data['imei_number'][$key] ?? null),
                    'qty' => $data['qty'][$key],
                    'sale_unit_id' => isset($saleUnit) ? $saleUnit->id : 0,
                    'net_unit_price' => $data['net_unit_price'][$key],
                    'discount' => $data['discount'][$key] ?? 0,
                    'tax_rate' => $data['tax_rate'][$key] ?? 0,
                    'tax' => $data['tax'][$key] ?? 0,
                    'total' => $data['subtotal'][$key],
                ]);

                app(\App\Services\ImportCostAllocationService::class)->allocateReturnCost(
                    $returnObj,
                    $createdProductReturn,
                    $sale->id
                );

                $productSale->return_qty += $data['qty'][$key];
                $productSale->save();
            }

            app(\App\Services\ZatcaIntegrationService::class)->persistReturnPricing($returnObj, $data);
            if ($fiscalLines !== null) {
                app(SaleReturnImeiService::class)->applyStoreImeis((int) $sale->warehouse_id, $fiscalLines);
            }

            if (!empty($data['change_sale_status'])) {
                $sale->sale_status = 4; // Returned
                $sale->save();
            }

            // Post Accounting GL Entries for Sale Return
            if ((float) $returnObj->grand_total > 0 || isset($data['_zatca_return_plan'])) {
                $resReturn = $this->accountingService->recordSaleReturn($returnObj, 'sale_return_created');
                if (!$resReturn->success || (isset($data['_zatca_return_plan']) && !$resReturn->isPosted())) {
                    if (isset($data['_zatca_return_plan'])) {
                        throw new \App\Exceptions\AccountingException($resReturn->error ?? 'Fiscal return journal failed.');
                    }
                    throw new \RuntimeException($resReturn->error ?: 'Sale return accounting posting failed.');
                }
            }

            app(\App\Services\ZatcaIntegrationService::class)->completeReturnRequest($returnObj, $zatcaTicket);
            return $returnObj;
        });
    }

    /** Resolve once under the sale transaction, before any refund/stock writes. */
    protected function resolveReturnSourceLines(Sale $sale, array $data): array
    {
        $products = $data['product_id'] ?? [];
        $quantities = $data['qty'] ?? [];
        $explicitIds = array_key_exists('product_sale_id', $data);
        if (! is_array($products) || $products === [] || ! is_array($quantities)
            || array_keys($products) !== array_keys($quantities)
            || ($explicitIds && (! is_array($data['product_sale_id'])
                || array_keys($products) !== array_keys($data['product_sale_id'])))) {
            throw new InvalidArgumentException('Return products, quantities and original line IDs must match.');
        }
        $lines = Product_Sale::query()->where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $resolved = $seen = [];
        foreach ($products as $key => $productId) {
            if ($explicitIds) {
                $id = $data['product_sale_id'][$key];
                $line = is_scalar($id) && preg_match('/^[1-9][0-9]*$/D', (string) $id) ? $lines->get($id) : null;
            } else {
                // Legacy callers may omit IDs only when the product has exactly
                // one original line. Never guess between prices/variants/batches.
                $matches = $lines->where('product_id', $productId);
                $line = $matches->count() === 1 ? $matches->first() : null;
            }
            if (! $line || (int) $line->product_id !== (int) $productId || isset($seen[$line->id])) {
                throw new InvalidArgumentException('Identify each returned item by its distinct original sale line.');
            }
            $quantity = $quantities[$key];
            if (! is_numeric($quantity) || ! is_finite((float) $quantity) || (float) $quantity <= 0
                || (float) $quantity + (float) $line->return_qty > (float) $line->qty + 0.000001) {
                throw new InvalidArgumentException('Return quantity exceeds the remaining original sale line quantity.');
            }
            $resolved[$key] = $line;
            $seen[$line->id] = true;
        }

        return $resolved;
    }
}
