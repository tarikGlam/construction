<?php 

namespace App\SMSProviders;

use Illuminate\Support\Facades\Http;

class Clickatell
{
    public function send($data)
    {
        $details = is_string($data['details'] ?? null)
            ? json_decode($data['details'], true)
            : (array) ($data['details'] ?? []);

        $apiKey = $details['api_key'] ?? env('CLICKATELL_API_KEY', '');
        $numbers = !empty($data['numbers']) ? (array) $data['numbers'] : [$data['recipent'] ?? ''];
        $message = $data['message'] ?? '';

        $endpoint = 'https://platform.clickatell.com/messages/http/send';

        $response = Http::withHeaders([
            'Authorization' => $apiKey,
            'Accept'        => 'application/json',
        ])->timeout(15)->post($endpoint, [
            'to'      => array_values(array_filter($numbers)),
            'content' => $message,
        ]);

        return $response->json() ?? [
            'status' => $response->successful() ? 'success' : 'failed',
            'body'   => $response->body(),
        ];
    }
}
