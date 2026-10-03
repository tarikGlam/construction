<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Models\WhatsappSetting;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendNotificationChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $deliveryId
    ) {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::find($this->deliveryId);
        if (!$delivery || in_array($delivery->status, ['sent', 'skipped'], true)) {
            return;
        }

        // Atomically claim the delivery record
        $claimed = NotificationDelivery::where('id', $this->deliveryId)
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', $this->tries)
            ->update([
                'status'     => 'processing',
                'attempts'   => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if (!$claimed) {
            return;
        }

        $delivery->refresh();

        $destination = $delivery->recipient_destination;
        if (!$destination || trim($destination) === '') {
            $delivery->update([
                'status'        => 'skipped',
                'error_summary' => 'Missing contact destination for ' . $delivery->recipient_category,
            ]);
            return;
        }

        try {
            $providerMessageId = match ($delivery->channel) {
                'mail'     => $this->sendMail($destination, $delivery->rendered_subject, $delivery->rendered_body),
                'whatsapp' => $this->sendWhatsApp($destination, $delivery->rendered_body),
                'sms'      => $this->sendSms($destination, $delivery->rendered_body),
                default    => throw new \InvalidArgumentException("Unsupported queued channel: {$delivery->channel}"),
            };

            $delivery->update([
                'status'              => 'sent',
                'sent_at'             => now(),
                'provider_message_id' => $providerMessageId,
                'error_summary'       => null,
            ]);
        } catch (\App\Exceptions\NonRetryableNotificationException $e) {
            $sanitizedError = $this->sanitizeError($e->getMessage());
            $maskedDest = $this->maskDestination($destination);

            Log::warning('Non-retryable notification delivery attempt failed.', [
                'delivery_id' => $delivery->id,
                'channel'     => $delivery->channel,
                'event'       => $delivery->event,
                'recipient'   => $maskedDest,
                'attempt'     => $delivery->attempts,
                'error'       => $sanitizedError,
            ]);

            $delivery->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'error_summary' => $sanitizedError,
            ]);
            // Terminal failure - do not re-throw
        } catch (\Throwable $e) {
            $sanitizedError = $this->sanitizeError($e->getMessage());
            $maskedDest = $this->maskDestination($destination);

            Log::error('Notification delivery attempt failed.', [
                'delivery_id' => $delivery->id,
                'channel'     => $delivery->channel,
                'event'       => $delivery->event,
                'recipient'   => $maskedDest,
                'attempt'     => $delivery->attempts,
                'error'       => $sanitizedError,
            ]);

            if ($delivery->attempts >= $this->tries) {
                $delivery->update([
                    'status'        => 'failed',
                    'failed_at'     => now(),
                    'error_summary' => $sanitizedError,
                ]);
            } else {
                $delivery->update([
                    'status'        => 'failed',
                    'error_summary' => $sanitizedError,
                ]);
                throw $e; // Re-throw to trigger queue backoff retry
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $delivery = NotificationDelivery::find($this->deliveryId);
        if (!$delivery || in_array($delivery->status, ['sent', 'skipped'], true)) {
            return;
        }

        $delivery->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error_summary' => $this->sanitizeError($exception->getMessage()),
        ]);
    }

    private function sendMail(string $email, ?string $subject, ?string $body): ?string
    {
        $subject ??= 'Notification';
        $body ??= '';

        Mail::html($body, function ($message) use ($email, $subject) {
            $message->to($email)->subject($subject);
        });

        return 'mail_' . uniqid();
    }

    private function sendWhatsApp(string $phone, ?string $message): ?string
    {
        $whatsapp = WhatsappSetting::latest()->first();
        if (!$whatsapp) {
            throw new \App\Exceptions\NonRetryableNotificationException('WhatsApp settings are not configured.');
        }

        return $whatsapp->sendTextMessageSynchronously($phone, (string) $message)['message_id'];
    }

    private function sendSms(string $phone, ?string $message): ?string
    {
        $result = app(SmsService::class)->sendDirect($phone, (string) $message);
        if (!$result['success']) {
            if (isset($result['retryable']) && !$result['retryable']) {
                throw new \App\Exceptions\NonRetryableNotificationException($result['error'] ?? 'SMS provider rejected non-retryable.');
            }
            throw new \RuntimeException($result['error'] ?? 'SMS provider did not confirm delivery.');
        }

        return $result['provider_message_id'];
    }

    private function maskDestination(string $dest): string
    {
        if (str_contains($dest, '@')) {
            $parts = explode('@', $dest);
            $name = $parts[0];
            $domain = $parts[1] ?? '';
            $maskedName = strlen($name) > 2 ? substr($name, 0, 1) . '***' . substr($name, -1) : '***';
            return $maskedName . '@' . $domain;
        }

        return strlen($dest) > 4
            ? substr($dest, 0, 2) . '***' . substr($dest, -2)
            : '***';
    }

    private function sanitizeError(string $msg): string
    {
        // Redact any bearer tokens, authorization headers, passwords, or keys
        $clean = preg_replace('/(bearer\s+[a-zA-Z0-9_\-\.]+)/i', 'bearer [REDACTED]', $msg);
        $clean = preg_replace('/(password\s*=\s*[^,\s]+)/i', 'password=[REDACTED]', $clean);
        $clean = preg_replace('/(token\s*=\s*[^,\s]+)/i', 'token=[REDACTED]', $clean);
        $clean = preg_replace('/(secret\s*=\s*[^,\s]+)/i', 'secret=[REDACTED]', $clean);

        return substr($clean, 0, 500);
    }
}
