<?php

namespace App\Services;

use App\DTOs\NotificationEventData;
use App\Exceptions\SaleValidationException;
use App\Mail\PaymentDetails;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Installment;
use App\Models\MailSetting;
use App\Models\Payment;
use App\Models\PaymentWithCheque;
use App\Models\PaymentWithCreditCard;
use App\Models\PaymentWithGiftCard;
use App\Models\PosSetting;
use App\Models\RewardPoint;
use App\Models\RewardPointSetting;
use App\Models\Sale;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Domain\SaleDomainService;
use App\Services\InvoiceService;
use App\Services\NotificationService;
use App\Services\SalePaymentIntegrity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Stripe;

class SalePaymentService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected AccountingService $accountingService,
        protected SalePaymentIntegrity $integrity,
        protected SaleDomainService $saleDomainService
    ) {}

    /**
     * Create and record a finalized payment for a sale.
     *
     * @param array $data
     * @param User|null $user
     * @param int|null $pendingCollectionId
     * @return array
     * @throws SaleValidationException
     */
    public function createPayment(array $data, ?User $user = null, ?int $pendingCollectionId = null): array
    {
        $user = $user ?? Auth::user();
        $userId = $user ? $user->id : null;

        return DB::transaction(function () use ($data, $user, $userId, $pendingCollectionId) {
            $saleId = (int) $data['sale_id'];
            $sale = $this->integrity->lockSale($saleId);

            $amount = (float) ($data['amount'] ?? 0.00);
            $payingAmount = (float) ($data['paying_amount'] ?? $amount);
            $change = (float) ($data['change'] ?? ($payingAmount - $amount));

            $paidById = (string) ($data['paid_by_id'] ?? '1');
            $payingMethod = match ($paidById) {
                '1' => 'Cash',
                '2' => 'Gift Card',
                '3' => 'Credit Card',
                '4' => 'Cheque',
                '5' => 'Paypal',
                '6' => 'Deposit',
                '7' => 'Points',
                default => ucfirst($paidById)
            };

            $candidate = new Payment([
                'sale_id' => $sale->id,
                'amount' => $amount,
                'change' => bcsub($this->integrity->money($payingAmount), $this->integrity->money($amount), 4),
                'paying_method' => $paidById === '1' ? 'Cash' : 'Other',
                'currency_id' => $data['currency_id'] ?? $sale->currency_id,
                'exchange_rate' => $data['exchange_rate'] ?? $sale->exchange_rate,
            ]);
            $this->integrity->validate($candidate, $sale);

            // Handle Deposit / Points redemption through SaleDomainService if applicable
            if (in_array($paidById, ['6', '7'], true)) {
                $payment = $this->saleDomainService->addSalePayment($data, $user);
                $sale = Sale::findOrFail($payment->sale_id);
                $this->dispatchPaymentNotifications($sale, $payment);
                $this->completePaymentInstallment($payment, $data);

                return [
                    'payment_created' => true,
                    'payment' => $payment,
                    'payment_id' => (int) $payment->id,
                    'print_receipt' => (int) ($data['print_receipt'] ?? 0) === 1,
                    'installment' => (int) ($data['installment_id'] ?? 0) > 0,
                    'message' => __('db.Payment created successfully') ?: 'Payment created successfully',
                ];
            }

            // Update Sale paid_amount and payment_status
            $sale->paid_amount += $amount;
            $balance = $sale->grand_total - $sale->paid_amount;
            if (abs($balance) < 0.001) {
                $sale->payment_status = 4; // Paid
            } elseif ($balance > 0 || $balance < 0) {
                $sale->payment_status = 2; // Partial / Due
            }

            // Check cash register
            $cashRegisterData = CashRegister::where([
                ['user_id', $userId],
                ['warehouse_id', $sale->warehouse_id],
                ['status', true],
            ])->first();

            $paymentAt = isset($data['payment_at'])
                ? normalize_to_sql_datetime($data['payment_at'])
                : date('Y-m-d H:i:s');

            $payment = new Payment();
            $payment->user_id = $userId;
            $payment->sale_id = $sale->id;
            $payment->pending_collection_id = $pendingCollectionId;
            if ($cashRegisterData) {
                $payment->cash_register_id = $cashRegisterData->id;
            }
            $payment->account_id = $data['account_id'];
            $payment->payment_reference = $this->invoiceService->generateInvoiceName('spr-');
            $payment->amount = $amount;
            $payment->currency_id = $data['currency_id'] ?? $sale->currency_id;
            $payment->exchange_rate = $data['exchange_rate'] ?? $sale->exchange_rate;
            $payment->change = $change;
            $payment->paying_method = $payingMethod;
            $payment->payment_note = $data['payment_note'] ?? null;
            $payment->payment_receiver = $data['payment_receiver'] ?? null;
            if (isset($data['document'])) {
                $payment->document = $data['document'];
            }
            $payment->payment_at = $paymentAt;

            $payment->save();
            $sale->save();

            // Method-specific side effects
            if ($payingMethod === 'Gift Card' && !empty($data['gift_card_id'])) {
                $giftCard = GiftCard::find($data['gift_card_id']);
                if ($giftCard) {
                    $giftCard->expense += $amount;
                    $giftCard->save();
                    PaymentWithGiftCard::create(array_merge($data, ['payment_id' => $payment->id]));
                }
            } elseif ($payingMethod === 'Cheque') {
                PaymentWithCheque::create(array_merge($data, ['payment_id' => $payment->id]));
            } elseif ($payingMethod === 'Credit Card') {
                $posSetting = PosSetting::latest()->first();
                if ($posSetting && $posSetting->stripe_secret_key && !empty($data['stripeToken'])) {
                    Stripe::setApiKey($posSetting->stripe_secret_key);
                    $charge = \Stripe\Charge::create([
                        'amount' => $amount * 100,
                        'currency' => 'usd',
                        'source' => $data['stripeToken'],
                    ]);
                    PaymentWithCreditCard::create(array_merge($data, [
                        'payment_id' => $payment->id,
                        'customer_id' => $sale->customer_id,
                        'charge_id' => $charge->id,
                    ]));
                }
            }

            // Record accounting entry
            $accountingResult = $this->accountingService->recordPayment($payment);
            if (!$accountingResult->success) {
                Log::error('Accounting failed for Sale Payment', [
                    'payment_id' => $payment->id,
                    'error' => $accountingResult->error,
                ]);
                throw new SaleValidationException(
                    $accountingResult->error ?: __('db.customer_deposit_payment_failed')
                );
            }

            // Notification dispatch
            $this->dispatchPaymentNotifications($sale, $payment);

            // Installment completion
            $this->completePaymentInstallment($payment, $data);

            // Refresh totals
            $this->integrity->refreshTotals($sale->fresh());

            // Optional email notification
            $customer = Customer::find($sale->customer_id);
            $message = __('db.Payment created successfully') ?: 'Payment created successfully';
            $mailSetting = MailSetting::latest()->first();
            if ($customer && $customer->email && $mailSetting) {
                try {
                    $mailData = [
                        'email' => $customer->email,
                        'sale_reference' => $sale->reference_no,
                        'payment_reference' => $payment->payment_reference,
                        'payment_method' => $payment->paying_method,
                        'grand_total' => $sale->grand_total,
                        'paid_amount' => $payment->amount,
                        'currency' => config('currency'),
                        'due' => $balance,
                    ];
                    Mail::to($customer->email)->send(new PaymentDetails($mailData));
                } catch (\Throwable $e) {
                    // Suppress email transport failure without aborting payment
                }
            }

            return [
                'payment_created' => true,
                'payment' => $payment,
                'payment_id' => (int) $payment->id,
                'print_receipt' => (int) ($data['print_receipt'] ?? 0) === 1,
                'installment' => (int) ($data['installment_id'] ?? 0) > 0,
                'message' => $message,
            ];
        });
    }

    /**
     * Perform an auditable, exactly-once reversal of a finalized sale payment.
     * Retains the Payment/source row, updates accounting_status = 'reversed',
     * reverses the GL accounting journal, and adjusts sale paid_amount.
     *
     * @param int $paymentId
     * @param User|null $user
     * @param string|null $reason
     * @return Payment
     * @throws \Exception
     */
    public function reversePayment(int $paymentId, ?User $user = null, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($paymentId, $user, $reason) {
            $payment = Payment::whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->accounting_status === 'reversed') {
                throw new \Exception('Payment has already been reversed.');
            }

            $sale = Sale::whereKey($payment->sale_id)->lockForUpdate()->firstOrFail();

            // Reverse customer deposit or points liability if applicable
            if ($payment->paying_method === 'Deposit') {
                app(\App\Services\CustomerDepositService::class)->restore((int) $sale->customer_id, $payment->amount);
            } elseif ($payment->paying_method === 'Points') {
                app(\App\Services\RewardPointService::class)->restorePayment($payment, $sale);
            }

            // Adjust sale balance
            $sale->paid_amount -= $payment->amount;
            $balance = $sale->grand_total - $sale->paid_amount;
            if (abs($balance) < 0.001) {
                $sale->payment_status = 4;
            } elseif ($sale->paid_amount > 0) {
                $sale->payment_status = 2;
            } else {
                $sale->payment_status = 1; // Due / Pending
            }
            $sale->save();

            // Reverse GL accounting journal
            $reversal = $this->accountingService->reverseTransaction(get_class($payment), $payment->id, '_reversed');
            if (!$reversal->success) {
                throw new SaleValidationException(
                    $reversal->error ?: 'Accounting reversal failed for sale payment.'
                );
            }

            // Mark payment as reversed and preserve audit trail
            $payment->accounting_status = 'reversed';
            if ($reason) {
                $payment->payment_note = trim(($payment->payment_note ? $payment->payment_note . ' | ' : '') . 'Reversed: ' . $reason);
            }
            $payment->save();

            // If linked to an installment, revert installment status
            if ($payment->installment_id) {
                Installment::where('id', $payment->installment_id)->update(['status' => 'pending']);
            }

            $this->integrity->refreshTotals($sale->fresh());

            return $payment;
        });
    }

    protected function completePaymentInstallment(Payment $payment, array $data): void
    {
        $installmentId = (int) ($data['installment_id'] ?? 0);
        if (!$installmentId) {
            return;
        }

        Installment::whereKey($installmentId)->update([
            'status' => 'completed',
            'payment_date' => $data['payment_at'] ?? $payment->payment_at,
        ]);
        $payment->installment_id = $installmentId;
        $payment->save();
    }

    protected function dispatchPaymentNotifications(Sale $sale, $paymentAmountOrModel): void
    {
        DB::afterCommit(function () use ($sale, $paymentAmountOrModel) {
            try {
                $customer = $sale->customer ?? Customer::find($sale->customer_id);
                if (!$customer) {
                    return;
                }

                if ($paymentAmountOrModel instanceof Payment) {
                    $payment = $paymentAmountOrModel;
                } else {
                    $amount = (float) $paymentAmountOrModel;
                    $payment = Payment::where('sale_id', $sale->id)->latest('id')->first();
                    if (!$payment) {
                        $payment = new Payment([
                            'sale_id' => $sale->id,
                            'amount' => $amount,
                            'created_at' => now(),
                        ]);
                        $payment->id = rand(1000, 999999);
                    }
                }

                $dto = NotificationEventData::forPayment($sale, $payment, $customer);
                app(NotificationService::class)->dispatch($dto);
            } catch (\Throwable $e) {
                Log::error('Payment notification engine failed: ' . $e->getMessage());
            }
        });
    }
}
