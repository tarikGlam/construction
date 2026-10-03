<?php 

namespace App\SMSProviders;

use Illuminate\Support\Facades\Http;

class CustomHttpSms
{
    public function send($data)
    {
        $details = is_string($data['details'] ?? null)
            ? json_decode($data['details'], true)
            : (array) ($data['details'] ?? []);
        
        $method = strtoupper($details['method'] ?? 'POST');
        $apiUrl = $details['api_url'] ?? '';
        
        $headersStr = $details['headers'] ?? '{}';
        $headers = is_array($headersStr) ? $headersStr : (json_decode($headersStr, true) ?? []);
        
        $bodyTemplateStr = $details['body_template'] ?? '';
        
        $responses = [];
        $numbers = !empty($data['numbers']) ? (array) $data['numbers'] : [$data['recipent'] ?? ''];
        
        foreach ($numbers as $number) {
            $number = trim((string) $number);
            if (empty($number)) {
                continue;
            }
            
            $message = (string) ($data['message'] ?? '');
            $replacedUrl = $this->replacePlaceholders($apiUrl, $number, $message);
            $replacedBodyStr = $this->replacePlaceholders($bodyTemplateStr, $number, $message);
            
            $replacedHeaders = [];
            foreach ($headers as $key => $val) {
                $replacedHeaders[$key] = $this->replacePlaceholders($val, $number, $message);
            }
            
            $request = Http::withHeaders($replacedHeaders)->timeout(15);
            
            if ($method === 'GET') {
                $response = $request->get($replacedUrl);
            } else {
                $bodyData = json_decode($replacedBodyStr, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($bodyData)) {
                    $response = $request->post($replacedUrl, $bodyData);
                } else {
                    $response = $request->withBody($replacedBodyStr, 'text/plain')->post($replacedUrl);
                }
            }
            
            $responses[] = $response->json() ?? ['body' => $response->body(), 'status' => $response->status()];
        }
        
        return $responses;
    }
    
    private function replacePlaceholders($string, $phone, $message)
    {
        if (empty($string) || !is_string($string)) {
            return '';
        }
        
        return str_replace(['{phone}', '{message}'], [$phone, $message], $string);
    }
}
