<?php

namespace App\SMSProviders;

use Illuminate\Support\Facades\Http;

class ZirconSms
{
    public function send($data)
    {
        $details = is_string($data['details'] ?? null)
            ? json_decode($data['details'], true)
            : (array) ($data['details'] ?? []);

        $response = Http::timeout(15)->get('https://sender.zirconhost.com/api/v2/send.php', [
            'user_id'   => $details['user_id'] ?? '',
            'api_key'   => $details['api_key'] ?? '',
            'sender_id' => $details['sender_id'] ?? '',
            'to'        => $data['recipent'] ?? ($data['numbers'][0] ?? ''),
            'message'   => $data['message'] ?? '',
        ]);

        return $response->json() ?? [
            'status' => $response->successful() ? 'success' : 'failed',
            'body'   => $response->body(),
        ];
    }
}
