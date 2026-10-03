<?php

namespace App\Http\Controllers;

use App\Services\Payment\MoyasarService;
use App\Services\Payment\MpesaService;
use App\Services\Payment\MtnMoMoService;
use App\Services\Payment\PayHereService;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\Cache;

class PaymentGatewayController extends Controller
{
    /**
     * Gateway slug থেকে সঠিক Service রিটার্ন করো
     */
    private function resolveService(string $gateway): PaymentGatewayInterface
    {
        return match(strtolower($gateway)) {
            'mpesa'    => new MpesaService(),
            'mtnmomo'  => new MtnMoMoService(),
            'payhere'  => new PayHereService(),
            'stripe'   => new \App\Services\Payment\StripeService(),
            'moyasar'  => new MoyasarService(),
            'razorpay', 'upi' => new \App\Services\Payment\RazorpayUpiService(),
            default    => abort(404, "Payment gateway [{$gateway}] not found."),
        };
    }

    /**
     * Create Razorpay UPI Order for POS
     * Route: POST /payment/razorpay/create-upi-order
     */
    public function createRazorpayUpiOrder(Request $request): JsonResponse
    {
        $service = new \App\Services\Payment\RazorpayUpiService();
        return $service->createUpiOrder($request);
    }

    /**
     * Verify Razorpay UPI Payment for POS
     * Route: POST /payment/razorpay/verify-upi
     */
    public function verifyRazorpayUpi(Request $request): JsonResponse
    {
        $service = new \App\Services\Payment\RazorpayUpiService();
        return $service->verifyPayment($request);
    }

    /**
     * Razorpay Webhook Callback
     * Route: POST /payment/razorpay/webhook
     */
    public function razorpayWebhook(Request $request): JsonResponse
    {
        $service = new \App\Services\Payment\RazorpayUpiService();
        return $service->handleWebhook($request);
    }

    /**
     * STK Push / Request to Pay / Checkout Initiate
     * Route: POST /payment/{gateway}/push
     */
    public function push(Request $request, string $gateway): JsonResponse
    {
        $service = $this->resolveService($gateway);
        return $service->push($request);
    }

    /**
     * Payment status polling (Cache-based)
     * Route: POST /payment/{gateway}/query-status
     */
    public function queryStatus(Request $request, string $gateway): JsonResponse
    {
        $service = $this->resolveService($gateway);
        return $service->queryStatus($request);
    }

    /**
     * Webhook Callback — gateway server থেকে আসে
     * Route: POST /payment/{gateway}/callback
     */
    public function callback(Request $request, string $gateway): JsonResponse
    {
        $service = $this->resolveService($gateway);
        return $service->callback($request);
    }

    /**
     * M-Pesa Dynamic QR Code generate করো
     * Route: POST /payment/mpesa/generate-qr
     */
    public function generateQr(Request $request): JsonResponse
    {
        $service = new MpesaService();
        return $service->generateDynamicQr($request);
    }

    /**
     * MTN MoMo USSD QR Code generate করো
     * Route: POST /payment/mtnmomo/generate-qr
     */
    public function generateMtnQr(Request $request): JsonResponse
    {
        $service = new MtnMoMoService();
        return $service->generateUssdQr($request);
    }

    public function payhereCheckout($order_id)
    {
        $cached = Cache::get('payhere_payload_' . $order_id);
        if (!$cached) {
            abort(404, 'Payment session expired or invalid.');
        }
        return view('backend.payment.payhere_checkout', $cached);
    }

    public function paymentSuccess(Request $request)
    {
        // Return/success URLs are browser-controlled and are never authoritative
        // payment confirmation. Gateway callbacks/webhooks are solely responsible
        // for updating cached/transaction payment state. This remains true in local
        // environments because the Host header and APP_ENV are not trust boundaries.
        return '<div style="font-family:sans-serif;text-align:center;padding:50px;color:#15803d;">
                    <h1 style="font-size:50px;margin-bottom:10px;">✅</h1>
                    <h2>Payment Submitted</h2>
                    <p style="color:#64748b;">You can now close this window. Payment status will be confirmed automatically.</p>
                    <script>
                        // Try to close the popup if it was opened by window.open
                        setTimeout(function() {
                            window.close();
                        }, 2000);
                    </script>
                </div>';
    }

    public function paymentFailed()
    {
        return '<div style="font-family:sans-serif;text-align:center;padding:50px;color:#dc2626;">
                    <h1 style="font-size:50px;margin-bottom:10px;">❌</h1>
                    <h2>Payment Failed or Cancelled</h2>
                    <p style="color:#64748b;">You can close this window and try scanning again.</p>
                </div>';
    }
}
