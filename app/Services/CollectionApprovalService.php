<?php

namespace App\Services;

use App\Exceptions\SaleValidationException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\GeneralSetting;
use App\Models\PendingCollection;
use App\Models\Sale;
use App\Models\User;
use App\Services\SalePaymentService;
use App\Services\WarehouseAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CollectionApprovalService
{
    public function __construct(
        protected SalePaymentService $paymentService,
        protected WarehouseAccessService $warehouseAccess
    ) {}

    /**
     * Intercept field collection payments and route to pending queue if eligible.
     *
     * @param Request $request
     * @return array|false
     * @throws SaleValidationException
     */
    public function interceptCollection(Request $request): array|false
    {
        if ($request->has('is_approval') && $request->is_approval) {
            return false;
        }

        $submittedMethod = (string) $request->paid_by_id;
        $stableCode = match ($submittedMethod) {
            '1' => 'cash',
            '2' => 'gift_card',
            '3' => 'card',
            '4' => 'cheque',
            '5' => 'paypal',
            '6' => 'deposit',
            '7' => 'points',
            default => strtolower($submittedMethod)
        };

        $settings = GeneralSetting::first();
        if (!$settings || !$settings->is_payment_approval_required) {
            return false;
        }

        $user = Auth::user();
        if ($user && $user->hasPermissionTo('pending_collections-bypass')) {
            return false;
        }

        $eligibleOptions = array_filter(explode(',', $settings->field_collection_payment_options ?? 'cash,cheque'));
        if (!in_array($stableCode, $eligibleOptions, true)) {
            throw new SaleValidationException(__('db.payment_method_not_permitted') ?: 'This payment method is not permitted for staff field collection. Please use an approved method.');
        }

        return DB::transaction(function () use ($request, $stableCode, $user) {
            $saleId = (int) $request->sale_id;
            $amount = (float) $request->amount;

            if ($amount <= 0) {
                throw new SaleValidationException(__('db.amount_must_be_positive') ?: 'Amount must be positive.');
            }

            $sale = Sale::whereKey($saleId)->lockForUpdate()->firstOrFail();
            $customer = Customer::findOrFail($sale->customer_id);

            // Authorize warehouse access using WarehouseAccessService
            try {
                $this->warehouseAccess->authorizeWarehouse((int) $sale->warehouse_id);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                throw new SaleValidationException(__('db.warehouse_access_denied') ?: 'You do not have permission to collect for this warehouse.');
            }

            $accountId = (int) $request->account_id;
            $account = Account::whereKey($accountId)->where('is_active', true)->first();
            if (!$account) {
                throw new SaleValidationException(__('db.account_invalid_or_inactive') ?: 'Selected account is invalid or inactive.');
            }

            $pendingAmount = PendingCollection::where('sale_id', $saleId)
                ->where('status', PendingCollection::STATUS_PENDING)
                ->lockForUpdate()
                ->sum('amount');

            $outstanding = round($sale->grand_total - $sale->paid_amount - $pendingAmount, 4);
            if (round($amount, 4) > $outstanding) {
                throw new SaleValidationException(__('db.amount_exceeds_due') ?: 'Amount plus existing pending collections exceeds the customer due balance.');
            }

            $idempotencyKey = $request->input('idempotency_key');
            if (!$idempotencyKey) {
                throw new SaleValidationException(__('db.idempotency_key_missing') ?: 'Idempotency key is missing. Please refresh the page.');
            }

            $existing = PendingCollection::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return ['payment_created' => true, 'message' => __('db.collection_already_recorded') ?: 'Collection already recorded.'];
            }

            $payingMethodName = match ($stableCode) {
                'cash' => 'Cash',
                'cheque' => 'Cheque',
                default => ucfirst($stableCode),
            };

            $pending = new PendingCollection();
            $pending->idempotency_key = $idempotencyKey;
            $pending->reference_no = 'pcol-' . date("Ymdhis") . rand(10, 99);
            $pending->customer_id = $customer->id;
            $pending->sale_id = $sale->id;
            $pending->warehouse_id = $sale->warehouse_id;
            $pending->account_id = $account->id;
            $pending->amount = $amount;
            $pending->paying_method = $payingMethodName;
            $pending->tender_reference = $request->cheque_no;
            $pending->payment_note = $request->payment_note;
            $pending->collected_by = $user ? $user->id : null;
            $pending->collected_at = now();
            $pending->collected_by_name = $user ? $user->name : null;
            $pending->status = PendingCollection::STATUS_PENDING;
            $pending->save();

            return [
                'payment_created' => true,
                'message' => __('db.collection_recorded_as_pending') ?: 'Collection recorded as pending and awaits approval.',
                'print_receipt' => false,
                'installment' => false,
            ];
        });
    }

    /**
     * Atomically approve a pending collection through the authoritative SalePaymentService.
     * Enforces exactly-once execution via lockForUpdate() and unique payments.pending_collection_id.
     *
     * @param int $id
     * @param int $approverId
     * @param string $approverName
     * @return array
     * @throws \Exception
     */
    public function approve(int $id, int $approverId, string $approverName): array
    {
        return DB::transaction(function () use ($id, $approverId, $approverName) {
            $pending = PendingCollection::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($pending->status !== PendingCollection::STATUS_PENDING) {
                throw new \Exception(__('db.only_pending_can_be_approved') ?: 'Only pending collections can be approved.');
            }

            // Permission and Self-approval check
            $approver = Auth::user() ?? User::find($approverId);
            if (!$approver || !$approver->hasPermissionTo('pending_collections-approve')) {
                throw new \Exception(__('db.not_permitted') ?: 'Unauthorized action.');
            }
            if ($pending->collected_by === $approverId && !$approver->hasPermissionTo('pending_collections-self_approve')) {
                throw new \Exception(__('db.cannot_self_approve') ?: 'You do not have permission to approve your own collections.');
            }

            // Warehouse access check
            $this->warehouseAccess->authorizeWarehouse((int) $pending->warehouse_id);

            // Lock sale and re-check balance
            $sale = Sale::whereKey($pending->sale_id)->lockForUpdate()->firstOrFail();
            $outstanding = round($sale->grand_total - $sale->paid_amount, 4);
            if (round($pending->amount, 4) > $outstanding) {
                throw new \Exception(__('db.amount_exceeds_current_due') ?: 'Amount exceeds the current customer due balance. Cannot approve.');
            }

            $paidById = match (strtolower($pending->paying_method)) {
                'cash' => '1',
                'gift card' => '2',
                'credit card' => '3',
                'cheque' => '4',
                'paypal' => '5',
                'deposit' => '6',
                'points' => '7',
                default => strtolower($pending->paying_method),
            };

            $paymentData = [
                'sale_id' => $sale->id,
                'amount' => $pending->amount,
                'paying_amount' => $pending->amount,
                'change' => 0,
                'paid_by_id' => $paidById,
                'account_id' => $pending->account_id,
                'cheque_no' => $pending->tender_reference,
                'payment_note' => $pending->payment_note,
                'is_approval' => true,
            ];

            // Delegate to the authoritative payment lifecycle service
            // Passing pendingCollectionId enforces unique payments.pending_collection_id in the database!
            $result = $this->paymentService->createPayment(
                $paymentData,
                $approver,
                $pending->id
            );

            if (!empty($result['payment_id'])) {
                $pending->status = PendingCollection::STATUS_APPROVED;
                $pending->approved_by = $approverId;
                $pending->approved_at = now();
                $pending->approved_by_name = $approverName;
                $pending->payment_id = $result['payment_id'];
                $pending->save();

                return [
                    'success' => true,
                    'message' => __('db.collection_approved_successfully') ?: 'Collection approved successfully.',
                ];
            }

            throw new \Exception(__('db.failed_to_create_finalized_payment') ?: 'Failed to create finalized payment during approval.');
        });
    }

    /**
     * Atomically reject a pending collection with reason. Zero financial effect.
     *
     * @param int $id
     * @param int $rejectorId
     * @param string $rejectorName
     * @param string $reason
     * @return array
     * @throws \Exception
     */
    public function reject(int $id, int $rejectorId, string $rejectorName, string $reason): array
    {
        return DB::transaction(function () use ($id, $rejectorId, $rejectorName, $reason) {
            $pending = PendingCollection::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($pending->status !== PendingCollection::STATUS_PENDING) {
                throw new \Exception(__('db.only_pending_can_be_rejected') ?: 'Only pending collections can be rejected.');
            }

            $rejector = Auth::user() ?? User::find($rejectorId);
            if (!$rejector || !$rejector->hasPermissionTo('pending_collections-reject')) {
                throw new \Exception(__('db.not_permitted') ?: 'Unauthorized action.');
            }

            $this->warehouseAccess->authorizeWarehouse((int) $pending->warehouse_id);

            $pending->status = PendingCollection::STATUS_REJECTED;
            $pending->rejected_by = $rejectorId;
            $pending->rejected_at = now();
            $pending->rejected_by_name = $rejectorName;
            $pending->rejection_reason = $reason;
            $pending->save();

            return [
                'success' => true,
                'message' => __('db.collection_rejected_successfully') ?: 'Collection rejected successfully.',
            ];
        });
    }

    /**
     * Atomically reverse an approved collection exactly once via SalePaymentService.
     * Preserves Payment row and links, reverses GL journals and balances.
     *
     * @param int $id
     * @param int $reverserId
     * @param string $reverserName
     * @param string $reason
     * @return array
     * @throws \Exception
     */
    public function reverse(int $id, int $reverserId, string $reverserName, string $reason): array
    {
        return DB::transaction(function () use ($id, $reverserId, $reverserName, $reason) {
            $pending = PendingCollection::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($pending->status !== PendingCollection::STATUS_APPROVED) {
                throw new \Exception(__('db.only_approved_can_be_reversed') ?: 'Only approved collections can be reversed.');
            }

            if (!$pending->payment_id) {
                throw new \Exception(__('db.no_payment_linked') ?: 'No finalized payment linked to reverse.');
            }

            $reverser = Auth::user() ?? User::find($reverserId);
            if (!$reverser || !$reverser->hasPermissionTo('pending_collections-reverse')) {
                throw new \Exception(__('db.not_permitted') ?: 'Unauthorized action.');
            }

            $this->warehouseAccess->authorizeWarehouse((int) $pending->warehouse_id);

            // Exactly-once reversal via SalePaymentService
            $reverser = Auth::user() ?? User::find($reverserId);
            $this->paymentService->reversePayment((int) $pending->payment_id, $reverser, $reason);

            // Update collection status ONLY after the reversal succeeds
            $pending->status = PendingCollection::STATUS_REVERSED;
            $pending->reversed_by = $reverserId;
            $pending->reversed_at = now();
            $pending->reversed_by_name = $reverserName;
            $pending->reversal_reason = $reason;
            $pending->save();

            return [
                'success' => true,
                'message' => __('db.collection_reversed_successfully') ?: 'Collection reversed successfully.',
            ];
        });
    }

    /**
     * Handover a pending collection to another user. Zero financial effect.
     *
     * @param int $id
     * @param int $handedOverTo
     * @param string $handedOverToName
     * @param string|null $notes
     * @return array
     * @throws \Exception
     */
    public function handover(int $id, int $handedOverTo, string $handedOverToName, ?string $notes): array
    {
        return DB::transaction(function () use ($id, $handedOverTo, $handedOverToName, $notes) {
            $pending = PendingCollection::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($pending->status !== PendingCollection::STATUS_PENDING) {
                throw new \Exception(__('db.only_pending_can_be_handed_over') ?: 'Only pending collections can be handed over.');
            }

            $this->warehouseAccess->authorizeWarehouse((int) $pending->warehouse_id);

            $pending->handed_over_to = $handedOverTo;
            $pending->handed_over_at = now();
            $pending->handed_over_to_name = $handedOverToName;
            if ($notes) {
                $pending->handover_notes = $notes;
            }
            $pending->save();

            return [
                'success' => true,
                'message' => __('db.collection_handed_over_successfully') ?: 'Collection handed over successfully.',
            ];
        });
    }
}
