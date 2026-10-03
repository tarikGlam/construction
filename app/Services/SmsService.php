<?php

namespace App\Services;

use App\SMSProviders\BdBulkSms;
use App\SMSProviders\Clickatell;
use App\SMSProviders\CustomHttpSms;
use App\SMSProviders\ReveSms;
use App\SMSProviders\TonkraSms;
use App\SMSProviders\Twilio;
use App\SMSProviders\ZirconSms;
use App\Models\ExternalService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private $_tonkraSms;
    private $_reveSms;
    private $_bdbulkSms;
    private $_zirconSms;
    private $_customHttpSms;
    private $_twilio;
    private $_clickatell;

    public function __construct(
        TonkraSms $tonkraSms,
        ReveSms $reveSms,
        BdBulkSms $bdBulkSms,
        ZirconSms $zirconSms,
        CustomHttpSms $customHttpSms,
        ?Twilio $twilio = null,
        ?Clickatell $clickatell = null
    ) {
        $this->_tonkraSms = $tonkraSms;
        $this->_reveSms = $reveSms;
        $this->_bdbulkSms = $bdBulkSms;
        $this->_zirconSms = $zirconSms;
        $this->_customHttpSms = $customHttpSms;
        $this->_twilio = $twilio ?? app(Twilio::class);
        $this->_clickatell = $clickatell ?? app(Clickatell::class);
    }

    public function initialize($data)
    {
        $smsServiceProviderName = $data['sms_provider_name'] ?? '';
        
        try {
            switch ($smsServiceProviderName) {
                case 'tonkra':
                    return $this->_tonkraSms->send($data);
                case 'revesms':
                    return $this->_reveSms->send($data);
                case 'bdbulksms':
                    return $this->_bdbulkSms->send($data);
                case 'zircon':
                    return $this->_zirconSms->send($data);
                case 'custom_http':
                    return $this->_customHttpSms->send($data);
                case 'twilio':
                    return $this->_twilio->send($data);
                case 'clickatell':
                    return $this->_clickatell->send($data);
                default:
                    return null;
            }
        } catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('SMS sending failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Narrow direct-message adapter for notification jobs.  It deliberately
     * bypasses SmsModel, whose contract is sale-template specific.
     *
     * @return array{success: bool, provider_message_id: ?string, retryable: bool, error: ?string}
     */
    public function sendDirect(string $destination, string $message): array
    {
        $provider = ExternalService::where('active', true)->where('type', 'sms')->first();
        if (!$provider) {
            return [
                'success'             => false,
                'provider_message_id' => null,
                'retryable'           => false,
                'error'               => 'No active SMS provider configured.',
            ];
        }

        return $this->sendViaProvider($provider->name, $provider->details, $destination, $message);
    }

    /**
     * Send SMS via explicit provider name and details with normalized result contract.
     *
     * @return array{success: bool, provider_message_id: ?string, retryable: bool, error: ?string}
     */
    public function sendViaProvider(string $providerName, mixed $providerDetails, string $destination, string $message): array
    {
        try {
            $smsData = [
                'sms_provider_name' => $providerName,
                'details'           => $providerDetails,
                'recipent'          => $destination,
                'numbers'           => [$destination],
                'message'           => $message,
            ];

            $result = $this->initialize($smsData);

            if ($result === false || $result === null) {
                return [
                    'success'             => false,
                    'provider_message_id' => null,
                    'retryable'           => true,
                    'error'               => 'SMS provider did not confirm delivery.',
                ];
            }

            return $this->normalizeProviderResponse($providerName, $result);
        } catch (ConnectionException $e) {
            return [
                'success'             => false,
                'provider_message_id' => null,
                'retryable'           => true,
                'error'               => 'Connection timeout or network failure: ' . $e->getMessage(),
            ];
        } catch (RequestException $e) {
            $status = $e->response ? $e->response->status() : 500;
            $retryable = in_array($status, [429, 500, 502, 503, 504], true);
            return [
                'success'             => false,
                'provider_message_id' => null,
                'retryable'           => $retryable,
                'error'               => "HTTP {$status}: " . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success'             => false,
                'provider_message_id' => null,
                'retryable'           => true,
                'error'               => $e->getMessage(),
            ];
        }
    }

    /**
     * Normalize provider-specific responses into the canonical contract:
     * - Confirmed acceptance/success boolean.
     * - Optional provider message ID.
     * - Retryable/non-retryable failure classification.
     * - Sanitized error code/message.
     */
    public function normalizeProviderResponse(string $providerName, mixed $result): array
    {
        return match ($providerName) {
            'tonkra'      => $this->normalizeTonkra($result),
            'revesms'     => $this->normalizeReveSms($result),
            'bdbulksms'   => $this->normalizeBdBulkSms($result),
            'zircon'      => $this->normalizeZircon($result),
            'twilio'      => $this->normalizeTwilio($result),
            'clickatell'  => $this->normalizeClickatell($result),
            'custom_http' => $this->normalizeCustomHttp($result),
            default       => [
                'success'             => false,
                'provider_message_id' => null,
                'retryable'           => false,
                'error'               => "Unsupported SMS provider: {$providerName}",
            ],
        };
    }

    private function normalizeTonkra(mixed $result): array
    {
        if (!is_array($result)) {
            return ['success' => false, 'provider_message_id' => null, 'retryable' => true, 'error' => 'Malformed response from Tonkra'];
        }

        $status = strtolower((string) ($result['status'] ?? ''));
        if ($status === 'success') {
            $msgId = data_get($result, 'data.message_id') ?? data_get($result, 'message_id');
            return [
                'success'             => true,
                'provider_message_id' => $msgId ? (string) $msgId : null,
                'retryable'           => false,
                'error'               => null,
            ];
        }

        $error = (string) ($result['message'] ?? 'Tonkra delivery rejected');
        $lower = strtolower($error);
        $retryable = !str_contains($lower, 'invalid') && !str_contains($lower, 'unauthorized') && !str_contains($lower, 'token');

        return [
            'success'             => false,
            'provider_message_id' => null,
            'retryable'           => $retryable,
            'error'               => $error,
        ];
    }

    private function normalizeReveSms(mixed $result): array
    {
        if (!is_array($result)) {
            return ['success' => false, 'provider_message_id' => null, 'retryable' => true, 'error' => 'Malformed response from ReveSMS'];
        }

        $status = (string) ($result['Status'] ?? $result['status'] ?? '');
        $text = strtolower((string) ($result['Text'] ?? $result['text'] ?? ''));

        if ($status === '0' || $text === 'acceptd') {
            $msgId = $result['Message_ID'] ?? $result['message_id'] ?? null;
            return [
                'success'             => true,
                'provider_message_id' => $msgId ? (string) $msgId : null,
                'retryable'           => false,
                'error'               => null,
            ];
        }

        $error = (string) ($result['Text'] ?? $result['error'] ?? $result['message'] ?? 'ReveSMS rejected delivery');
        $lower = strtolower($error);
        $retryable = !str_contains($lower, 'invalid') && !str_contains($lower, 'key') && !str_contains($lower, 'apikey');

        return [
            'success'             => false,
            'provider_message_id' => null,
            'retryable'           => $retryable,
            'error'               => $error,
        ];
    }

    private function normalizeBdBulkSms(mixed $result): array
    {
        $item = is_array($result) && isset($result[0]) ? $result[0] : $result;

        if (is_array($item)) {
            $status = strtoupper((string) ($item['status'] ?? ''));
            $statusCode = (int) ($item['status_code'] ?? 0);

            if ($status === 'SENT' || $statusCode === 200) {
                $msgId = $item['message_id'] ?? null;
                return [
                    'success'             => true,
                    'provider_message_id' => $msgId ? (string) $msgId : null,
                    'retryable'           => false,
                    'error'               => null,
                ];
            }

            $error = (string) ($item['error'] ?? $item['message'] ?? 'BdBulkSms rejected delivery');
            $retryable = $statusCode !== 401 && !str_contains(strtolower($error), 'token');

            return [
                'success'             => false,
                'provider_message_id' => null,
                'retryable'           => $retryable,
                'error'               => $error,
            ];
        }

        if (is_string($result) && str_contains($result, 'SMS Sent')) {
            return [
                'success'             => true,
                'provider_message_id' => null,
                'retryable'           => false,
                'error'               => null,
            ];
        }

        return [
            'success'             => false,
            'provider_message_id' => null,
            'retryable'           => true,
            'error'               => is_string($result) ? substr($result, 0, 200) : 'BdBulkSms did not confirm delivery',
        ];
    }

    private function normalizeZircon(mixed $result): array
    {
        if (!is_array($result)) {
            return ['success' => false, 'provider_message_id' => null, 'retryable' => true, 'error' => 'Malformed response from Zircon'];
        }

        $status = strtolower((string) ($result['status'] ?? ''));
        $success = $result['success'] ?? false;
        $code = (int) ($result['response_code'] ?? 0);

        if ($status === 'success' || $success === true || $code === 200) {
            $msgId = $result['message_id'] ?? $result['id'] ?? null;
            return [
                'success'             => true,
                'provider_message_id' => $msgId ? (string) $msgId : null,
                'retryable'           => false,
                'error'               => null,
            ];
        }

        $error = (string) ($result['message'] ?? $result['error'] ?? 'Zircon rejected delivery');
        $lower = strtolower($error);
        $retryable = !str_contains($lower, 'invalid') && !str_contains($lower, 'unauthorized') && !str_contains($lower, 'api_key');

        return [
            'success'             => false,
            'provider_message_id' => null,
            'retryable'           => $retryable,
            'error'               => $error,
        ];
    }

    private function normalizeTwilio(mixed $result): array
    {
        if (!is_array($result)) {
            return ['success' => false, 'provider_message_id' => null, 'retryable' => true, 'error' => 'Malformed response from Twilio'];
        }

        if (!empty($result['sid']) && empty($result['code'])) {
            return [
                'success'             => true,
                'provider_message_id' => (string) $result['sid'],
                'retryable'           => false,
                'error'               => null,
            ];
        }

        $error = (string) ($result['message'] ?? 'Twilio rejected delivery');
        $code = (int) ($result['code'] ?? 0);
        $retryable = !in_array($code, [21211, 21614, 20003, 21608], true)
            && !str_contains(strtolower($error), 'authenticate');

        return [
            'success'             => false,
            'provider_message_id' => null,
            'retryable'           => $retryable,
            'error'               => $error,
        ];
    }

    private function normalizeClickatell(mixed $result): array
    {
        if (!is_array($result)) {
            return ['success' => false, 'provider_message_id' => null, 'retryable' => true, 'error' => 'Malformed response from Clickatell'];
        }

        $firstMessage = $result['messages'][0] ?? null;
        if (is_array($firstMessage)) {
            if (!empty($firstMessage['accepted']) || !empty($firstMessage['apiMessageId'])) {
                $msgId = $firstMessage['apiMessageId'] ?? null;
                return [
                    'success'             => true,
                    'provider_message_id' => $msgId ? (string) $msgId : null,
                    'retryable'           => false,
                    'error'               => null,
                ];
            }

            if (!empty($firstMessage['error'])) {
                $error = (string) $firstMessage['error'];
                $retryable = !str_contains(strtolower($error), 'invalid') && !str_contains(strtolower($error), 'auth');
                return [
                    'success'             => false,
                    'provider_message_id' => null,
                    'retryable'           => $retryable,
                    'error'               => $error,
                ];
            }
        }

        $error = (string) ($result['error'] ?? 'Clickatell rejected delivery');
        $retryable = !str_contains(strtolower($error), 'invalid') && !str_contains(strtolower($error), 'auth');

        return [
            'success'             => false,
            'provider_message_id' => null,
            'retryable'           => $retryable,
            'error'               => $error,
        ];
    }

    private function normalizeCustomHttp(mixed $result): array
    {
        $responseItem = is_array($result) && isset($result[0]) ? $result[0] : $result;

        if (!is_array($responseItem) && is_string($responseItem)) {
            if (str_contains(strtolower($responseItem), 'error') || str_contains(strtolower($responseItem), 'fail')) {
                return [
                    'success'             => false,
                    'provider_message_id' => null,
                    'retryable'           => true,
                    'error'               => substr($responseItem, 0, 200),
                ];
            }
            return [
                'success'             => true,
                'provider_message_id' => null,
                'retryable'           => false,
                'error'               => null,
            ];
        }

        if (is_array($responseItem)) {
            $status = strtolower((string) ($responseItem['status'] ?? ''));
            if ($status === 'error' || $status === 'failed' || !empty($responseItem['error'])) {
                $error = (string) ($responseItem['error'] ?? $responseItem['message'] ?? 'Custom HTTP rejected message');
                return [
                    'success'             => false,
                    'provider_message_id' => null,
                    'retryable'           => true,
                    'error'               => $error,
                ];
            }

            $msgId = data_get($responseItem, 'message_id')
                ?? data_get($responseItem, 'id')
                ?? data_get($responseItem, 'data.id');

            return [
                'success'             => true,
                'provider_message_id' => $msgId ? (string) $msgId : null,
                'retryable'           => false,
                'error'               => null,
            ];
        }

        return [
            'success'             => false,
            'provider_message_id' => null,
            'retryable'           => true,
            'error'               => 'Custom HTTP response was empty or unconfirmed',
        ];
    }
}
