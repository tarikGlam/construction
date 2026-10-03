<?php

namespace App\Services\Domain;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\SaleValidationException;
use App\Models\Sale;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Product_Sale;
use App\Models\Product_Warehouse;
use App\Models\ProductBatch;
use App\Models\Payment;
use App\Models\PosPaymentAttempt;
use App\Models\Customer;
use App\Models\Account;
use App\Models\CashRegister;
use App\Models\GiftCard;
use App\Models\PaymentWithGiftCard;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CustomerCreditService;
use App\Services\CustomerDepositService;
use App\Services\Domain\TaxCalculationService;
use App\Services\InvoiceService;
use App\Services\WarehouseAccessService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaleDomainService
{
    protected InvoiceService $invoiceService;
    protected AccountingService $accountingService;
    protected CustomerCreditService $creditService;
    protected CustomerDepositService $customerDeposits;
    protected TaxCalculationService $taxCalculationService;

    public function __construct(
        ?InvoiceService $invoiceService = null,
        ?AccountingService $accountingService = null,
        ?CustomerCreditService $creditService = null,
        ?CustomerDepositService $customerDeposits = null,
        ?TaxCalculationService $taxCalculationService = null
    ) {
        $this->invoiceService = $invoiceService ?? app(InvoiceService::class);
        $this->accountingService = $accountingService ?? app(AccountingService::class);
        $this->creditService = $creditService ?? app(CustomerCreditService::class);
        $this->customerDeposits = $customerDeposits ?? app(CustomerDepositService::class);
        $this->taxCalculationService = $taxCalculationService ?? app(TaxCalculationService::class);
    }

    /**
     * Create a Sale transaction, update stock levels, handle payments, and record GL accounting entries.
     *
     * @param array $data
     * @param User|null $user
     * @return Sale
     */
    public function createSale(array $data, ?User $user = null): Sale
    {
        // Check before idempotency replay, reference generation or stock/payment work.
        app(\App\Services\ZatcaIntegrationService::class)->assertConfiguredPhase2ModuleAvailable();
        app(\App\Services\ZatcaIntegrationService::class)->validateOfflineReplay($data);
        app(\App\Services\ZatcaIntegrationService::class)->validatePhase2RequestIdentity($data);
        $user = $user ?? auth()->user();
        if ($user) {
            $data['user_id'] = $user->id;
        }

        // 1. Idempotency Replay Check (Stage 2 & Stage 3: BEFORE any sequence generation, catalog mutation, or tax resolution)
        $idempotencyKey = !empty($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;
        $fingerprint = null;
        if ($idempotencyKey !== null) {
            $fingerprint = $this->computeCanonicalRequestFingerprint($data, $user);
            $data['idempotency_key'] = $idempotencyKey;
            $data['idempotency_fingerprint'] = $fingerprint;

            $existingSale = Sale::where('idempotency_key', $idempotencyKey)->first();
            if ($existingSale) {
                // Scope check: User authorization
                if ($user && (int) $existingSale->user_id !== (int) $user->id) {
                    throw new IdempotencyConflictException(__('db.idempotency_conflict') ?: 'Idempotency key reused with mismatched transaction payload.');
                }

                // Scope check: Operational warehouse scope
                $warehouseAccess = app(WarehouseAccessService::class);
                if ($user && $warehouseAccess->isWarehouseOperational($user) && (int) $existingSale->warehouse_id !== (int) $user->warehouse_id) {
                    throw new IdempotencyConflictException(__('db.idempotency_conflict') ?: 'Idempotency key reused with mismatched transaction payload.');
                }

                // Winner must have a non-empty stored fingerprint
                $storedFingerprint = (string) $existingSale->idempotency_fingerprint;
                if ($storedFingerprint === '' || !hash_equals($storedFingerprint, (string) $fingerprint)) {
                    throw new IdempotencyConflictException(__('db.idempotency_conflict') ?: 'Idempotency key reused with mismatched transaction payload.');
                }

                $existingSale->was_replayed = true;
                return $existingSale;
            }
        }

        if (isset($data['created_at'])) {
            $data['created_at'] = normalize_to_sql_datetime($data['created_at']);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        // 2. Reference Generation
        if (!isset($data['reference_no']) || empty($data['reference_no'])) {
            if (isset($data['pos']) && $data['pos']) {
                $data['reference_no'] = $this->invoiceService->generateInvoiceName('posr-', true);
            } else {
                $data['reference_no'] = $this->invoiceService->generateInvoiceName('sr-', true);
            }
        }

        // 3. Active Cash Register
        $cashRegister = CashRegister::where([
            ['user_id', $data['user_id'] ?? 1],
            ['warehouse_id', $data['warehouse_id'] ?? 1],
            ['status', true]
        ])->first() ?? CashRegister::where('status', true)->first();

        if ($cashRegister) {
            $data['cash_register_id'] = $cashRegister->id;
        }

        // 4. Normalize Paid Amount & Payment Status
        $paidAmountArray = $data['paid_amount'] ?? 0;
        $totalPaidAmount = is_array($paidAmountArray) ? array_sum($paidAmountArray) : (float)$paidAmountArray;

        $grandTotal = (float)($data['grand_total'] ?? 0);
        $balance = $grandTotal - $totalPaidAmount;

        if (!isset($data['payment_status'])) {
            if ($totalPaidAmount <= 0) {
                $data['payment_status'] = 1; // Pending/Unpaid
            } elseif ($balance > 0.001) {
                $data['payment_status'] = 2; // Due/Partial
            } else {
                $data['payment_status'] = 4; // Paid
            }
        }

        if (!isset($data['sale_status'])) {
            $data['sale_status'] = 1; // Completed
        }

        if (empty($data['currency_id'])) {
            $data['currency_id'] = data_get(cache()->get('general_setting'), 'currency', 1) ?: 1;
        }

        $baseCurrency = app(\App\Services\Accounting\CurrencyNormalizationService::class)->getBaseCurrencyId();
        $data['exchange_rate'] = app(\App\Services\TransactionExchangeRate::class)->validate(
            $data['exchange_rate'] ?? ((int) $data['currency_id'] === $baseCurrency ? '1' : null)
        );

        if (!isset($data['item']) && isset($data['product_id'])) {
            $data['item'] = count($data['product_id']);
        }
        if (!isset($data['total_qty']) && isset($data['qty'])) {
            $data['total_qty'] = array_sum($data['qty']);
        }
        if (!isset($data['total_price']) && isset($data['subtotal'])) {
            $data['total_price'] = array_sum($data['subtotal']);
        }
        $data['total_discount'] = $data['total_discount'] ?? 0;
        $data['total_tax'] = $data['total_tax'] ?? 0;
        $data['order_tax_rate'] = $data['order_tax_rate'] ?? 0;
        $data['order_tax'] = $data['order_tax'] ?? 0;
        $data['order_discount'] = $data['order_discount'] ?? 0;
        $data['shipping_cost'] = $data['shipping_cost'] ?? 0;

        $submittedMethods = array_map(
            'strtolower',
            array_map('strval', (array) ($data['paid_by_id'] ?? $data['paying_method'] ?? [])),
        );
        $rewardCustomerId = (int) ($data['customer_id'] ?? 0);
        if ($rewardCustomerId > 0 && array_intersect($submittedMethods, ['7', 'points'])) {
            app(\App\Services\RewardPointService::class)->sweepExpiredPoints($rewardCustomerId);
        }

        try {
            return DB::transaction(function () use ($data, $user, $paidAmountArray, $cashRegister, $totalPaidAmount) {
                // Stage 4: Authoritative Resolution & Deterministic Locking of All Lines
                $resolvedLines = $this->resolveAndLockSaleLines($data, $user, (int)($data['warehouse_id'] ?? 0), (int)($data['sale_status'] ?? 1));
                if (in_array((int) ($data['sale_status'] ?? 1), [1, 5], true)) {
                    foreach ($resolvedLines as $resolvedLine) {
                        if (!is_array($resolvedLine) || ($resolvedLine['product']->type ?? null) !== 'combo') {
                            continue;
                        }
                        foreach ($resolvedLine['combo_children'] as $child) {
                            $query = \App\Models\ImportStockLayer::where('warehouse_id', (int) $data['warehouse_id'])
                                ->where('product_id', $child['child_product']->id)
                                ->where('variant_id', $child['child_variant']?->variant_id)
                                ->where('remaining_qty', '>', 0);
                            if ($query->exists()) {
                                throw new SaleValidationException('Imported combo components require component-level provenance support before sale.');
                            }
                        }
                    }
                }

                $preparedIndiaGstSale = null;
                if (class_exists(\Modules\IndiaGST\Services\Calculation\IndiaGstSaleTransactionService::class)) {
                    $preparedIndiaGstSale = app(\Modules\IndiaGST\Services\Calculation\IndiaGstSaleTransactionService::class)
                        ->prepareSaleData($data, $user);
                    $data = $preparedIndiaGstSale->saleData;
                }

                if (!$preparedIndiaGstSale || !$preparedIndiaGstSale->isIndiaGstApplicable()) {
                    $dp = (int) (data_get(cache()->get('general_setting'), 'decimal', 2) ?: 2);
                    $data = $this->taxCalculationService->reconcileSaleTotals($data, $dp, $user, $resolvedLines);
                }

                // Optional module validation must precede sale, stock and payment writes.
                $data = app(\App\Services\ZatcaIntegrationService::class)->prepareSaleData($data);

                if (!isset($data['grand_total'])) {
                    $data['grand_total'] = round(
                        ((float)($data['total_price'] ?? 0) + (float)($data['order_tax'] ?? 0) + (float)($data['shipping_cost'] ?? 0))
                        - (float)($data['order_discount'] ?? 0) - (float)($data['coupon_discount'] ?? 0),
                        $dp ?? 2
                    );
                }

                $grandTotal = (float) ($data['grand_total'] ?? 0);
                $balance = $grandTotal - $totalPaidAmount;
                if (!isset($data['payment_status']) || class_exists(\Modules\IndiaGST\Services\Calculation\IndiaGstSaleTransactionService::class)) {
                    $data['payment_status'] = $totalPaidAmount <= 0
                        ? 1
                        : ($balance > 0.001 ? 2 : 4);
                }

                // Validate Credit Limit
                $isDraft = (isset($data['sale_status']) && (int)$data['sale_status'] === 3);
                if ($isDraft) {
                    $data['paid_amount'] = 0;
                    $data['payment_status'] = 1;
                }
                $validation = $this->creditService->validateCreditLimit(
                    $data['customer_id'],
                    floatval($data['grand_total']),
                    $totalPaidAmount,
                    null,
                    $isDraft
                );

                if (!$validation['allowed']) {
                    $creditField = (isset($validation['message']) && str_contains(strtolower($validation['message']), 'customer')) ? 'customer_id' : 'credit_limit';
                    throw new SaleValidationException($validation['message'], $creditField);
                }

                // Create Sale header
                $saleHeaderData = $data;
                $saleHeaderData['paid_amount'] = $isDraft ? 0 : $totalPaidAmount;
                $sale = Sale::create($saleHeaderData);

                // Persist lines directly from resolved rows (no continue; skipping)
                $shouldDeductStock = in_array((int)$sale->sale_status, [1, 5], true);

                // Authoritative Aggregate Stock Deduction (Exact Single Mutation Per Row)
                if ($shouldDeductStock) {
                    $demandData = $resolvedLines['__demand'] ?? null;
                    $lockedData = $resolvedLines['__locked'] ?? null;

                    if ($demandData && $lockedData) {
                        $pDemand = $demandData['productDemand'] ?? [];
                        $vDemand = $demandData['variantDemand'] ?? [];
                        $pwDem = $demandData['pwDemand'] ?? [];
                        $bDemand = $demandData['batchDemand'] ?? [];

                        $lProducts = $lockedData['products'];
                        $lVariants = $lockedData['variants'];
                        $lBatches = $lockedData['batches'];

                        // 1. Mutate each affected Product exactly once
                        foreach ($pDemand as $pid => $demQty) {
                            if ($demQty <= 0) {
                                continue;
                            }
                            $prod = $lProducts->get($pid);
                            if ($prod && !in_array($prod->type, ['combo', 'digital', 'service'], true)) {
                                $prod->qty -= $demQty;
                                $prod->save();
                            }
                        }

                        // 2. Mutate each affected ProductVariant exactly once
                        foreach ($vDemand as $pvId => $demQty) {
                            if ($demQty <= 0) {
                                continue;
                            }
                            $pv = $lVariants->get($pvId);
                            if ($pv) {
                                $pv->qty -= $demQty;
                                $pv->save();
                            }
                        }

                        // 3. Mutate each affected ProductBatch exactly once
                        foreach ($bDemand as $bId => $demQty) {
                            if ($demQty <= 0) {
                                continue;
                            }
                            $batch = $lBatches->get($bId);
                            if ($batch) {
                                $batch->qty -= $demQty;
                                $batch->save();
                            }
                        }

                        // 4. Mutate each affected Product_Warehouse exactly once
                        $soldByWarehouse = [];
                        foreach ($resolvedLines as $lineIndex => $resolvedLine) {
                            if (is_int($lineIndex) && $resolvedLine['product_warehouse'] && $resolvedLine['sold_imeis']) {
                                $rowId = $resolvedLine['product_warehouse']->id;
                                $soldByWarehouse[$rowId] = array_merge($soldByWarehouse[$rowId] ?? [], $resolvedLine['sold_imeis']);
                            }
                        }
                        foreach ($pwDem as $pwKey => $pwItem) {
                            $demQty = (float)$pwItem['demand'];
                            if ($demQty <= 0) {
                                continue;
                            }
                            $pwModel = $pwItem['model'];
                            if ($pwModel) {
                                $pwModel->qty -= $demQty;
                                if (isset($soldByWarehouse[$pwModel->id])) {
                                    $available = array_values(array_filter(array_map('trim', explode(',', (string) $pwModel->imei_number)), fn ($serial) => $serial !== ''));
                                    $remaining = array_values(array_diff($available, $soldByWarehouse[$pwModel->id]));
                                    $pwModel->imei_number = $remaining ? implode(',', $remaining) : null;
                                }
                                $pwModel->save();
                            } else {
                                $createdPw = Product_Warehouse::create([
                                    'product_id' => $pwItem['pid'],
                                    'variant_id' => $pwItem['vid'] > 0 ? $pwItem['vid'] : null,
                                    'product_batch_id' => $pwItem['bid'] > 0 ? $pwItem['bid'] : null,
                                    'warehouse_id' => $sale->warehouse_id,
                                    'qty' => -$demQty,
                                ]);
                                $pwDem[$pwKey]['model'] = $createdPw;
                            }
                        }
                    }
                }

                $reconciledLines = $data['reconciled_lines'] ?? [];
                $fiscalSaleLineIds = [];

                foreach ($resolvedLines as $i => $line) {
                    if (!is_int($i)) {
                        continue;
                    }
                    $product = $line['product'];
                    $id = $line['product_id'];
                    $requestedQty = $line['requested_qty'];
                    $deductQty = $line['deduct_qty'];
                    $pvModel = $line['product_variant'];
                    $pw = $line['product_warehouse'];

                    $rec = $reconciledLines[$i] ?? null;
                    $netUnitPrice = $rec !== null ? $rec['net_unit_price'] : ($data['net_unit_price'][$i] ?? 0);
                    $discount = $rec !== null ? $rec['discount'] : ($data['discount'][$i] ?? 0);
                    $taxRate = $rec !== null ? $rec['tax_rate'] : ($data['tax_rate'][$i] ?? 0);
                    $tax = $rec !== null ? $rec['tax'] : ($data['tax'][$i] ?? 0);
                    $total = $rec !== null ? $rec['total'] : ($data['subtotal'][$i] ?? 0);

                    $productSale = [
                        'sale_id' => $sale->id,
                        'product_id' => $id,
                        'product_batch_id' => $line['product_batch_id'],
                        'variant_id' => $line['variant_id'],
                        'product_variant_id' => $line['product_variant_id'],
                        'qty' => $line['qty'],
                        'sale_unit_id' => $line['sale_unit_id'],
                        'net_unit_price' => $netUnitPrice,
                        'discount' => $discount,
                        'tax_rate' => $taxRate,
                        'tax' => $tax,
                        'total' => $total,
                        'imei_number' => $line['imei_number'],
                    ];

                    $created_product_sale = Product_Sale::create($productSale);
                    $fiscalSaleLineIds[] = $created_product_sale->id;

                    if ($shouldDeductStock) {
                        app(\App\Services\ImportCostAllocationService::class)->allocateSaleCost(
                            $sale,
                            $created_product_sale,
                            (float)$line['deduct_qty'],
                            (float)$line['qty']
                        );
                    }


                    // Handle Restaurant Modifiers
                    if (!empty($line['resolved_modifiers'])) {
                        foreach ($line['resolved_modifiers'] as $modifierData) {
                            DB::table('product_sale_modifiers')->insert([
                                'product_sale_id' => $created_product_sale->id,
                                'modifier_group_id' => $modifierData['modifier_group_id'],
                                'modifier_id' => $modifierData['modifier_id'],
                                'modifier_group_name' => $modifierData['modifier_group_name'],
                                'modifier_name' => $modifierData['modifier_name'],
                                'price_adjustment' => $modifierData['price_adjustment'],
                                'product_list' => $modifierData['product_list'],
                                'qty_list' => $modifierData['qty_list'],
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);

                            // Ingredient stock is included in the authoritative aggregate above.
                        }
                    }
                }

                app(\App\Services\ZatcaIntegrationService::class)->persistSalePricing($sale, $data, $fiscalSaleLineIds);

                if ($preparedIndiaGstSale) {
                    app(\Modules\IndiaGST\Services\Calculation\IndiaGstSaleTransactionService::class)
                        ->finalizeSale($sale, $preparedIndiaGstSale);
                }

                // Create Payment Records & Post Accounting Payment Entries
                $alignedPaymentTuples = self::alignPaymentTuples($data);

                if (!$isDraft && $totalPaidAmount > 0 && !empty($alignedPaymentTuples)) {
                    $defaultAccount = Account::where('is_default', true)->first() ?? Account::first();
                    $accountId = !empty($data['account_id']) ? $data['account_id'] : ($defaultAccount ? $defaultAccount->id : 1);

                    foreach ($alignedPaymentTuples as $pt) {
                        $amount = (float)$pt['amount'];
                        if ($amount <= 0) {
                            continue;
                        }

                        $methodStr = $pt['method'];
                        $payingMethod = match ($methodStr) {
                            '1', 'cash' => 'Cash',
                            '2', 'gift_card', 'gift card' => 'Gift Card',
                            '3', 'credit_card', 'card' => 'Credit Card',
                            '4', 'cheque' => 'Cheque',
                            '5', 'paypal' => 'Paypal',
                            '6', 'deposit' => 'Deposit',
                            '7', 'points' => 'Points',
                            'upi' => 'UPI',
                            'razorpay' => 'Razorpay',
                            default => is_string($methodStr) ? (strtoupper($methodStr) === 'UPI' ? 'UPI' : ucfirst($methodStr)) : 'Cash',
                        };

                        $attempt = null;
                        if ($payingMethod === 'UPI') {
                            $key = $pt['index'];
                            $attemptUuid = $data['payment_attempt_id'][$key] ?? ($data['payment_attempt_id'] ?? ($data['attempt_uuid'] ?? null));
                            $paymentId = $data['razorpay_payment_id'][$key] ?? ($data['razorpay_payment_id'] ?? null);

                            $attemptQuery = PosPaymentAttempt::where('gateway', 'razorpay');
                            if ($attemptUuid) {
                                $attemptQuery->where('attempt_uuid', $attemptUuid);
                            } elseif ($paymentId) {
                                $attemptQuery->where('payment_id', $paymentId);
                            } else {
                                throw new SaleValidationException('UPI payment attempt reference is required.');
                            }

                            $attempt = $attemptQuery->lockForUpdate()->first();

                            if (!$attempt || !$attempt->isVerified()) {
                                throw new SaleValidationException('Authoritative UPI payment verification is missing or unverified.');
                            }

                            if ($attempt->isFinalized() && $attempt->sale_id !== $sale->id) {
                                throw new SaleValidationException('This UPI payment attempt has already been finalized for another sale.');
                            }

                            if (abs((float)$attempt->expected_amount - $amount) > 0.01) {
                                throw new SaleValidationException('UPI payment attempt amount does not match payment line amount.');
                            }
                        }

                        $payment = new Payment();
                        $payment->user_id = $data['user_id'];
                        $payment->sale_id = $sale->id;
                        $payment->payment_reference = $this->invoiceService->generateInvoiceName('spr-');
                        $payment->amount = $amount;
                        $payment->change = max(0, (float)($data['paying_amount'][$pt['index']] ?? $amount) - $amount);
                        $payment->paying_method = $payingMethod;
                        $paymentNote = $data['payment_note'] ?? null;
                        if ($attempt && $attempt->payment_id) {
                            $paymentNote = $paymentNote ? ($paymentNote . ' | Razorpay UPI: ' . $attempt->payment_id) : ('Razorpay UPI: ' . $attempt->payment_id . ' (Order: ' . $attempt->order_id . ')');
                        }
                        $payment->payment_note = $paymentNote;
                        $payment->account_id = $pt['account_id'] ?: $accountId;
                        $payment->currency_id = $sale->currency_id ?? 1;
                        $payment->exchange_rate = $sale->exchange_rate ?? 1;
                        $payment->payment_at = $data['created_at'];

                        if ($cashRegister) {
                            $payment->cash_register_id = $cashRegister->id;
                        }

                        $payment->save();

                        if ($payingMethod === 'Deposit') {
                            $this->customerDeposits->consume((int) $sale->customer_id, $amount);
                        }
                        if ($payingMethod === 'Points') {
                            app(\App\Services\RewardPointService::class)->redeemPayment($payment, $sale);
                        }

                        if ($payingMethod === 'Gift Card') {
                            $giftCardId = $pt['gift_card_id'];
                            if (!$giftCardId) {
                                throw new SaleValidationException(__('db.Gift card ID is required for gift card payment.'));
                            }
                            $giftCard = $resolvedLines['__locked']['gift_cards']->get((int) $giftCardId);
                            if (!$giftCard) {
                                throw new SaleValidationException(__('db.Gift card not found.'));
                            }
                            if (!$giftCard->is_active) {
                                throw new SaleValidationException(__('db.Gift card is inactive.'));
                            }
                            $today = date('Y-m-d');
                            if ($giftCard->expired_date && $giftCard->expired_date < $today) {
                                throw new SaleValidationException(__('db.Gift card has expired.'));
                            }
                            $available = bcsub((string)$giftCard->amount, (string)$giftCard->expense, 4);
                            if (bccomp((string)$amount, $available, 4) > 0) {
                                throw new SaleValidationException(__('db.Gift card balance is insufficient.'));
                            }

                            $giftCard->expense = bcadd((string)$giftCard->expense, (string)$amount, 4);

                            PaymentWithGiftCard::create([
                                'payment_id' => $payment->id,
                                'gift_card_id' => $giftCard->id,
                            ]);
                        }

                        if ($attempt) {
                            $attempt->markFinalized($sale->id, $payment->id);
                        }

                        // Record Payment in Accounting Engine
                        $resPay = $this->accountingService->recordPayment($payment);
                        if (isset($data['_zatca_sale_plan']) && !$resPay->isPosted()) {
                            throw new SaleValidationException('Adjusted fiscal payment accounting must be posted; this sale has been rolled back.');
                        }
                        if (!$resPay->success) {
                            \Log::error('Accounting failed for Sale Payment', ['payment_id' => $payment->id, 'error' => $resPay->error]);
                            throw new SaleValidationException($resPay->error ?: __('db.customer_deposit_payment_failed'));
                        }
                    }
                }

                foreach ($resolvedLines['__locked']['gift_cards'] as $giftCard) {
                    if ($giftCard->isDirty('expense')) {
                        $giftCard->save();
                    }
                }

                // Drafts do not become accounting events until finalization.
                if (!$isDraft) {
                    $resSale = $this->accountingService->recordSale(
                        $sale,
                        (string) ($data['accounting_event_type'] ?? 'sale_created')
                    );
                    if (isset($data['_zatca_sale_plan']) && !$resSale->isPosted()) {
                        throw new SaleValidationException('Adjusted fiscal sale accounting must be posted; this sale has been rolled back.');
                    }
                    if (!$resSale->success) {
                        \Log::error('Accounting failed for Sale', ['sale_id' => $sale->id, 'error' => $resSale->error]);
                        throw new SaleValidationException($resSale->error ?: __('integrity.payment_failed'));
                    }
                    app(\App\Services\RewardPointService::class)->reconcileSale($sale);
                }

                return $sale;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $driverCode = $e->errorInfo[1] ?? null;
            $message = $e->getMessage();

            // Replay recovery requires strictly ALL of:
            // 1. MySQL driver error code 1062 (ER_DUP_ENTRY)
            // 2. Exception message contains exact stable constraint name 'sales_idempotency_key_unique'
            // 3. A non-empty idempotency key
            $isExactIdempotencyDuplicate = ($driverCode === 1062)
                && str_contains($message, 'sales_idempotency_key_unique')
                && !empty($idempotencyKey);

            if (!$isExactIdempotencyDuplicate) {
                throw $e;
            }

            $warehouseAccess = app(WarehouseAccessService::class);
            for ($attempt = 0; $attempt < 5; $attempt++) {
                usleep(50000); // 50ms
                $existingSale = Sale::where('idempotency_key', $idempotencyKey)->first();
                if ($existingSale) {
                    if ($user && (int) $existingSale->user_id !== (int) $user->id) {
                        throw new IdempotencyConflictException(__('db.idempotency_conflict') ?: 'Idempotency key reused with mismatched transaction payload.');
                    }
                    if ($user && $warehouseAccess->isWarehouseOperational($user) && (int) $existingSale->warehouse_id !== (int) $user->warehouse_id) {
                        throw new IdempotencyConflictException(__('db.idempotency_conflict') ?: 'Idempotency key reused with mismatched transaction payload.');
                    }

                    $storedFingerprint = (string) $existingSale->idempotency_fingerprint;
                    if ($storedFingerprint === '' || !hash_equals($storedFingerprint, (string) $fingerprint)) {
                        throw new IdempotencyConflictException(__('db.idempotency_conflict') ?: 'Idempotency key reused with mismatched transaction payload.');
                    }

                    $existingSale->was_replayed = true;
                    return $existingSale;
                }
            }

            throw $e;
        }
    }

    /**
     * Authoritatively resolve and lock all sale lines, products, variants, batches, IMEIs,
     * combos, restaurant modifiers, and stock sufficiency inside a database transaction.
     *
     * @param array $data
     * @param User|null $user
     * @param int $warehouseId
     * @param int $saleStatus
     * @return array
     * @throws SaleValidationException
     */
    /** Read-only checkout resolution; no references, payments, stock writes or rewards. */
    public function previewFiscalSale(array $data, User $user): array
    {
        return DB::transaction(function () use ($data, $user) {
            $baseCurrency = app(\App\Services\Accounting\CurrencyNormalizationService::class)->getBaseCurrencyId();
            $data['exchange_rate'] = app(\App\Services\TransactionExchangeRate::class)->validate(
                $data['exchange_rate'] ?? ((int) ($data['currency_id'] ?? 0) === $baseCurrency ? '1' : null)
            );
            $resolved = $this->resolveAndLockSaleLines($data, $user, (int) ($data['warehouse_id'] ?? 0), (int) ($data['sale_status'] ?? 1));
            return $this->taxCalculationService->reconcileSaleTotals($data, 2, $user, $resolved);
        });
    }

    protected function resolveAndLockSaleLines(array $data, ?User $user, int $warehouseId, int $saleStatus): array
    {
        $warehouseAccess = app(WarehouseAccessService::class);
        if ($user && $warehouseAccess->isWarehouseOperational($user)) {
            if (!$warehouseAccess->hasValidWarehouseAssignment($user) || (int) $warehouseId !== (int) $user->warehouse_id) {
                throw new SaleValidationException(__('db.unauthorized_warehouse_access') ?: 'You are not authorized to create sales for this warehouse.', 'warehouse_id');
            }
        }

        $customer = Customer::find($data['customer_id'] ?? 0);
        if (!$customer) {
            throw new SaleValidationException(__('db.customer_not_found') ?: 'Customer not found.', 'customer_id');
        }

        $productIdArray = $data['product_id'] ?? [];
        if (empty($productIdArray) || !is_array($productIdArray)) {
            throw new SaleValidationException(__('db.empty_products_list') ?: 'Sale must contain at least one product.');
        }

        $productCodeArray = $data['product_code'] ?? [];
        $qtyArray = $data['qty'] ?? [];
        $saleUnitArray = $data['sale_unit'] ?? [];
        $netUnitPriceArray = $data['net_unit_price'] ?? [];
        $discountArray = $data['discount'] ?? [];
        $taxRateArray = $data['tax_rate'] ?? [];
        $taxArray = $data['tax'] ?? [];
        $subtotalArray = $data['subtotal'] ?? [];
        $imeiNumberArray = $data['imei_number'] ?? [];
        $batchIdArray = $data['product_batch_id'] ?? [];
        $variantIdArray = $data['variant_id'] ?? [];
        $productVariantIdArray = $data['product_variant_id'] ?? [];
        $enforceStock = config('without_stock') !== 'yes';
        $shouldDeductStock = in_array($saleStatus, [1, 5], true);

        // --- STEP 1: Pre-validation & Candidate Identification ---
        $lineCandidates = [];
        $productIdsToLock = [];
        $pvIdsToLock = [];
        $comboProductIdsToLock = [];
        $batchIdsToLock = [];
        $modProductIdsToLock = [];

        foreach ($productIdArray as $i => $id) {
            $id = (int)$id;
            $productIdsToLock[] = $id;

            $candProduct = Product::find($id);
            if (!$candProduct) {
                throw new SaleValidationException(__('db.product_not_found') ?: "Product with ID {$id} was not found.");
            }
            if (!$candProduct->is_active) {
                throw new SaleValidationException(__('db.product_is_inactive') ?: "Product '{$candProduct->name}' is inactive.");
            }

            $requestedQty = (float)($qtyArray[$i] ?? 0);
            if ($requestedQty <= 0) {
                throw new SaleValidationException(__('db.invalid_quantity') ?: "Quantity must be greater than zero for product '{$candProduct->name}'.");
            }

            $submittedPvId = !empty($productVariantIdArray[$i]) ? (int)$productVariantIdArray[$i] : null;
            $submittedVarId = !empty($variantIdArray[$i]) ? (int)$variantIdArray[$i] : null;
            $submittedCode = isset($productCodeArray[$i]) && trim((string)$productCodeArray[$i]) !== '' ? trim((string)$productCodeArray[$i]) : null;

            $resolvedPvId = null;
            $resolvedVarId = null;

            if ($candProduct->is_variant) {
                $isBaseCode = ($submittedCode !== null && $submittedCode === (string)$candProduct->code);
                if (empty($submittedPvId) && empty($submittedVarId) && (empty($submittedCode) || $isBaseCode)) {
                    throw new SaleValidationException("Variant identification is required for variant product '{$candProduct->name}'.");
                }

                $pvMatches = [];

                if ($submittedPvId) {
                    $m = ProductVariant::where('product_id', $id)->where('id', $submittedPvId)->first();
                    if (!$m) {
                        throw new SaleValidationException("Product variant ID {$submittedPvId} does not belong to product '{$candProduct->name}'.");
                    }
                    $pvMatches['product_variant_id'] = $m;
                }

                if ($submittedVarId) {
                    $m = ProductVariant::where('product_id', $id)->where('variant_id', $submittedVarId)->first();
                    if (!$m) {
                        throw new SaleValidationException("Variant ID {$submittedVarId} does not belong to product '{$candProduct->name}'.");
                    }
                    $pvMatches['variant_id'] = $m;
                }

                if ($submittedCode && !$isBaseCode) {
                    $m = ProductVariant::where('product_id', $id)->where('item_code', $submittedCode)->first();
                    if (!$m) {
                        throw new SaleValidationException("Variant with code '{$submittedCode}' does not exist for product '{$candProduct->name}'.");
                    }
                    $pvMatches['product_code'] = $m;
                }

                $matchedIds = array_unique(array_map(fn($pv) => (int)$pv->id, $pvMatches));
                if (count($matchedIds) > 1) {
                    throw new SaleValidationException("Submitted variant identifiers resolve to different variants for product '{$candProduct->name}'.");
                }

                $resolvedPv = reset($pvMatches);
                $resolvedPvId = (int)$resolvedPv->id;
                $resolvedVarId = (int)$resolvedPv->variant_id;
                $pvIdsToLock[] = $resolvedPvId;
            } else {
                if (!empty($submittedPvId) || !empty($submittedVarId)) {
                    throw new SaleValidationException("Product '{$candProduct->name}' is not a variant product but a variant was submitted.");
                }
                if ($submittedCode !== null && $submittedCode !== (string)$candProduct->code) {
                    $foreignPv = ProductVariant::where('item_code', $submittedCode)->first();
                    if ($foreignPv) {
                        throw new SaleValidationException("Product '{$candProduct->name}' is not a variant product but variant SKU '{$submittedCode}' was submitted.");
                    }
                }
            }

            if (!empty($batchIdArray[$i])) {
                $batchIdsToLock[] = (int)$batchIdArray[$i];
            }

            $comboComponents = [];
            if ($candProduct->type === 'combo') {
                $compList = array_values(array_filter(array_map('trim', explode(',', (string)$candProduct->product_list)), fn($val) => $val !== ''));
                if (empty($compList)) {
                    throw new SaleValidationException("Combo product '{$candProduct->name}' has no component products defined.");
                }

                $cVarList = !empty($candProduct->variant_list) ? explode(',', (string)$candProduct->variant_list) : [];
                $cPvList = !empty($candProduct->product_variant_list) ? explode(',', (string)$candProduct->product_variant_list) : [];
                $cQtyList = !empty($candProduct->qty_list) ? explode(',', (string)$candProduct->qty_list) : [];
                $cUnitList = !empty($candProduct->combo_unit_id) ? explode(',', (string)$candProduct->combo_unit_id) : [];

                foreach ($compList as $k => $cidStr) {
                    $cid = (int)$cidStr;
                    if ($cid <= 0) {
                        throw new SaleValidationException("Invalid component product ID in combo '{$candProduct->name}'.");
                    }
                    $compProduct = Product::find($cid);
                    if (!$compProduct) {
                        throw new SaleValidationException("Component product ID {$cid} for combo '{$candProduct->name}' was not found.");
                    }
                    if (!$compProduct->is_active) {
                        throw new SaleValidationException("Component product '{$compProduct->name}' for combo '{$candProduct->name}' is inactive.");
                    }

                    $comboProductIdsToLock[] = $cid;

                    $rawVar = isset($cVarList[$k]) ? trim((string)$cVarList[$k]) : '';
                    $rawPv = isset($cPvList[$k]) ? trim((string)$cPvList[$k]) : '';
                    $compPvId = null;
                    $compVarId = null;

                    $hasVar = ($rawVar !== '' && $rawVar !== 'null' && $rawVar !== '0');
                    $hasPv = ($rawPv !== '' && $rawPv !== 'null' && $rawPv !== '0');

                    if (!$compProduct->is_variant) {
                        if ($hasVar || $hasPv) {
                            throw new SaleValidationException("Non-variant component '{$compProduct->name}' in combo '{$candProduct->name}' cannot have a variant identifier.");
                        }
                    } else {
                        if (!$hasVar && !$hasPv) {
                            throw new SaleValidationException("Variant identification is required for component '{$compProduct->name}' in combo '{$candProduct->name}'.");
                        }

                        $pvByPvId = null;
                        if ($hasPv) {
                            $pvByPvId = ProductVariant::where('product_id', $cid)->where('id', (int)$rawPv)->first();
                            if (!$pvByPvId) {
                                throw new SaleValidationException("Product variant ID '{$rawPv}' does not belong to component product ID {$cid} in combo '{$candProduct->name}'.");
                            }
                        }

                        $pvByVarId = null;
                        if ($hasVar) {
                            $matches = ProductVariant::where('product_id', $cid)->where('variant_id', (int)$rawVar)->get();
                            if ($matches->isEmpty()) {
                                throw new SaleValidationException("Variant '{$rawVar}' does not belong to component product ID {$cid} in combo '{$candProduct->name}'.");
                            }
                            if ($matches->count() > 1) {
                                throw new SaleValidationException("Variant '{$rawVar}' is ambiguous for component product ID {$cid} in combo '{$candProduct->name}'.");
                            }
                            $pvByVarId = $matches->first();
                        }

                        if ($pvByPvId && $pvByVarId) {
                            if ((int)$pvByPvId->id !== (int)$pvByVarId->id) {
                                throw new SaleValidationException("Conflicting variant identifiers for component product ID {$cid} in combo '{$candProduct->name}'.");
                            }
                            $resolvedChildPv = $pvByPvId;
                        } else {
                            $resolvedChildPv = $pvByPvId ?? $pvByVarId;
                        }

                        $compPvId = (int)$resolvedChildPv->id;
                        $compVarId = (int)$resolvedChildPv->variant_id;
                        $pvIdsToLock[] = $compPvId;
                    }

                    $reqQty = isset($cQtyList[$k]) && is_numeric(trim((string)$cQtyList[$k])) ? (float)trim((string)$cQtyList[$k]) : 1.0;
                    if ($reqQty <= 0) {
                        throw new SaleValidationException("Invalid component quantity in combo '{$candProduct->name}'.");
                    }

                    if (isset($cUnitList[$k]) && $cUnitList[$k]) {
                        $cUnitId = (int)trim((string)$cUnitList[$k]);
                        if ($cUnitId > 0 && $cUnitId != $compProduct->unit_id) {
                            $unit = Unit::find($cUnitId);
                            if ($unit) {
                                if ($unit->operator == '*') {
                                    $reqQty *= (float)$unit->operation_value;
                                } elseif ($unit->operator == '/') {
                                    $reqQty /= (float)$unit->operation_value;
                                }
                            }
                        }
                    }

                    $comboComponents[] = [
                        'component_index' => $k,
                        'product_id' => $cid,
                        'resolved_pv_id' => $compPvId,
                        'resolved_var_id' => $compVarId,
                        'expected_pv_id' => $hasPv ? (int)$rawPv : null,
                        'expected_var_id' => $hasVar ? (int)$rawVar : null,
                        'required_qty' => $reqQty,
                        'expected_unit_id' => (int) $compProduct->unit_id,
                    ];
                }
            }

            $modifierPayload = $data['topping_product'][$i] ?? ($data['modifiers'][$i] ?? null);
            if (is_array($modifierPayload)) {
                $modifierPayload = json_encode($modifierPayload);
            }
            $resolvedMods = [];
            if (!empty($modifierPayload) && class_exists(\Modules\Restaurant\Entities\ProductSaleModifier::class)) {
                $resolvedMods = app(\Modules\Restaurant\Services\ModifierSelectionService::class)->resolve((int)$id, $modifierPayload);
                if (is_array($resolvedMods)) {
                    foreach ($resolvedMods as $rm) {
                        if (!empty($rm['product_list'])) {
                            foreach (explode(',', (string)$rm['product_list']) as $mpId) {
                                $mpId = (int)trim($mpId);
                                if ($mpId > 0) {
                                    $modProductIdsToLock[] = $mpId;
                                }
                            }
                        }
                    }
                } else {
                    $resolvedMods = [];
                }
            }

            $lineCandidates[$i] = [
                'product_id' => $id,
                'resolved_pv_id' => $resolvedPvId,
                'resolved_var_id' => $resolvedVarId,
                'submitted_pv_id' => $submittedPvId,
                'submitted_var_id' => $submittedVarId,
                'submitted_code' => $submittedCode,
                'requested_qty' => $requestedQty,
                'combo_components' => $comboComponents,
                'resolved_modifiers' => $resolvedMods,
                'combo_definition' => $candProduct->only(['type', 'product_list', 'variant_list', 'product_variant_list', 'qty_list', 'combo_unit_id']),
            ];
        }

        // --- STEP 2: Deterministic Locking Order ---
        // Sort all primary keys ascending and acquire locks in strict table order:
        // 1. Products
        // 2. ProductVariants
        // 3. ProductBatches
        // 4. Product_Warehouse
        // 5. GiftCards
        $allProductIds = array_unique(array_filter(array_merge($productIdsToLock, $comboProductIdsToLock, $modProductIdsToLock)));
        sort($allProductIds);
        $lockedProducts = empty($allProductIds)
            ? collect()
            : Product::whereIn('id', $allProductIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $uniquePvIds = array_unique(array_filter($pvIdsToLock));
        sort($uniquePvIds);
        $lockedProductVariants = empty($uniquePvIds)
            ? collect()
            : ProductVariant::whereIn('id', $uniquePvIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $uniqueBatchIds = array_unique(array_filter($batchIdsToLock));
        sort($uniqueBatchIds);
        $lockedBatches = empty($uniqueBatchIds)
            ? collect()
            : ProductBatch::whereIn('id', $uniqueBatchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $pwQuery = Product_Warehouse::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $allProductIds);
        $lockedWarehouseStocks = $pwQuery->orderBy('id')->lockForUpdate()->get();

        // Lock gift cards deterministically if present in payment payload
        $paymentTuples = self::alignPaymentTuples($data);
        $giftCardIdsToLock = [];
        foreach ($paymentTuples as $pt) {
            if ($pt['method'] === 'gift_card' && !empty($pt['gift_card_id'])) {
                $giftCardIdsToLock[] = (int)$pt['gift_card_id'];
            }
        }
        $uniqueGiftCardIds = array_unique(array_filter($giftCardIdsToLock));
        sort($uniqueGiftCardIds);
        $lockedGiftCards = empty($uniqueGiftCardIds)
            ? collect()
            : GiftCard::whereIn('id', $uniqueGiftCardIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $findPw = function (int $pid, int $vid = 0, int $bid = 0) use ($lockedWarehouseStocks) {
            $matches = $lockedWarehouseStocks->filter(function ($item) use ($pid, $vid, $bid) {
                if ((int)$item->product_id !== $pid) {
                    return false;
                }
                if ($vid > 0) {
                    if ((int)$item->variant_id !== $vid) {
                        return false;
                    }
                } else {
                    if ($item->variant_id !== null && (int)$item->variant_id !== 0) {
                        return false;
                    }
                }
                if ($bid > 0) {
                    if ((int)$item->product_batch_id !== $bid) {
                        return false;
                    }
                } else {
                    if ($item->product_batch_id !== null && (int)$item->product_batch_id !== 0) {
                        return false;
                    }
                }
                return true;
            });
            if ($matches->count() > 1) {
                throw new SaleValidationException('Ambiguous warehouse stock identity.');
            }
            return $matches->first();
        };

        // --- STEP 3: Re-validation & Line Construction under Locks ---
        $resolvedLines = [];

        foreach ($productIdArray as $i => $id) {
            $id = (int)$id;
            $product = $lockedProducts->get($id);
            if (!$product) {
                throw new SaleValidationException(__('db.product_not_found') ?: "Product with ID {$id} was not found.");
            }
            if (!$product->is_active) {
                throw new SaleValidationException(__('db.product_is_inactive') ?: "Product '{$product->name}' is inactive.");
            }

            $cand = $lineCandidates[$i];
            if ($product->only(['type', 'product_list', 'variant_list', 'product_variant_list', 'qty_list', 'combo_unit_id']) !== $cand['combo_definition']) {
                throw new SaleValidationException('Product composition changed during checkout. Please retry.');
            }
            $pvModel = null;
            $variantId = null;

            if ($product->is_variant) {
                $pvModel = $lockedProductVariants->get($cand['resolved_pv_id']);
                if (!$pvModel || (int)$pvModel->product_id !== $product->id) {
                    throw new SaleValidationException("Variant for product '{$product->name}' could not be locked or does not belong to product.");
                }
                $variantId = (int)$pvModel->variant_id;
                if (($cand['submitted_pv_id'] && (int) $pvModel->id !== $cand['submitted_pv_id'])
                    || ($cand['submitted_var_id'] && $variantId !== $cand['submitted_var_id'])
                    || ($cand['submitted_code'] && $cand['submitted_code'] !== (string) $product->code && $cand['submitted_code'] !== (string) $pvModel->item_code)) {
                    throw new SaleValidationException('Product variant identity changed during checkout.');
                }
            }

            $rawImei = $imeiNumberArray[$i] ?? null;
            $cleanImei = ($rawImei !== null && $rawImei !== '' && !str_contains((string)$rawImei, 'null')) ? trim((string)$rawImei) : null;
            $requestedQty = $cand['requested_qty'];

            // Unit resolution & conversion
            $unitName = $saleUnitArray[$i] ?? 'n/a';
            $saleUnitId = 0;
            $deductQty = $requestedQty;

            if ($unitName !== 'n/a' && !in_array($product->type, ['combo', 'digital', 'service'], true)) {
                $saleUnit = Unit::where('unit_name', $unitName)->orWhere('unit_code', $unitName)->first();
                if (!$saleUnit) {
                    throw new SaleValidationException("Unit '{$unitName}' was not found.");
                }

                $baseUnitId = (int)$product->unit_id;
                $isSameAsBase = ((int)$saleUnit->id === $baseUnitId);
                $isChildOfBase = ($saleUnit->base_unit !== null && (int)$saleUnit->base_unit === $baseUnitId);
                if ($baseUnitId > 0 && !$isSameAsBase && !$isChildOfBase) {
                    throw new SaleValidationException("Unit '{$unitName}' does not belong to the unit hierarchy for product '{$product->name}'.");
                }

                if (!in_array($saleUnit->operator, ['*', '/'], true) || (float)$saleUnit->operation_value <= 0) {
                    throw new SaleValidationException("Invalid unit conversion operator or value for unit '{$unitName}'.");
                }

                $saleUnitId = $saleUnit->id;
                if ($saleUnit->operator === '*') {
                    $deductQty = $requestedQty * (float)$saleUnit->operation_value;
                } elseif ($saleUnit->operator === '/') {
                    $deductQty = $requestedQty / (float)$saleUnit->operation_value;
                }
            }

            // Batch resolution
            $batchId = !empty($batchIdArray[$i]) ? (int)$batchIdArray[$i] : null;
            $batchModel = null;
            if ($batchId) {
                if (!$product->is_batch) {
                    throw new SaleValidationException("Product '{$product->name}' does not support batch tracking.");
                }
                $batchModel = $lockedBatches->get($batchId);
                if (!$batchModel || (int)$batchModel->product_id !== (int)$id) {
                    throw new SaleValidationException("Batch ID {$batchId} does not belong to product '{$product->name}'.");
                }
                $batchPw = $findPw($id, 0, $batchId);
                if (!$batchPw) {
                    throw new SaleValidationException("Batch ID {$batchId} is located in another warehouse.");
                }
            }

            // IMEI resolution
            $soldImeis = [];
            if ($product->is_imei) {
                if (empty($cleanImei)) {
                    throw new SaleValidationException("IMEI number is required for IMEI-tracked product '{$product->name}'.");
                }
                $soldImeis = array_values(array_filter(array_map('trim', explode(',', $cleanImei))));
                if (count($soldImeis) !== (int) $requestedQty) {
                    throw new SaleValidationException("IMEI count (" . count($soldImeis) . ") does not match requested quantity ({$requestedQty}) for product '{$product->name}'.");
                }
                if (count($soldImeis) !== count(array_unique($soldImeis))) {
                    throw new SaleValidationException("Duplicate IMEI numbers submitted for product '{$product->name}'.");
                }

                $pwForImei = $findPw($id, $variantId ?? 0, $batchId ?? 0);
                $availableImeis = ($pwForImei && !empty($pwForImei->imei_number))
                    ? array_values(array_filter(array_map('trim', explode(',', (string)$pwForImei->imei_number))))
                    : [];
                foreach ($soldImeis as $imei) {
                    if (!in_array($imei, $availableImeis, true)) {
                        throw new SaleValidationException("IMEI '{$imei}' is not available in warehouse for product '{$product->name}'.");
                    }
                }
            } else {
                if (!empty($cleanImei)) {
                    throw new SaleValidationException("Product '{$product->name}' does not support IMEI tracking.");
                }
            }

            // Combo components validation & linking from locked instances (Zero DB Queries)
            $comboChildren = [];
            if ($product->type === 'combo') {
                foreach ($cand['combo_components'] as $comp) {
                    $childProduct = $lockedProducts->get($comp['product_id']);
                    if (!$childProduct) {
                        throw new SaleValidationException("Component product ID {$comp['product_id']} for combo '{$product->name}' was not found.");
                    }
                    if (!$childProduct->is_active) {
                        throw new SaleValidationException("Component product '{$childProduct->name}' for combo '{$product->name}' is inactive.");
                    }
                    if ((int) $childProduct->unit_id !== $comp['expected_unit_id']) {
                        throw new SaleValidationException('Component unit changed during checkout. Please retry.');
                    }

                    $childVariant = null;
                    if (!empty($comp['resolved_pv_id'])) {
                        $childVariant = $lockedProductVariants->get($comp['resolved_pv_id']);
                        if (!$childVariant || (int)$childVariant->product_id !== (int)$childProduct->id) {
                            throw new SaleValidationException("Variant for component '{$childProduct->name}' in combo '{$product->name}' could not be locked or does not belong to product.");
                        }
                    }

                    if ($childProduct->is_variant) {
                        if (!$childVariant) {
                            throw new SaleValidationException("Variant identification is required for component '{$childProduct->name}' in combo '{$product->name}'.");
                        }
                        if ($comp['expected_pv_id'] !== null && (int)$childVariant->id !== (int)$comp['expected_pv_id']) {
                            throw new SaleValidationException("Product variant ID '{$comp['expected_pv_id']}' does not match component '{$childProduct->name}' in combo '{$product->name}'.");
                        }
                        if ($comp['expected_var_id'] !== null && (int)$childVariant->variant_id !== (int)$comp['expected_var_id']) {
                            throw new SaleValidationException("Variant '{$comp['expected_var_id']}' does not match component '{$childProduct->name}' in combo '{$product->name}'.");
                        }
                    } else {
                        if ($childVariant) {
                            throw new SaleValidationException("Non-variant component '{$childProduct->name}' in combo '{$product->name}' cannot have a variant identifier.");
                        }
                    }

                    $childDeductQty = $requestedQty * (float)$comp['required_qty'];
                    $childVid = $childVariant ? (int)$childVariant->variant_id : 0;
                    $childPw = $findPw((int)$childProduct->id, $childVid, 0);

                    $comboChildren[] = [
                        'child_product' => $childProduct,
                        'child_variant' => $childVariant,
                        'child_pw' => $childPw,
                        'deduct_qty' => $childDeductQty,
                    ];
                }
            }

            // Standard and Variant Warehouse Stock
            $pw = null;
            if (!in_array($product->type, ['combo', 'digital', 'service'], true)) {
                $pw = $findPw($id, $variantId ?? 0, $batchId ?? 0);
            }

            $resolvedLines[] = [
                'index' => $i,
                'product' => $product,
                'product_id' => $id,
                'product_batch_id' => $batchId,
                'batch_model' => $batchModel,
                'variant_id' => $variantId,
                'product_variant_id' => $pvModel ? (int)$pvModel->id : null,
                'product_code' => $pvModel ? (string)$pvModel->item_code : (string)$product->code,
                'additional_price' => $pvModel ? (string)($pvModel->additional_price ?? '0.0000') : '0.0000',
                'product_variant' => $pvModel,
                'product_warehouse' => $pw,
                'qty' => $qtyArray[$i],
                'requested_qty' => $requestedQty,
                'deduct_qty' => $deductQty,
                'sale_unit_id' => $saleUnitId,
                'imei_number' => $cleanImei,
                'sold_imeis' => $soldImeis,
                'combo_children' => $comboChildren,
                'resolved_modifiers' => $cand['resolved_modifiers'],
            ];
        }

        // --- STEP 4: Multi-Line Aggregation & Combined Sufficiency Checks ---
        $productDemand = []; // [product_id => float total_demand]
        $variantDemand = []; // [product_variant_id => float total_demand]
        $pwDemand = [];      // [key => ['model' => ?Product_Warehouse, 'demand' => float, 'pid' => int, 'vid' => int, 'bid' => int]]
        $batchDemand = [];   // [product_batch_id => float total_demand]

        foreach ($resolvedLines as $line) {
            $p = $line['product'];
            $deductQty = (float)$line['deduct_qty'];

            if ($p->type === 'combo') {
                foreach ($line['combo_children'] as $child) {
                    $cProd = $child['child_product'];
                    $cVar = $child['child_variant'];
                    $cQty = (float)$child['deduct_qty'];

                    $productDemand[$cProd->id] = ($productDemand[$cProd->id] ?? 0.0) + $cQty;

                    if ($cVar) {
                        $variantDemand[$cVar->id] = ($variantDemand[$cVar->id] ?? 0.0) + $cQty;
                    }

                    $cVid = $cVar ? (int)$cVar->variant_id : 0;
                    $cPw = $child['child_pw'];
                    $pwKey = $cPw ? 'id_' . $cPw->id : "synthetic_{$cProd->id}_{$cVid}_0";
                    if (!isset($pwDemand[$pwKey])) {
                        $pwDemand[$pwKey] = [
                            'model' => $cPw,
                            'demand' => 0.0,
                            'pid' => (int)$cProd->id,
                            'vid' => $cVid,
                            'bid' => 0,
                        ];
                    }
                    $pwDemand[$pwKey]['demand'] += $cQty;
                }
            } elseif (!in_array($p->type, ['digital', 'service'], true)) {
                $productDemand[$p->id] = ($productDemand[$p->id] ?? 0.0) + $deductQty;

                if (!empty($line['product_variant_id'])) {
                    $pvId = (int)$line['product_variant_id'];
                    $variantDemand[$pvId] = ($variantDemand[$pvId] ?? 0.0) + $deductQty;
                }

                if (!empty($line['product_batch_id'])) {
                    $bId = (int)$line['product_batch_id'];
                    $batchDemand[$bId] = ($batchDemand[$bId] ?? 0.0) + $deductQty;
                }

                $pw = $line['product_warehouse'];
                $vid = (int)($line['variant_id'] ?: 0);
                $bid = (int)($line['product_batch_id'] ?: 0);
                $pwKey = $pw ? 'id_' . $pw->id : "synthetic_{$p->id}_{$vid}_{$bid}";
                if (!isset($pwDemand[$pwKey])) {
                    $pwDemand[$pwKey] = [
                        'model' => $pw,
                        'demand' => 0.0,
                        'pid' => (int)$p->id,
                        'vid' => $vid,
                        'bid' => $bid,
                    ];
                }
                $pwDemand[$pwKey]['demand'] += $deductQty;
            }

            if (!empty($line['resolved_modifiers'])) {
                foreach ($line['resolved_modifiers'] as $mod) {
                    if (!empty($mod['product_list'])) {
                        $modProductIds = explode(',', (string)$mod['product_list']);
                        $modQtys = explode(',', (string)$mod['qty_list']);
                        foreach ($modProductIds as $k => $mpId) {
                            $mpId = (int)trim($mpId);
                            $ingredient = $lockedProducts->get($mpId);
                            if (!$ingredient || !$ingredient->is_active) {
                                throw new SaleValidationException('Modifier ingredient is missing or inactive.');
                            }
                            if (in_array($ingredient->type, ['digital', 'service'], true)) {
                                continue;
                            }
                            $mQty = (float)($modQtys[$k] ?? 1) * (float)($mod['qty'] ?? 1) * (float)$line['qty'];
                            $productDemand[$mpId] = ($productDemand[$mpId] ?? 0.0) + $mQty;
                            $mPw = $findPw($mpId, 0, 0);
                            $mPwKey = $mPw ? 'id_' . $mPw->id : "synthetic_{$mpId}_0_0";
                            if (!isset($pwDemand[$mPwKey])) {
                                $pwDemand[$mPwKey] = [
                                    'model' => $mPw,
                                    'demand' => 0.0,
                                    'pid' => $mpId,
                                    'vid' => 0,
                                    'bid' => 0,
                                ];
                            }
                            $pwDemand[$mPwKey]['demand'] += $mQty;
                        }
                    }
                }
            }
        }

        if ($shouldDeductStock && $enforceStock) {
            // Check aggregate Product stock (for physical products)
            foreach ($productDemand as $pId => $totalNeeded) {
                $prod = $lockedProducts->get($pId);
                if ($prod && !in_array($prod->type, ['combo', 'digital', 'service'], true)) {
                    if (((float)$prod->qty + 0.000001) < $totalNeeded) {
                        throw new SaleValidationException("Requested quantity is not available in stock for {$prod->name}.");
                    }
                }
            }

            // Check aggregate ProductVariant stock
            foreach ($variantDemand as $pvId => $totalNeeded) {
                $pv = $lockedProductVariants->get($pvId);
                $parentProd = $pv ? $lockedProducts->get($pv->product_id) : null;
                $prodName = $parentProd ? $parentProd->name : "Variant #{$pvId}";

                if (!$pv || ((float)$pv->qty + 0.000001) < $totalNeeded) {
                    throw new SaleValidationException("Requested quantity is not available in stock for {$prodName}.");
                }
            }

            // Check aggregate warehouse stock
            foreach ($pwDemand as $pwKey => $item) {
                $stockRow = $item['model'];
                $totalNeeded = $item['demand'];
                $pid = $item['pid'];
                $prodName = $lockedProducts->get($pid)?->name ?? "Product #{$pid}";

                if (!$stockRow || ((float)$stockRow->qty + 0.000001) < $totalNeeded) {
                    throw new SaleValidationException("Requested quantity is not available in stock for {$prodName}.");
                }
            }

            // Check aggregate batch stock
            foreach ($batchDemand as $bId => $totalNeeded) {
                $batch = $lockedBatches->get($bId);
                if (!$batch || ((float)$batch->qty + 0.000001) < $totalNeeded) {
                    $bNo = $batch ? $batch->batch_no : "#{$bId}";
                    throw new SaleValidationException("Requested quantity is not available in stock for batch {$bNo}.");
                }
            }
        }

        // Aggregate IMEI uniqueness check across lines
        $allSoldImeis = [];
        foreach ($resolvedLines as $line) {
            foreach ($line['sold_imeis'] as $imei) {
                if (in_array($imei, $allSoldImeis, true)) {
                    throw new SaleValidationException("Duplicate IMEI '{$imei}' submitted across sale lines.");
                }
                $allSoldImeis[] = $imei;
            }
        }

        // Aggregate gift card balance sufficiency check across payment rows
        $giftCardDemand = [];
        foreach ($paymentTuples as $pt) {
            if ($pt['method'] === 'gift_card' && !empty($pt['gift_card_id'])) {
                $gcId = (int)$pt['gift_card_id'];
                $giftCardDemand[$gcId] = bcadd($giftCardDemand[$gcId] ?? '0.0000', (string)$pt['amount'], 4);
            }
        }
        foreach ($giftCardDemand as $gcId => $totalNeeded) {
            $gc = $lockedGiftCards->get($gcId);
            if (!$gc) {
                throw new SaleValidationException(__('db.Gift card not found.'));
            }
            if (!$gc->is_active) {
                throw new SaleValidationException(__('db.Gift card is inactive.'));
            }
            $today = date('Y-m-d');
            if ($gc->expired_date && $gc->expired_date < $today) {
                throw new SaleValidationException(__('db.Gift card has expired.'));
            }
            $available = bcsub((string)$gc->amount, (string)$gc->expense, 4);
            if (bccomp($totalNeeded, $available, 4) > 0) {
                throw new SaleValidationException(__('db.Gift card balance is insufficient.'));
            }
        }

        $resolvedLines['__demand'] = [
            'productDemand' => $productDemand,
            'variantDemand' => $variantDemand,
            'pwDemand' => $pwDemand,
            'batchDemand' => $batchDemand,
        ];
        $resolvedLines['__locked'] = [
            'products' => $lockedProducts,
            'variants' => $lockedProductVariants,
            'batches' => $lockedBatches,
            'stocks' => $lockedWarehouseStocks,
            'gift_cards' => $lockedGiftCards,
        ];

        return $resolvedLines;
    }

    /**
     * Genuinely float-free decimal canonicalization.
     * Accepts only ordinary decimal strings and integers.
     * Half-away-from-zero carry addition with pure string arithmetic.
     *
     * @param mixed $val
     * @param int $scale
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function canonicalizeDecimalString(mixed $val, int $scale = 4): string
    {
        if ($scale < 0) {
            throw new \InvalidArgumentException("Scale must be a non-negative integer.");
        }

        if ($val === null) {
            return '0' . ($scale > 0 ? '.' . str_repeat('0', $scale) : '');
        }

        if (is_float($val)) {
            throw new \InvalidArgumentException("Float values are strictly prohibited in decimal canonicalization; supply an integer or decimal string.");
        }

        if (!is_string($val) && !is_int($val)) {
            throw new \InvalidArgumentException("Invalid decimal type: " . gettype($val));
        }

        $str = trim((string) $val);
        if ($str === '') {
            return '0' . ($scale > 0 ? '.' . str_repeat('0', $scale) : '');
        }

        // Reject exponent notation
        if (stripos($str, 'e') !== false) {
            throw new \InvalidArgumentException("Exponent notation is rejected: {$str}");
        }

        // Accept ordinary signed decimal strings only
        if (!preg_match('/^([+-])?(\d*)(\.(\d*))?$/', $str, $m)) {
            throw new \InvalidArgumentException("Malformed decimal string: {$str}");
        }

        $sign = $m[1] ?? '+';
        $intPart = $m[2] ?? '';
        $fracPart = $m[4] ?? '';

        if ($intPart === '' && $fracPart === '') {
            throw new \InvalidArgumentException("Malformed decimal string: {$str}");
        }

        $intPart = ($intPart === '') ? '0' : ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }

        $fracLen = strlen($fracPart);
        if ($fracLen <= $scale) {
            $retainedFrac = str_pad($fracPart, $scale, '0', STR_PAD_RIGHT);
            $shouldRoundUp = false;
        } else {
            $retainedFrac = $scale > 0 ? substr($fracPart, 0, $scale) : '';
            $firstDiscarded = (int) $fracPart[$scale];
            $shouldRoundUp = ($firstDiscarded >= 5);
        }

        // Half-away-from-zero carry propagation across the integer string
        if ($shouldRoundUp) {
            $digits = $intPart . $retainedFrac;
            $carry = 1;
            for ($i = strlen($digits) - 1; $i >= 0 && $carry > 0; $i--) {
                $s = ((int) $digits[$i]) + $carry;
                if ($s >= 10) {
                    $digits[$i] = '0';
                    $carry = 1;
                } else {
                    $digits[$i] = (string) $s;
                    $carry = 0;
                }
            }
            if ($carry > 0) {
                $digits = '1' . $digits;
            }

            if ($scale > 0) {
                $intPart = substr($digits, 0, strlen($digits) - $scale);
                $retainedFrac = substr($digits, -$scale);
            } else {
                $intPart = $digits;
                $retainedFrac = '';
            }
        }

        // Normalize negative zero to positive zero
        $isZero = ($intPart === '0') && ($retainedFrac === '' || rtrim($retainedFrac, '0') === '');
        if ($isZero) {
            return '0' . ($scale > 0 ? '.' . str_repeat('0', $scale) : '');
        }

        return ($sign === '-' ? '-' : '') . $intPart . ($scale > 0 ? '.' . $retainedFrac : '');
    }

    /**
     * Authoritatively align and validate payment tuples from request payload.
     * Shared identically by canonical request fingerprinting and payment persistence.
     *
     * @param array $data
     * @return array
     * @throws SaleValidationException
     */
    public static function alignPaymentTuples(array $data): array
    {
        $paidByIds = isset($data['paid_by_id']) ? (is_array($data['paid_by_id']) ? array_values($data['paid_by_id']) : [$data['paid_by_id']]) : [];
        $payingMethods = isset($data['paying_method']) ? (is_array($data['paying_method']) ? array_values($data['paying_method']) : [$data['paying_method']]) : [];
        $payingAmounts = isset($data['paying_amount']) ? (is_array($data['paying_amount']) ? array_values($data['paying_amount']) : [$data['paying_amount']]) : [];
        $paidAmounts = isset($data['paid_amount']) ? (is_array($data['paid_amount']) ? array_values($data['paid_amount']) : [$data['paid_amount']]) : [];
        $chequeNos = isset($data['cheque_no']) ? (is_array($data['cheque_no']) ? array_values($data['cheque_no']) : [$data['cheque_no']]) : [];
        $rawPaymentAttempt = $data['payment_attempt_id'] ?? ($data['client_attempt_id'] ?? ($data['attempt_token'] ?? null));
        $paymentAttemptIds = isset($rawPaymentAttempt) ? (is_array($rawPaymentAttempt) ? array_values($rawPaymentAttempt) : [$rawPaymentAttempt]) : [];
        $razorpayPaymentIds = isset($data['razorpay_payment_id']) ? (is_array($data['razorpay_payment_id']) ? array_values($data['razorpay_payment_id']) : [$data['razorpay_payment_id']]) : [];

        $rawGiftCard = $data['gift_card_id'] ?? ($data['gift_card_id_select'] ?? ($data['gift_card'] ?? null));

        $lenPaidBy = count($paidByIds);
        $lenPayingMethods = count($payingMethods);
        $lenPaidAmounts = count($paidAmounts);
        $lenPayingAmounts = count($payingAmounts);

        $arrayLengths = array_filter([
            'paid_by_id' => $lenPaidBy,
            'paying_method' => $lenPayingMethods,
            'paid_amount' => $lenPaidAmounts,
            'paying_amount' => $lenPayingAmounts,
        ], fn($len) => $len > 0);

        if (!empty($arrayLengths)) {
            $maxLen = max($arrayLengths);
            $minLen = min($arrayLengths);
            if ($minLen !== $maxLen) {
                throw new SaleValidationException(__('db.Mismatched payment array lengths in canonical request'));
            }
            $numPayments = $maxLen;
        } else {
            $numPayments = 0;
        }

        if (isset($data['account_id']) && is_array($data['account_id']) && count($data['account_id']) > 1) {
            if (count($data['account_id']) !== $numPayments) {
                throw new SaleValidationException(__('db.Mismatched payment array lengths in canonical request'));
            }
        }

        $normalizeMethod = static function ($rawMethod): string {
            $m = strtolower(trim((string)$rawMethod));
            return match ($m) {
                '1', 'cash' => 'cash',
                '2', 'gift_card', 'gift card' => 'gift_card',
                '3', 'credit_card', 'card' => 'credit_card',
                '4', 'cheque' => 'cheque',
                '5', 'paypal' => 'paypal',
                '6', 'deposit' => 'deposit',
                '7', 'points' => 'points',
                'upi' => 'upi',
                'razorpay' => 'razorpay',
                default => $m ?: 'cash',
            };
        };

        // Determine method per payment row and collect gift card indices
        $methods = [];
        $giftCardIndices = [];
        for ($k = 0; $k < $numPayments; $k++) {
            $rawMethod = $payingMethods[$k] ?? ($paidByIds[$k] ?? 'cash');
            $norm = $normalizeMethod($rawMethod);
            $methods[$k] = $norm;
            if ($norm === 'gift_card') {
                $giftCardIndices[] = $k;
            }
        }
        $giftCardCount = count($giftCardIndices);

        // Align gift card IDs across payment rows
        $alignedGiftCardIds = array_fill(0, $numPayments, null);

        if ($giftCardCount === 0) {
            if ($rawGiftCard !== null && $rawGiftCard !== '' && $rawGiftCard !== []) {
                throw new SaleValidationException(__('db.Gift card ID provided for non-gift-card payment method.'));
            }
        } elseif ($giftCardCount === 1) {
            $targetIdx = $giftCardIndices[0];
            if (is_array($rawGiftCard)) {
                $count = count($rawGiftCard);
                if ($count === $numPayments) {
                    // Full parallel array
                    foreach ($rawGiftCard as $idx => $val) {
                        if ($idx === $targetIdx) {
                            if ($val === null || $val === '') {
                                throw new SaleValidationException(__('db.Gift card ID is required for gift card payment.'));
                            }
                            $alignedGiftCardIds[$targetIdx] = (int)$val;
                        } else {
                            if ($val !== null && $val !== '') {
                                throw new SaleValidationException(__('db.Gift card ID assigned to non-gift-card payment row.'));
                            }
                        }
                    }
                } elseif ($count === 1) {
                    // Compact 1-element array
                    $val = reset($rawGiftCard);
                    if ($val === null || $val === '') {
                        throw new SaleValidationException(__('db.Gift card ID is required for gift card payment.'));
                    }
                    $alignedGiftCardIds[$targetIdx] = (int)$val;
                } else {
                    throw new SaleValidationException(__('db.Mismatched gift card payment array lengths.'));
                }
            } else {
                // Scalar gift card ID
                if ($rawGiftCard === null || $rawGiftCard === '') {
                    throw new SaleValidationException(__('db.Gift card ID is required for gift card payment.'));
                }
                $alignedGiftCardIds[$targetIdx] = (int)$rawGiftCard;
            }
        } else {
            // Multiple gift card tenders
            if (!is_array($rawGiftCard)) {
                throw new SaleValidationException(__('db.Ambiguous gift card submission for multiple gift card payments.'));
            }
            $count = count($rawGiftCard);
            if ($count === $numPayments) {
                // Full parallel array
                foreach ($rawGiftCard as $idx => $val) {
                    if (in_array($idx, $giftCardIndices, true)) {
                        if ($val === null || $val === '') {
                            throw new SaleValidationException(__('db.Gift card ID is required for gift card payment.'));
                        }
                        $alignedGiftCardIds[$idx] = (int)$val;
                    } else {
                        if ($val !== null && $val !== '') {
                            throw new SaleValidationException(__('db.Gift card ID assigned to non-gift-card payment row.'));
                        }
                    }
                }
            } elseif ($count === $giftCardCount) {
                // Compact array mapping only across gift card tender rows
                $compactVals = array_values($rawGiftCard);
                foreach ($giftCardIndices as $seq => $targetIdx) {
                    $val = $compactVals[$seq];
                    if ($val === null || $val === '') {
                        throw new SaleValidationException(__('db.Gift card ID is required for gift card payment.'));
                    }
                    $alignedGiftCardIds[$targetIdx] = (int)$val;
                }
            } else {
                throw new SaleValidationException(__('db.Mismatched gift card payment array lengths.'));
            }
        }

        $tuples = [];
        for ($k = 0; $k < $numPayments; $k++) {
            $amt = $paidAmounts[$k] ?? ($payingAmounts[$k] ?? 0);
            $accId = isset($data['account_id'])
                ? (is_array($data['account_id']) ? ($data['account_id'][$k] ?? 1) : $data['account_id'])
                : 1;

            $tuples[] = [
                'index' => $k,
                'method' => $methods[$k],
                'amount' => self::canonicalizeDecimalString($amt),
                'account_id' => (int)$accId,
                'cheque_no' => isset($chequeNos[$k]) ? trim((string)$chequeNos[$k]) : null,
                'gift_card_id' => $alignedGiftCardIds[$k],
                'payment_attempt_id' => isset($paymentAttemptIds[$k]) ? trim((string)$paymentAttemptIds[$k]) : null,
                'razorpay_payment_id' => isset($razorpayPaymentIds[$k]) ? trim((string)$razorpayPaymentIds[$k]) : null,
            ];
        }

        return $tuples;
    }

    /**
     * Compute canonical SHA-256 fingerprint for sale transaction idempotency
     * based strictly on submitted intent and server authorization context.
     *
     * @param array $data
     * @param User|null $user
     * @return string
     * @throws SaleValidationException
     */
    public function computeCanonicalRequestFingerprint(array $data, ?User $user = null): string
    {
        $callerUser = $user ?? auth()->user();
        $userId = $callerUser ? (int) $callerUser->id : (int) ($data['user_id'] ?? 1);

        $warehouseAccess = app(WarehouseAccessService::class);
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        if ($callerUser && $warehouseAccess->isWarehouseOperational($callerUser)) {
            $warehouseId = (int) $callerUser->warehouse_id;
        }

        $productIds = $data['product_id'] ?? [];
        $lineItems = [];
        if (is_array($productIds)) {
            foreach ($productIds as $idx => $pid) {
                $rawImei = $data['imei_number'][$idx] ?? null;
                $cleanImei = ($rawImei !== null && $rawImei !== '' && !str_contains((string)$rawImei, 'null'))
                    ? trim((string)$rawImei)
                    : null;

                $modifierPayload = $data['topping_product'][$idx] ?? ($data['modifiers'][$idx] ?? ($data['modifier_id'][$idx] ?? null));
                $normalizedModifiers = null;
                if (!empty($modifierPayload)) {
                    if (is_string($modifierPayload)) {
                        $decoded = json_decode($modifierPayload, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $modifierPayload = $decoded;
                        }
                    }
                    if (is_array($modifierPayload)) {
                        $sortedModifiers = $modifierPayload;
                        ksort($sortedModifiers);
                        $normalizedModifiers = json_encode($sortedModifiers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        $normalizedModifiers = trim((string)$modifierPayload);
                    }
                }

                $lineItems[] = [
                    'product_id' => (int) $pid,
                    'product_variant_id' => !empty($data['product_variant_id'][$idx]) ? (int) $data['product_variant_id'][$idx] : null,
                    'variant_id' => !empty($data['variant_id'][$idx]) ? (int) $data['variant_id'][$idx] : null,
                    'product_code' => isset($data['product_code'][$idx]) && trim((string)$data['product_code'][$idx]) !== '' ? trim((string)$data['product_code'][$idx]) : null,
                    'product_batch_id' => !empty($data['product_batch_id'][$idx]) ? (int) $data['product_batch_id'][$idx] : null,
                    'imei_number' => $cleanImei,
                    'qty' => self::canonicalizeDecimalString($data['qty'][$idx] ?? 0),
                    'sale_unit' => (string) ($data['sale_unit'][$idx] ?? ($data['sale_unit_id'][$idx] ?? '')),
                    'net_unit_price' => self::canonicalizeDecimalString($data['net_unit_price'][$idx] ?? 0),
                    'discount' => self::canonicalizeDecimalString($data['discount'][$idx] ?? 0),
                    'tax_rate' => self::canonicalizeDecimalString($data['tax_rate'][$idx] ?? 0),
                    'tax' => self::canonicalizeDecimalString($data['tax'][$idx] ?? 0),
                    'tax_method' => isset($data['tax_method'][$idx]) ? (int) $data['tax_method'][$idx] : null,
                    'subtotal' => self::canonicalizeDecimalString($data['subtotal'][$idx] ?? 0),
                    'modifiers' => $normalizedModifiers,
                ];
            }

            usort($lineItems, function ($a, $b) {
                return ($a['product_id'] <=> $b['product_id'])
                    ?: (($a['product_variant_id'] ?? 0) <=> ($b['product_variant_id'] ?? 0))
                    ?: (($a['variant_id'] ?? 0) <=> ($b['variant_id'] ?? 0))
                    ?: strcmp((string)($a['product_code'] ?? ''), (string)($b['product_code'] ?? ''))
                    ?: (($a['product_batch_id'] ?? 0) <=> ($b['product_batch_id'] ?? 0))
                    ?: strcmp((string)($a['imei_number'] ?? ''), (string)($b['imei_number'] ?? ''))
                    ?: strcmp((string)($a['modifiers'] ?? ''), (string)($b['modifiers'] ?? ''))
                    ?: strcmp($a['qty'], $b['qty'])
                    ?: strcmp($a['subtotal'], $b['subtotal']);
            });
        }

        // Canonical payment tuples aligned authoritatively with transport index stripped
        $rawTuples = self::alignPaymentTuples($data);
        $paymentTuples = [];
        foreach ($rawTuples as $pt) {
            $paymentTuples[] = [
                'method' => $pt['method'],
                'amount' => $pt['amount'],
                'account_id' => $pt['account_id'],
                'cheque_no' => $pt['cheque_no'],
                'gift_card_id' => $pt['gift_card_id'],
                'payment_attempt_id' => $pt['payment_attempt_id'],
                'razorpay_payment_id' => $pt['razorpay_payment_id'],
            ];
        }

        usort($paymentTuples, function ($a, $b) {
            return strcmp($a['method'], $b['method'])
                ?: strcmp($a['amount'], $b['amount'])
                ?: ($a['account_id'] <=> $b['account_id'])
                ?: strcmp((string)($a['cheque_no'] ?? ''), (string)($b['cheque_no'] ?? ''))
                ?: (($a['gift_card_id'] ?? 0) <=> ($b['gift_card_id'] ?? 0))
                ?: strcmp((string)($a['payment_attempt_id'] ?? ''), (string)($b['payment_attempt_id'] ?? ''))
                ?: strcmp((string)($a['razorpay_payment_id'] ?? ''), (string)($b['razorpay_payment_id'] ?? ''));
        });

        $totalPaid = '0.0000';
        if (!empty($paymentTuples)) {
            foreach ($paymentTuples as $pt) {
                $totalPaid = bcadd($totalPaid, $pt['amount'], 4);
            }
        } else {
            $totalPaid = self::canonicalizeDecimalString($data['paid_amount'] ?? 0);
        }

        $canonical = [
            'user_id' => (int) $userId,
            'warehouse_id' => (int) $warehouseId,
            'customer_id' => (int) ($data['customer_id'] ?? 0),
            'items' => $lineItems,
            'payments' => $paymentTuples,
            'currency_id' => (int) ($data['currency_id'] ?? 1),
            'exchange_rate' => self::canonicalizeDecimalString($data['exchange_rate'] ?? 1),
            'grand_total' => self::canonicalizeDecimalString($data['grand_total'] ?? 0),
            'total_paid' => $totalPaid,
            'payment_status' => isset($data['payment_status']) ? (int) $data['payment_status'] : null,
            'sale_status' => isset($data['sale_status']) ? (int) $data['sale_status'] : null,
            'order_discount' => self::canonicalizeDecimalString($data['order_discount'] ?? 0),
            'order_discount_value' => self::canonicalizeDecimalString($data['order_discount_value'] ?? 0),
            'order_discount_type' => (string) ($data['order_discount_type'] ?? ''),
            'order_tax_rate' => self::canonicalizeDecimalString($data['order_tax_rate'] ?? 0),
            'order_tax' => self::canonicalizeDecimalString($data['order_tax'] ?? 0),
            'shipping_cost' => self::canonicalizeDecimalString($data['shipping_cost'] ?? 0),
            'coupon_id' => isset($data['coupon_id']) ? (int) $data['coupon_id'] : null,
            'coupon_code' => isset($data['coupon_code']) ? trim((string) $data['coupon_code']) : null,
            'coupon_discount' => isset($data['coupon_discount']) ? self::canonicalizeDecimalString($data['coupon_discount']) : null,
            'used_points' => isset($data['used_points']) ? self::canonicalizeDecimalString($data['used_points']) : (isset($data['reward_points']) ? self::canonicalizeDecimalString($data['reward_points']) : null),
            'deposit_amount' => isset($data['deposit_amount']) ? self::canonicalizeDecimalString($data['deposit_amount']) : (isset($data['used_deposit']) ? self::canonicalizeDecimalString($data['used_deposit']) : null),
            'service_id' => isset($data['service_id']) ? (int) $data['service_id'] : null,
            'table_id' => isset($data['table_id']) ? (int) $data['table_id'] : null,
            'waiter_id' => isset($data['waiter_id']) ? (int) $data['waiter_id'] : null,
        ];

        // Preserve existing zero/no-charge replay hashes. Nonzero service charge
        // is monetary intent too, even if a caller forges an unchanged grand total.
        $serviceCharge = self::canonicalizeDecimalString($data['service_charge'] ?? 0);
        if (bccomp($serviceCharge, '0', 8) !== 0) { $canonical['service_charge'] = $serviceCharge; }
        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to encode canonical sale payload: " . json_last_error_msg());
        }

        return hash('sha256', $json);
    }

    /**
     * Compute canonical SHA-256 fingerprint for sale transaction idempotency.
     * Backwards-compatible alias for computeCanonicalRequestFingerprint.
     *
     * @param array $data
     * @param User|null $user
     * @return string
     */
    public function computeIdempotencyFingerprint(array $data, ?User $user = null): string
    {
        return $this->computeCanonicalRequestFingerprint($data, $user);
    }

    /**
     * Add a payment to an existing sale.
     *
     * @param array $data
     * @param User|null $user
     * @return Payment
     */
    public function addSalePayment(array $data, ?User $user = null, bool $updateSaleTotals = true): Payment
    {
        if (in_array(strtolower((string) ($data['paid_by_id'] ?? '')), ['7', 'points'], true)) {
            $customerId = Sale::withoutGlobalScopes()->whereKey((int) $data['sale_id'])->value('customer_id');
            app(\App\Services\RewardPointService::class)->sweepExpiredPoints((int) $customerId);
        }

        return DB::transaction(function () use ($data, $user, $updateSaleTotals) {
            $userId = $user ? $user->id : (\Illuminate\Support\Facades\Auth::id() ?? 1);
            $sale = app(\App\Services\SalePaymentIntegrity::class)->lockSale((int) $data['sale_id']);
            $amount = app(\App\Services\SalePaymentIntegrity::class)->money($data['amount'] ?? 0);

            if ($updateSaleTotals) {
                $sale->paid_amount += $amount;
                $balance = $sale->grand_total - $sale->paid_amount;
                if (abs($balance) < 0.001) {
                    $sale->payment_status = 4; // Paid
                } else {
                    $sale->payment_status = 2; // Partial
                }
                $sale->save();
            }

            $cashRegister = CashRegister::where([
                ['user_id', $userId],
                ['warehouse_id', $sale->warehouse_id],
                ['status', true]
            ])->first();

            $payingMethodId = $data['paid_by_id'] ?? 1;
            $methodStr = strtolower((string)$payingMethodId);
            $payingMethod = match ($methodStr) {
                '1', 'cash' => 'Cash',
                '2', 'gift_card', 'gift card' => 'Gift Card',
                '3', 'credit_card', 'card' => 'Credit Card',
                '4', 'cheque' => 'Cheque',
                '5', 'paypal' => 'Paypal',
                '6', 'deposit' => 'Deposit',
                '7', 'points' => 'Points',
                'upi' => 'UPI',
                'razorpay' => 'Razorpay',
                default => is_string($payingMethodId) ? (strtoupper($payingMethodId) === 'UPI' ? 'UPI' : ucfirst($payingMethodId)) : 'Cash',
            };

            $attempt = null;
            if ($payingMethod === 'UPI') {
                $attemptUuid = $data['payment_attempt_id'] ?? ($data['attempt_uuid'] ?? null);
                $paymentId = $data['razorpay_payment_id'] ?? null;

                $attemptQuery = PosPaymentAttempt::where('gateway', 'razorpay');
                if ($attemptUuid) {
                    $attemptQuery->where('attempt_uuid', $attemptUuid);
                } elseif ($paymentId) {
                    $attemptQuery->where('payment_id', $paymentId);
                } else {
                    throw new SaleValidationException('UPI payment attempt reference is required.');
                }

                $attempt = $attemptQuery->lockForUpdate()->first();

                if (!$attempt || !$attempt->isVerified()) {
                    throw new SaleValidationException('Authoritative UPI payment verification is missing or unverified.');
                }

                if ($attempt->isFinalized() && $attempt->sale_id !== $sale->id) {
                    throw new SaleValidationException('This UPI payment attempt has already been finalized.');
                }

                if (abs((float)$attempt->expected_amount - $amount) > 0.01) {
                    throw new SaleValidationException('UPI payment attempt amount does not match payment amount.');
                }
            }

            $paymentReference = $data['payment_reference'] ?? $this->invoiceService->generateInvoiceName('spr-');

            $payment = new Payment();
            $payment->user_id = $userId;
            $payment->sale_id = $sale->id;
            if ($cashRegister) {
                $payment->cash_register_id = $cashRegister->id;
            }
            $payment->account_id = $data['account_id'] ?? 1;
            $payment->payment_reference = $paymentReference;
            $payment->amount = $amount;
            $payment->currency_id = $data['currency_id'] ?? $sale->currency_id ?? 1;
            $payment->exchange_rate = $data['exchange_rate'] ?? $sale->exchange_rate ?? 1;
            $payment->change = bcsub(app(\App\Services\SalePaymentIntegrity::class)->money($data['paying_amount'] ?? $amount), $amount, 4);
            $payment->paying_method = $payingMethod;
            $paymentNote = $data['payment_note'] ?? null;
            if ($attempt && $attempt->payment_id) {
                $paymentNote = $paymentNote ? ($paymentNote . ' | Razorpay UPI: ' . $attempt->payment_id) : ('Razorpay UPI: ' . $attempt->payment_id . ' (Order: ' . $attempt->order_id . ')');
            }
            $payment->payment_note = $paymentNote;
            $payment->payment_receiver = $data['payment_receiver'] ?? null;
            $payment->payment_at = isset($data['payment_at']) ? normalize_to_sql_datetime($data['payment_at']) : date('Y-m-d H:i:s');

            $payment->save();

            if ($payingMethod === 'Deposit') {
                $this->customerDeposits->consume((int) $sale->customer_id, $amount);
            }
            if ($payingMethod === 'Points') {
                app(\App\Services\RewardPointService::class)->redeemPayment($payment, $sale);
            }

            if ($attempt) {
                $attempt->markFinalized($sale->id, $payment->id);
            }

            // Record accounting payment entry
            $result = $this->accountingService->recordPayment($payment);
            if (!$result->success) {
                \Log::error('Accounting failed for Sale Payment', ['payment_id' => $payment->id, 'error' => $result->error]);
                throw new SaleValidationException($result->error ?: __('db.customer_deposit_payment_failed'));
            }

            return $payment;
        });
    }

    /**
     * Persist payment lines submitted while an existing draft is finalized.
     * SaleController has already applied the submitted sale totals, so payment
     * records are created without incrementing the sale total a second time.
     *
     * @return array<int, Payment>
     */
    public function recordFinalizedDraftPayments(Sale $sale, array $data, ?User $user = null): array
    {
        $submittedAmounts = array_values((array) ($data['paid_amount'] ?? []));
        $submittedMethods = array_values((array) ($data['paid_by_id'] ?? []));
        $payingAmounts = array_values((array) ($data['paying_amount'] ?? []));
        $alreadyRecorded = (float) $sale->payments()->sum('amount');
        $remaining = max(0, (float) $sale->paid_amount - $alreadyRecorded);
        $payments = [];

        foreach ($submittedAmounts as $key => $submittedAmount) {
            if ($remaining <= 0.0001) {
                break;
            }

            $amount = min(max(0, (float) $submittedAmount), $remaining);
            if ($amount <= 0) {
                continue;
            }

            $payments[] = $this->addSalePayment([
                'sale_id' => $sale->id,
                'amount' => $amount,
                'paid_by_id' => $submittedMethods[$key] ?? 1,
                'paying_amount' => $payingAmounts[$key] ?? $amount,
                'payment_note' => $data['payment_note'] ?? null,
                'payment_receiver' => $data['payment_receiver'] ?? null,
                'account_id' => $data['account_id'] ?? null,
                'currency_id' => $sale->currency_id,
                'exchange_rate' => $sale->exchange_rate,
                'payment_at' => $data['created_at'] ?? now(),
                'payment_attempt_id' => $data['payment_attempt_id'][$key] ?? ($data['payment_attempt_id'] ?? null),
                'razorpay_payment_id' => $data['razorpay_payment_id'][$key] ?? ($data['razorpay_payment_id'] ?? null),
            ], $user, false);

            $remaining -= $amount;
        }

        $sale->refresh();
        $sale->paid_amount = $alreadyRecorded + array_sum(array_map(
            static fn (Payment $payment) => (float) $payment->amount,
            $payments
        ));
        $balance = (float) $sale->grand_total - (float) $sale->paid_amount;
        $sale->payment_status = abs($balance) < 0.001 ? 4 : ($sale->paid_amount > 0 ? 2 : 1);
        $sale->save();

        return $payments;
    }

    /**
     * Update an existing sale payment.
     *
     * @param array $data
     * @param User|null $user
     * @return Payment
     */
    public function updateSalePayment(array $data, ?User $user = null): Payment
    {
        // Resolve the parent before opening the locking transaction. A plain
        // read inside a REPEATABLE READ transaction would establish a stale
        // snapshot before the sale row becomes our serialization lock.
        $existingPayment = Payment::whereKey($data['payment_id'])->firstOrFail(['sale_id', 'paying_method']);
        $saleId = $existingPayment->sale_id;
        $newMethod = strtolower((string) ($data['edit_paid_by_id'] ?? ''));
        if ($existingPayment->paying_method === 'Points' || in_array($newMethod, ['7', 'points'], true)) {
            $customerId = Sale::withoutGlobalScopes()->whereKey((int) $saleId)->value('customer_id');
            app(\App\Services\RewardPointService::class)->sweepExpiredPoints((int) $customerId);
        }
        return DB::transaction(function () use ($data, $user, $saleId) {
            $sale = app(\App\Services\SalePaymentIntegrity::class)->lockSale((int) $saleId);
            $payment = Payment::whereKey($data['payment_id'])->lockForUpdate()->firstOrFail();
            $newAmount = app(\App\Services\SalePaymentIntegrity::class)->money($data['edit_amount'] ?? $payment->amount);
            $oldMethod = $payment->paying_method;

            $editPaidById = $data['edit_paid_by_id'] ?? 1;
            $methodStr = strtolower((string)$editPaidById);
            $payingMethod = match ($methodStr) {
                '1', 'cash' => 'Cash',
                '2', 'gift_card', 'gift card' => 'Gift Card',
                '3', 'credit_card', 'card' => 'Credit Card',
                '4', 'cheque' => 'Cheque',
                '5', 'paypal' => 'Paypal',
                '6', 'deposit' => 'Deposit',
                '7', 'points' => 'Points',
                'upi' => 'UPI',
                'razorpay' => 'Razorpay',
                default => is_string($editPaidById) ? (strtoupper($editPaidById) === 'UPI' ? 'UPI' : ucfirst($editPaidById)) : 'Cash',
            };

            if ($oldMethod === 'Deposit' || $payingMethod === 'Deposit') {
                $this->customerDeposits->reconcile(
                    (int) $sale->customer_id,
                    $oldMethod,
                    $payment->amount,
                    $payingMethod,
                    $newAmount,
                );
            }
            if ($oldMethod === 'Points' && $payingMethod !== 'Points') {
                app(\App\Services\RewardPointService::class)->restorePayment($payment, $sale);
            }

            $payment->account_id = $data['account_id'] ?? $payment->account_id;
            $payment->amount = $newAmount;
            $payment->change = bcsub(app(\App\Services\SalePaymentIntegrity::class)->money($data['edit_paying_amount'] ?? $newAmount), $newAmount, 4);
            $payment->paying_method = $payingMethod;
            $payment->payment_note = $data['edit_payment_note'] ?? $payment->payment_note;
            $payment->payment_receiver = $data['payment_receiver'] ?? $payment->payment_receiver;
            if (isset($data['payment_at'])) {
                $payment->payment_at = normalize_to_sql_datetime($data['payment_at']);
            }
            $financialChanged = app(\App\Services\SalePaymentIntegrity::class)->financialChanged($payment);
            $payment->save();
            if ($payingMethod === 'Points') {
                app(\App\Services\RewardPointService::class)->redeemPayment($payment, $sale);
            }
            app(\App\Services\SalePaymentIntegrity::class)->refreshTotals($sale);

            // Exact replay keeps the active journal; a new state reverses first.
            if (!$financialChanged) {
                $classification = app(\App\Services\AccountingJournalRetryService::class)->classify(get_class($payment), $payment->id);
                if (($classification['status'] ?? null) !== 'already_posted_valid') {
                    throw new SaleValidationException(__('integrity.payment_failed'));
                }
                return $payment;
            }
            // Reverse previous accounting entry and post updated entry
            $reversal = $this->accountingService->reverseTransaction(get_class($payment), $payment->id, '_reversed');
            if (!$reversal->success) {
                throw new SaleValidationException(__('db.customer_deposit_payment_failed'));
            }
            $result = $this->accountingService->recordPayment($payment, 'payment_updated');
            if (!$result->success) {
                \Log::error('Accounting failed for Sale Payment Update', ['payment_id' => $payment->id, 'error' => $result->error]);
                throw new SaleValidationException(__('integrity.payment_failed'));
            }

            return $payment;
        });
    }

    /**
     * Delete/reverse an existing sale payment.
     *
     * @param int $paymentId
     * @param User|null $user
     * @return bool
     */
    public function deleteSalePayment(int $paymentId, ?User $user = null): bool
    {
        return DB::transaction(function () use ($paymentId) {
            $payment = Payment::findOrFail($paymentId);
            $sale = Sale::findOrFail($payment->sale_id);

            if ($payment->paying_method === 'Deposit') {
                $this->customerDeposits->restore((int) $sale->customer_id, $payment->amount);
            }
            if ($payment->paying_method === 'Points') {
                app(\App\Services\RewardPointService::class)->restorePayment($payment, $sale);
            }

            $sale->paid_amount -= $payment->amount;
            $balance = $sale->grand_total - $sale->paid_amount;
            if (abs($balance) < 0.001) {
                $sale->payment_status = 4;
            } elseif ($sale->paid_amount > 0) {
                $sale->payment_status = 2;
            } else {
                $sale->payment_status = 1; // Pending
            }
            $sale->save();

            // Reverse GL accounting entries for this payment
            $reversal = $this->accountingService->reverseTransaction(get_class($payment), $payment->id, '_deleted');
            if (in_array($payment->paying_method, ['Deposit', 'Points'], true) && !$reversal->success) {
                throw new SaleValidationException(__('db.customer_deposit_payment_failed'));
            }

            $payment->delete();

            return true;
        });
    }
}
