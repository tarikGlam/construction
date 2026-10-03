<?php 

namespace App\SMSProviders;

use Illuminate\Support\Facades\Http;

class Twilio
{
    public function send($data)
    {
        $details = is_string($data['details'] ?? null)
            ? json_decode($data['details'], true)
            : (array) ($data['details'] ?? []);

        $accountSid = $details['account_sid'] ?? '';
        $authToken = $details['auth_token'] ?? '';
        $fromNumber = $details['twilio_number'] ?? '';
        $recipient = $data['recipent'] ?? ($data['numbers'][0] ?? '');
        $message = $data['message'] ?? '';

        $endpoint = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

        $response = Http::withBasicAuth($accountSid, $authToken)
            ->asForm()
            ->timeout(15)
            ->post($endpoint, [
                'To'   => $recipient,
                'From' => $fromNumber,
                'Body' => $message,
            ]);

        return $response->json() ?? [
            'status' => $response->successful() ? 'queued' : 'failed',
            'body'   => $response->body(),
        ];
    }

    public function initialize($data)
    {
        return $this->send($data);
    }
}
