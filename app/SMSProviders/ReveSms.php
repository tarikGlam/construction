<?php

namespace App\SMSProviders;

use Illuminate\Support\Facades\Http;

class ReveSms
{
    public function send($data)
    {
        $details = is_string($data['details'] ?? null)
            ? json_decode($data['details'], true)
            : (array) ($data['details'] ?? []);

        $apikey = $details['apikey'] ?? '';
        $secretkey = $details['secretkey'] ?? '';
        $callerID = $details['callerID'] ?? '';
        $recipient = $data['recipent'] ?? ($data['numbers'][0] ?? '');
        $message = $data['message'] ?? '';

        $url = 'http://smpp.revesms.com:7788/sendtext';

        $params = [
            'apikey'         => $apikey,
            'secretkey'      => $secretkey,
            'callerID'       => $callerID,
            'toUser'         => $recipient,
            'messageContent' => $message,
        ];

        $response = Http::timeout(15)->get($url, $params);

        return $response->json() ?? [
            'status' => $response->successful() ? '0' : '-1',
            'body'   => $response->body(),
        ];
    }
}