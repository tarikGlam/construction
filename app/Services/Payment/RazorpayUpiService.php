<?php

namespace App\Services\Payment;

use App\Models\ExternalService;
use App\Models\PaymentGatewayWebhookEvent;
use App\Models\PosPaymentAttempt;
use App\Models\PosSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

class RazorpayUpiService implements PaymentGatewayInterface
{
    /**
     * Retrieve active Razorpay credentials from external_services.
     *
     * @return array{key: string, secret: string, webhook_secret: ?string}
     */
    public function getCredentials(): array
    {
        $service = ExternalService::where('name', 'Razorpay')
            ->where('type', 'payment')
            ->first();

        if (!$service || !(bool) $service->active) {
            abort(503, 'Razorpay gateway is not active or configured.');
        }

        if ($service->module_status) {
            $modules = is_array($service->module_status)
                ? $service->module_status
                : json_decode($service->module_status, true);

            if (isset($modules['pos']) && !$modules['pos']) {
                abort(403, 'Razorpay gateway is disabled for POS module.');
            }
        }

        $lines = explode(';', $service->details ?? '');
        $keys = array_map('trim', explode(',', $lines[0] ?? ''));
        $vals = array_map('trim', explode(',', $lines[1] ?? ''));

        $creds = [];
        foreach ($keys as $idx => $keyName) {
            if ($keyName !== '') {
                $creds[strtolower(str_replace(' ', '_', $keyName))] = $vals[$idx] ?? '';
            }
        }

        $key = $creds['key'] ?? '';
        $secret = $creds['secret'] ?? '';
        $webhookSecret = $creds['webhook_secret'] ?? ($creds['webhook'] ?? null);

        if (empty($key) || empty($secret)) {
            abort(500, 'Razorpay Key and Secret are incomplete in system settings.');
        }

        return [
            'key' => $key,
            'secret' => $secret,
            'webhook_secret' => !empty($webhookSecret) ? $webhookSecret : null,
        ];
    }

    /**
     * Check if UPI payment method is available and configured for POS.
     */
    public function isAvailable(): bool
    {
        try {
            $posSetting = PosSetting::latest()->first();
            if ($posSetting) {
                $options = array_map('trim', explode(',', $posSetting->payment_options ?? ''));
                if (!in_array('upi', $options, true) && !in_array('razorpay', $options, true)) {
                    return false;
                }
            }

            $creds = $this->getCredentials();
            return !empty($creds['key']) && !empty($creds['secret']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Create a Razorpay UPI Order and persist a PosPaymentAttempt record.
     */
    public function createUpiOrder(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'customer_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'sale_context' => 'nullable|array',
        ]);

        $amount = (float) $request->amount;
        $creds = $this->getCredentials();
        $api = new Api($creds['key'], $creds['secret']);

        $amountInPaise = (int) round($amount * 100);
        $attemptUuid = 'pos_upi_' . Str::uuid()->toString();

        try {
            $receipt = substr('pos_' . str_replace('-', '', $attemptUuid), 0, 40);
            $orderData = [
                'receipt' => $receipt,
                'amount' => $amountInPaise,
                'currency' => 'INR',
                'notes' => [
                    'attempt_uuid' => $attemptUuid,
                    'customer_id' => (string) ($request->customer_id ?? ''),
                    'warehouse_id' => (string) ($request->warehouse_id ?? ''),
                    'method' => 'upi',
                    'app' => 'SalePro POS',
                ],
            ];

            $order = $api->order->create($orderData);

            $attempt = PosPaymentAttempt::create([
                'attempt_uuid' => $attemptUuid,
                'gateway' => 'razorpay',
                'method' => 'upi',
                'order_id' => $order['id'],
                'expected_amount' => $amount,
                'currency' => 'INR',
                'state' => 'initiated',
                'customer_id' => $request->customer_id ? (int) $request->customer_id : null,
                'warehouse_id' => $request->warehouse_id ? (int) $request->warehouse_id : null,
                'user_id' => Auth::id(),
                'sale_context' => $request->sale_context,
            ]);

            return response()->json([
                'success' => true,
                'attempt_uuid' => $attemptUuid,
                'key' => $creds['key'],
                'order_id' => $order['id'],
                'amount_paise' => $amountInPaise,
                'amount' => $amount,
                'currency' => 'INR',
            ]);
        } catch (\Throwable $e) {
            Log::error('Razorpay UPI Order Creation Failed', [
                'error' => $e->getMessage(),
                'amount' => $amount,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize UPI order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Authoritative server-side verification of Razorpay payment signature & entity.
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        $request->validate([
            'attempt_uuid' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $attempt = PosPaymentAttempt::where('attempt_uuid', $request->attempt_uuid)->first();

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payment attempt reference.',
            ], 404);
        }

        if ($attempt->isFinalized()) {
            return response()->json([
                'success' => true,
                'verified' => true,
                'state' => 'finalized',
                'sale_id' => $attempt->sale_id,
                'attempt_uuid' => $attempt->attempt_uuid,
            ]);
        }

        $creds = $this->getCredentials();
        $paymentId = $request->razorpay_payment_id;
        $signature = $request->razorpay_signature;

        // 1. Verify HMAC SHA256 signature using the SERVER-STORED order ID
        $expectedSignature = hash_hmac('sha256', $attempt->order_id . '|' . $paymentId, $creds['secret']);

        if (!hash_equals($expectedSignature, $signature)) {
            $attempt->markFailed('Signature verification mismatch.');
            return response()->json([
                'success' => false,
                'message' => 'Invalid payment signature.',
            ], 400);
        }

        // 2. Fetch authoritative payment entity directly from Razorpay API
        try {
            $api = new Api($creds['key'], $creds['secret']);
            $payment = $api->payment->fetch($paymentId);

            // Assert matching order ID
            if ($payment->order_id !== $attempt->order_id) {
                $attempt->markFailed('Order ID mismatch between attempt and gateway payment.');
                return response()->json([
                    'success' => false,
                    'message' => 'Order mismatch detected for this payment.',
                ], 422);
            }

            // Assert matching expected amount (in paise)
            $expectedPaise = (int) round($attempt->expected_amount * 100);
            if ((int) $payment->amount !== $expectedPaise) {
                $attempt->markFailed("Amount mismatch. Expected: {$expectedPaise}, Received: {$payment->amount}");
                return response()->json([
                    'success' => false,
                    'message' => 'Payment amount does not match expected amount.',
                ], 422);
            }

            // Assert INR currency
            if (strtoupper((string) $payment->currency) !== 'INR') {
                $attempt->markFailed('Currency mismatch. Expected INR.');
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment currency.',
                ], 422);
            }

            // Assert payment method is UPI
            if (strtolower((string) $payment->method) !== 'upi') {
                $attempt->markFailed('Payment method is not UPI.');
                return response()->json([
                    'success' => false,
                    'message' => 'Selected payment rail was not UPI.',
                ], 422);
            }

            // Assert payment state is captured
            if ($payment->status !== 'captured') {
                return response()->json([
                    'success' => false,
                    'status' => $payment->status,
                    'message' => 'Payment is not captured yet (Current status: ' . $payment->status . ').',
                ], 400);
            }

            // Sanitized, non-sensitive verification payload for audit
            $verificationData = [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'vpa' => $payment->vpa ?? null,
                'bank' => $payment->bank ?? null,
                'wallet' => $payment->wallet ?? null,
                'acquirer_data' => [
                    'rrn' => $payment->acquirer_data['rrn'] ?? null,
                    'upi_transaction_id' => $payment->acquirer_data['upi_transaction_id'] ?? null,
                ],
                'fee' => $payment->fee ?? null,
                'tax' => $payment->tax ?? null,
                'created_at' => $payment->created_at ?? time(),
            ];

            $attempt->markVerified($payment->id, $verificationData);

            return response()->json([
                'success' => true,
                'verified' => true,
                'attempt_uuid' => $attempt->attempt_uuid,
                'payment_id' => $payment->id,
                'order_id' => $attempt->order_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Razorpay UPI API Verification Failed', [
                'attempt_uuid' => $attempt->attempt_uuid,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Authoritative payment verification failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Webhook Handler with HMAC signature validation and persistent deduplication.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $signature = $request->header('X-Razorpay-Signature');
        $eventId = $request->header('X-Razorpay-Event-Id');
        $rawPayload = $request->getContent();

        if (empty($signature)) {
            return response()->json(['error' => 'Missing webhook signature.'], 400);
        }

        $creds = $this->getCredentials();
        $webhookSecret = $creds['webhook_secret'] ?? $creds['secret'];

        // 1. Verify webhook signature against raw request body
        $expectedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Razorpay Webhook Signature Mismatch', ['signature' => $signature]);
            return response()->json(['error' => 'Invalid webhook signature.'], 400);
        }

        $payload = json_decode($rawPayload, true);
        if (!$payload || !isset($payload['event'])) {
            return response()->json(['error' => 'Invalid webhook payload JSON.'], 400);
        }

        $eventType = $payload['event'];
        $eventRecordId = $eventId ?: ('evt_' . md5($rawPayload));

        // 2. Persistent event deduplication
        if (PaymentGatewayWebhookEvent::hasBeenProcessed('razorpay', $eventRecordId)) {
            return response()->json(['status' => 'already_processed']);
        }

        DB::beginTransaction();
        try {
            $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
            $orderEntity = $payload['payload']['order']['entity'] ?? [];

            $paymentId = $paymentEntity['id'] ?? null;
            $orderId = $paymentEntity['order_id'] ?? ($orderEntity['id'] ?? null);

            $webhookEvent = PaymentGatewayWebhookEvent::firstOrCreate(
                ['gateway' => 'razorpay', 'event_id' => $eventRecordId],
                [
                    'event_type' => $eventType,
                    'payment_id' => $paymentId,
                    'order_id' => $orderId,
                    'payload' => $payload,
                    'received_at' => now(),
                ]
            );

            // 3. Process event according to event type
            if (in_array($eventType, ['payment.captured', 'order.paid'], true)) {
                $attempt = null;

                if ($orderId) {
                    $attempt = PosPaymentAttempt::where('gateway', 'razorpay')
                        ->where('order_id', $orderId)
                        ->first();
                }

                if (!$attempt && $paymentId) {
                    $attempt = PosPaymentAttempt::where('gateway', 'razorpay')
                        ->where('payment_id', $paymentId)
                        ->first();
                }

                if ($attempt && !$attempt->isFinalized()) {
                    $verificationData = [
                        'payment_id' => $paymentId,
                        'order_id' => $orderId,
                        'status' => $paymentEntity['status'] ?? 'captured',
                        'amount' => $paymentEntity['amount'] ?? null,
                        'currency' => $paymentEntity['currency'] ?? 'INR',
                        'method' => $paymentEntity['method'] ?? 'upi',
                        'webhook_event_id' => $eventRecordId,
                        'webhook_event_type' => $eventType,
                    ];

                    $attempt->markVerified($paymentId ?? ($attempt->payment_id ?: 'webhook_verified'), $verificationData);
                    Log::info('PosPaymentAttempt verified via Webhook', [
                        'attempt_uuid' => $attempt->attempt_uuid,
                        'order_id' => $orderId,
                        'payment_id' => $paymentId,
                    ]);
                }
            } elseif ($eventType === 'payment.failed') {
                if ($orderId) {
                    $attempt = PosPaymentAttempt::where('gateway', 'razorpay')
                        ->where('order_id', $orderId)
                        ->first();

                    // Monotonic check: Never regress a finalized or verified attempt to failed
                    if ($attempt && !$attempt->isVerified()) {
                        $reason = $paymentEntity['error_description'] ?? 'Payment failed via webhook notification.';
                        $attempt->markFailed($reason);
                    }
                }
            }

            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            DB::commit();
            return response()->json(['status' => 'processed']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Razorpay Webhook Processing Error', [
                'event_id' => $eventRecordId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed.'], 500);
        }
    }

    /**
     * Interface push implementation.
     */
    public function push(Request $request): JsonResponse
    {
        return $this->createUpiOrder($request);
    }

    /**
     * Interface queryStatus implementation.
     */
    public function queryStatus(Request $request): JsonResponse
    {
        $request->validate(['reference_id' => 'required|string']);

        $attempt = PosPaymentAttempt::where('attempt_uuid', $request->reference_id)
            ->orWhere('order_id', $request->reference_id)
            ->first();

        if (!$attempt) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json([
            'status' => $attempt->state,
            'verified' => $attempt->isVerified(),
            'finalized' => $attempt->isFinalized(),
            'payment_id' => $attempt->payment_id,
            'order_id' => $attempt->order_id,
        ]);
    }

    /**
     * Interface callback implementation.
     */
    public function callback(Request $request): JsonResponse
    {
        return $this->handleWebhook($request);
    }
}
