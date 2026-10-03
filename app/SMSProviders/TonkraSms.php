<?php 

namespace App\SMSProviders;

use App\Contracts\Sms\SendSmsInterface;
use App\Contracts\Sms\CheckBalanceInterface;
use App\Models\ExternalService;
use Illuminate\Support\Facades\Http;

class TonkraSms implements SendSmsInterface, CheckBalanceInterface
{
    public function send($data)
    {
        $details = is_string($data['details'] ?? null)
            ? json_decode($data['details'], true)
            : (array) ($data['details'] ?? []);

        $apiToken = $details['api_token'] ?? '';
        $senderId = $details['sender_id'] ?? '';
     
        $params = [
            'recipient' => $data['recipent'] ?? ($data['numbers'][0] ?? ''),
            'sender_id' => $senderId,
            'type'      => 'plain',
            'message'   => $data['message'] ?? '',
        ];

        $url = 'https://sms.tonkra.com/api/v3/sms/send';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiToken,
            'Accept'        => 'application/json',
        ])->timeout(15)->post($url, $params);

        return $response->json() ?? [
            'status' => $response->successful() ? 'success' : 'error',
            'body'   => $response->body(),
        ];
    }

    public function balance()
    {
        $tonkra = ExternalService::where('name', 'tonkra')->first();

        if (empty($tonkra)) {
            return 0;
        }    
    
        $details = is_string($tonkra->details)
            ? json_decode($tonkra->details, true)
            : (array) $tonkra->details;

        $apiToken = $details['api_token'] ?? '';
       
        $url = 'https://sms.tonkra.com/api/v3/balance';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])->timeout(15)->get($url);

            $responseData = $response->json();
            $remaining = $responseData['data']['remaining_balance'] ?? 0;
            return (int) preg_replace('/[^0-9]/', '', (string) $remaining);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
