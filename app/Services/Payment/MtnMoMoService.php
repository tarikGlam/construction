<?php

namespace App\Services\Payment;

use App\Models\ExternalService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MtnMoMoService implements PaymentGatewayInterface
{
    private function getCredentials(): array
    {
        $service = ExternalService::where('name', 'MtnMoMo')->first();
        if (!$service) {
            abort(500, 'MTN MoMo gateway not configured.');
        }
        $lines = explode(';', $service->details);
        $keys  = explode(',', $lines[0]);
        $vals  = explode(',', $lines[1]);
        return array_combine($keys, $vals);
    }

    private function getAccessToken(array $creds): string
    {
        $subscriptionKey = trim($creds['subscription_key'] ?? '');
        $apiUserId       = trim($creds['api_user_id']      ?? '');
        $apiKey          = trim($creds['api_key']          ?? '');
        $mode            = trim($creds['Mode']             ?? 'sandbox');

        $cacheKey    = 'mtnmomo_token_' . md5($apiUserId . '_' . $mode);
        $cachedToken = Cache::get($cacheKey);
        if ($cachedToken) {
            return $cachedToken;
        }

        $baseUrl = $mode === 'live'
            ? 'https://proxy.momoapi.mtn.com'
            : 'https://sandbox.momodeveloper.mtn.com';

        $auth = base64_encode($apiUserId . ':' . $apiKey);

        $ch = curl_init($baseUrl . '/collection/token/');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $auth,
            'Ocp-Apim-Subscription-Key: ' . $subscriptionKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        Log::info('MTN MoMo Token Response', [
            'http_code'  => $httpCode,
            'curl_error' => $curlError,
            'mode'       => $mode,
        ]);

        $data  = json_decode($response, true);
        $token = $data['access_token'] ?? '';

        if ($token) {
            Cache::put($cacheKey, $token, 3300); // 55 মিনিট ক্যাশে রাখো
        }

        return $token;
    }

    private function formatPhone(string $phone, string $countryCode = '256'): string
    {
        // Remove all non-numeric characters (+, spaces, dashes)
        $phone = preg_replace('/[^0-9]/', '', trim($phone));

        // Remove leading double zeros (e.g. 00256 -> 256)
        $phone = preg_replace('/^00/', '', $phone);

        // Remove leading country code to get local digits
        if (strpos($phone, $countryCode) === 0) {
            $phone = substr($phone, strlen($countryCode));
        }

        // Remove leading 0 (e.g. 0771234567 -> 771234567)
        if (strpos($phone, '0') === 0) {
            $phone = substr($phone, 1);
        }

        return $countryCode . $phone;
    }

    /**
     * MTN MoMo USSD QR Code generate করো
     * কাস্টমার MoMo App বা ক্যামেরা দিয়ে স্ক্যান করে USSD পেমেন্ট করতে পারবে
     */
    public function generateUssdQr(Request $request): JsonResponse
    {
        $request->validate([
            'amount'       => 'required|numeric|min:1',
            'merchant_code'=> 'nullable|string',
        ]);

        $creds        = $this->getCredentials();
        $countryCode  = trim($creds['country_code'] ?? '256');
        $merchantCode = trim($request->merchant_code ?? $creds['merchant_code'] ?? '');
        $amount       = (int) ceil($request->amount);

        // MTN MoMo USSD string format (Uganda: *165*3*{merchant}*{amount}#)
        // The USSD deep link format that works on most Android devices:
        // tel:*165*3*{merchantCode}*{amount}%23  (where %23 = #)
        if ($merchantCode) {
            $ussdCode  = "*165*3*{$merchantCode}*{$amount}#";
            $telLink   = 'tel:' . urlencode($ussdCode);
        } else {
            // Fallback: just the MoMo dial code without merchant specifics
            $ussdCode = "*165*3*{$amount}#";
            $telLink  = 'tel:' . urlencode($ussdCode);
        }

        // Generate QR code using the free qrserver.com API
        $qrSize    = 400;
        $qrUrl     = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qrSize . 'x' . $qrSize . '&data=' . urlencode($telLink);

        // Fetch and base64-encode so frontend can display it the same way as M-Pesa
        $ch = curl_init($qrUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $imageData = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        Log::info('MTN MoMo USSD QR Generated', [
            'ussd_code' => $ussdCode,
            'http_code' => $httpCode,
        ]);

        if ($imageData && $httpCode === 200) {
            return response()->json([
                'success'   => true,
                'qr_code'   => base64_encode($imageData),
                'ussd_code' => $ussdCode,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'QR Code generation failed.',
        ], 400);
    }

    public function push(Request $request): JsonResponse
    {
        $request->validate([
            'phone'  => 'required|string',
            'amount' => 'required|numeric|min:1',
        ]);

        $creds = $this->getCredentials();

        $required = ['subscription_key', 'api_user_id', 'api_key'];
        foreach ($required as $field) {
            if (empty(trim($creds[$field] ?? ''))) {
                return response()->json([
                    'success' => false,
                    'message' => "MTN MoMo credential missing: [{$field}]. Please configure it in Settings → Payment Gateways → MtnMoMo.",
                ], 422);
            }
        }

        $mode            = trim($creds['Mode']             ?? 'sandbox');
        $subscriptionKey = trim($creds['subscription_key'] ?? '');
        
        $baseCurrencyId  = \Illuminate\Support\Facades\DB::table('general_settings')->first()->currency ?? 1;
        $currency        = \Illuminate\Support\Facades\DB::table('currencies')->where('id', $baseCurrencyId)->value('code') ?? 'EUR';
        
        if ($mode === 'sandbox') {
            $currency = 'EUR';
        }

        $countryCode     = trim($creds['country_code']     ?? '256'); // default Uganda
        $environment     = $mode === 'live' ? 'mtnuganda' : 'sandbox';
        $baseUrl         = $mode === 'live'
            ? 'https://proxy.momoapi.mtn.com'
            : 'https://sandbox.momodeveloper.mtn.com';

        $token = $this->getAccessToken($creds);
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'MTN MoMo Authentication failed. Please check your API credentials.',
            ], 500);
        }

        $referenceId = (string) Str::uuid(); // Unique transaction ID
        $externalId  = (string) Str::uuid();
        $phone       = $this->formatPhone($request->phone, $countryCode);
        $amount      = (string) ceil($request->amount);
        $callbackUrl = route('payment.callback', ['gateway' => 'mtnmomo']);

        $payload = [
            'amount'                => $amount,
            'currency'              => $currency,
            'externalId'            => $externalId,
            'payer'                 => [
                'partyIdType' => 'MSISDN',
                'partyId'     => $phone,
            ],
            'payerMessage'          => 'Payment via SalePro',
            'payeeNote'             => 'SalePro POS Payment',
        ];

        $ch = curl_init($baseUrl . '/collection/v1_0/requesttopay');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'X-Reference-Id: ' . $referenceId,
            'X-Target-Environment: ' . $environment,
            'X-Callback-Url: ' . $callbackUrl,
            'Ocp-Apim-Subscription-Key: ' . $subscriptionKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        Log::info('MTN MoMo Request to Pay Response', ['http_code' => $httpCode, 'response' => $response]);

        // HTTP 202 = Accepted (pending), সফলতার চিহ্ন
        if ($httpCode === 202) {
            // Bind the public callback to an externalId/referenceId pair that
            // was generated locally for this authenticated request-to-pay.
            Cache::put('mtnmomo_pending_' . $referenceId, [
                'external_id' => $externalId,
                'amount' => $amount,
                'phone' => $phone,
                'created_at' => now()->toIso8601String(),
            ], now()->addMinutes(30));
            Cache::put('mtnmomo_external_' . $externalId, $referenceId, now()->addMinutes(30));

            return response()->json([
                'success'      => true,
                'reference_id' => $referenceId,
                'message'      => 'MTN MoMo payment request has been sent. Please enter your PIN on your phone.',
            ]);
        }

        $result = json_decode($response, true) ?? [];
        return response()->json([
            'success' => false,
            'message' => $result['message'] ?? 'MTN MoMo request failed.',
            'raw'     => $result,
        ], 400);
    }

    public function queryStatus(Request $request): JsonResponse
    {
        $request->validate(['reference_id' => 'required|string']);
        $cached = Cache::get('mtnmomo_' . $request->reference_id);

        if ($cached) {
            $status = strtolower($cached['status'] ?? 'failed');
            if ($status === 'successful') {
                return response()->json(['status' => 'success']);
            } elseif ($status === 'failed') {
                return response()->json([
                    'status'  => 'failed',
                    'message' => $cached['reason'] ?? 'Payment failed.',
                ]);
            } elseif ($status === 'pending') {
                return response()->json(['status' => 'pending']);
            }
        }

        return response()->json(['status' => 'pending']);
    }

    public function callback(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::info('MTN MoMo Callback Received', $payload);

        $externalId = (string) ($payload['externalId'] ?? '');
        $referenceId = $externalId !== '' ? Cache::get('mtnmomo_external_' . $externalId) : null;
        $headerReferenceId = $request->header('X-Reference-Id');

        if (!$referenceId || !Cache::has('mtnmomo_pending_' . $referenceId)
            || ($headerReferenceId && !hash_equals((string) $referenceId, (string) $headerReferenceId))) {
            Log::warning('Rejected MTN MoMo callback for an unknown payment request.', [
                'external_id' => $externalId,
                'reference_id' => $headerReferenceId,
            ]);
            return response()->json(['status' => 'unknown_reference'], 400);
        }

        $status = strtolower((string) ($payload['status'] ?? 'failed'));
        $reason = (string) ($payload['reason'] ?? '');
        Cache::put('mtnmomo_' . $referenceId, [
            'status' => $status,
            'reason' => $reason,
        ], now()->addMinutes(10));

        Log::info('MTN MoMo Callback Cached', [
            'reference_id' => $referenceId,
            'status' => $status,
        ]);

        return response()->json(['status' => 'received'], 200);
    }
}
