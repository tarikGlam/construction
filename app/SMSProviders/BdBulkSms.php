<?php

namespace App\SMSProviders;

use Illuminate\Support\Facades\Http;

class BdBulkSms
{
    public function send($data)
    {
        $details = is_string($data['details'] ?? null)
            ? json_decode($data['details'], true)
            : (array) ($data['details'] ?? []);

        $token = $details['token'] ?? '';
        $to = $data['recipent'] ?? ($data['numbers'][0] ?? '');
        $message = $data['message'] ?? '';
        
        $url = 'http://api.greenweb.com.bd/api.php?json';
        
        $response = Http::asForm()->timeout(15)->post($url, [
            'token'   => $token,
            'to'      => $to,
            'message' => $message,
        ]);

        return $response->json() ?? [
            'status' => $response->successful() ? 'SENT' : 'FAILED',
            'body'   => $response->body(),
        ];
    }
}