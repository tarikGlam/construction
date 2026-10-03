<?php

namespace App\Services;

use App\DTOs\NotificationEventData;
use App\Jobs\SendNotificationChannelJob;
use App\Models\Customer;
use App\Models\NotificationDelivery;
use App\Models\NotificationSetting;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\SendNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    protected NotificationRecipientResolver $recipientResolver;
    protected NotificationTemplateRenderer $templateRenderer;

    public function __construct(
        ?NotificationRecipientResolver $recipientResolver = null,
        ?NotificationTemplateRenderer $templateRenderer = null
    ) {
        $this->recipientResolver = $recipientResolver ?? app(NotificationRecipientResolver::class);
        $this->templateRenderer = $templateRenderer ?? app(NotificationTemplateRenderer::class);
    }

    /**
     * Main dispatch entry point for all notification events.
     */
    public function dispatch(NotificationEventData|string $event, ?array $data = null): void
    {
        $eventData = ($event instanceof NotificationEventData)
            ? $event
            : $this->normalizeLegacyArrayPayload($event, $data ?? []);

        if (!$eventData) {
            return;
        }

        $setting = NotificationSetting::where('event', $eventData->event)->first();
        if (!$setting) {
            return;
        }

        // Check if at least one channel is toggled ON
        if (!$setting->notify_in_app && !$setting->notify_whatsapp && !$setting->notify_sms && !$setting->notify_mail) {
            return;
        }

        $rawRecipients = $this->normalizeRecipients($setting->recipients);

        // Filter selected categories against event applicability matrix
        $applicableCategories = array_filter($rawRecipients, function ($cat) use ($eventData) {
            return $this->recipientResolver->isRecipientApplicable($eventData->event, $cat);
        });

        if (empty($applicableCategories)) {
            return;
        }

        $channels = [];
        if ($setting->notify_in_app)   $channels[] = 'in_app';
        if ($setting->notify_whatsapp) $channels[] = 'whatsapp';
        if ($setting->notify_sms)      $channels[] = 'sms';
        if ($setting->notify_mail)     $channels[] = 'mail';

        // Pre-render frozen template snapshots
        $renderedSubject = $this->templateRenderer->renderSubject($eventData->event, $eventData);
        $renderedBodies = [
            'in_app'   => $this->templateRenderer->renderBody($setting->whatsapp_message ?: $setting->sms_message, 'in_app', $eventData),
            'whatsapp' => $this->templateRenderer->renderBody($setting->whatsapp_message, 'whatsapp', $eventData),
            'sms'      => $this->templateRenderer->renderBody($setting->sms_message, 'sms', $eventData),
            'mail'     => $this->templateRenderer->renderBody($setting->mail_message, 'mail', $eventData),
        ];

        $payloadSnapshot = [
            'event'     => $eventData->event,
            'reference' => $eventData->reference,
            'amount'    => $eventData->amount,
            'date'      => $eventData->date,
            'extra'     => $eventData->extra,
        ];

        $tenantId = method_exists(app(), 'bound') && app()->bound('tenant') ? (string) optional(app('tenant'))->id : null;

        // Process each applicable category
        foreach ($applicableCategories as $category) {
            if ($category === 'admin') {
                $staffUsers = $this->recipientResolver->resolveStaffRecipients($eventData);
                foreach ($staffUsers as $staffUser) {
                    $this->dispatchForRecipient(
                        category: 'admin',
                        channels: $channels,
                        eventData: $eventData,
                        renderedSubject: $renderedSubject,
                        renderedBodies: $renderedBodies,
                        payloadSnapshot: $payloadSnapshot,
                        tenantId: $tenantId,
                        staffUser: $staffUser
                    );
                }
            } else {
                $this->dispatchForRecipient(
                    category: $category,
                    channels: $channels,
                    eventData: $eventData,
                    renderedSubject: $renderedSubject,
                    renderedBodies: $renderedBodies,
                    payloadSnapshot: $payloadSnapshot,
                    tenantId: $tenantId
                );
            }
        }
    }

    private function dispatchForRecipient(
        string $category,
        array $channels,
        NotificationEventData $eventData,
        string $renderedSubject,
        array $renderedBodies,
        array $payloadSnapshot,
        ?string $tenantId,
        ?User $staffUser = null
    ): void {
        foreach ($channels as $channel) {
            if (!$this->recipientResolver->isChannelApplicable($category, $channel)) {
                continue;
            }

            $destInfo = $this->recipientResolver->resolveDestination($category, $channel, $eventData, $staffUser);
            $destination = $destInfo['destination'];
            $targetUser = $destInfo['user'];
            $recipientHash = $destInfo['normalized_hash'];

            $idempotencyKey = NotificationDelivery::generateIdempotencyKey(
                tenantId: $tenantId,
                event: $eventData->event,
                subjectType: $eventData->subjectType,
                subjectId: $eventData->subjectId,
                eventVersion: $eventData->eventVersion,
                channel: $channel,
                recipientCategory: $category,
                recipientHash: $recipientHash
            );

            $hasDestination = !empty($destination) && trim((string)$destination) !== '';
            $initialStatus = $hasDestination ? 'pending' : 'skipped';
            $errorSummary = $hasDestination ? null : 'Missing contact destination for ' . $category;

            try {
                $delivery = NotificationDelivery::firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'event'                 => $eventData->event,
                        'tenant_id'             => $tenantId,
                        'subject_type'          => $eventData->subjectType,
                        'subject_id'            => $eventData->subjectId,
                        'event_version'         => $eventData->eventVersion,
                        'channel'               => $channel,
                        'recipient_category'    => $category,
                        'recipient_hash'        => $recipientHash,
                        'recipient_destination' => $destination,
                        'rendered_subject'      => $renderedSubject,
                        'rendered_body'         => $renderedBodies[$channel] ?? '',
                        'payload_snapshot'      => $payloadSnapshot,
                        'status'                => $initialStatus,
                        'error_summary'         => $errorSummary,
                        'queued_at'             => now(),
                    ]
                );

                if (!$delivery->wasRecentlyCreated || $delivery->status !== 'pending') {
                    continue;
                }

                if ($channel === 'in_app') {
                    if ($targetUser) {
                        $this->dispatchInAppSynchronously($delivery, $targetUser, $renderedBodies['in_app'], $eventData);
                    } else {
                        $delivery->update([
                            'status'        => 'skipped',
                            'error_summary' => 'No active user account found for in-app delivery',
                        ]);
                    }
                } else {
                    SendNotificationChannelJob::dispatch($delivery->id)->afterCommit();
                }
            } catch (\Throwable $e) {
                Log::error('Failed to create notification delivery record.', [
                    'event'   => $eventData->event,
                    'channel' => $channel,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    private function dispatchInAppSynchronously(
        NotificationDelivery $delivery,
        User $user,
        string $renderedMessage,
        NotificationEventData $eventData
    ): void {
        $inAppPayload = [
            'sender_id'     => auth()->id() ?? 1,
            'receiver_id'   => $user->id,
            'reminder_date' => date('Y-m-d'),
            'document_name' => null,
            'message'       => $renderedMessage,
            'event'         => $eventData->event,
            'reference'     => $eventData->reference,
        ];

        DB::transaction(function () use ($delivery, $user, $inAppPayload) {
            Notification::send($user, new SendNotification($inAppPayload));
            $delivery->update([
                'status'  => 'sent',
                'sent_at' => now(),
            ]);
        });
    }

    private function normalizeRecipients($value): array
    {
        if (is_array($value)) {
            return array_values(array_unique(array_filter(array_map('strval', $value))));
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_unique(array_filter(array_map('strval', $decoded))));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($recipient) => trim((string) $recipient),
            explode(',', $value)
        ))));
    }

    private function normalizeLegacyArrayPayload(string $event, array $data): ?NotificationEventData
    {
        $customer = null;
        if (!empty($data['customer'])) {
            $customer = $data['customer'] instanceof Customer ? $data['customer'] : Customer::find($data['customer']);
        }

        $supplier = null;
        if (!empty($data['supplier'])) {
            $supplier = $data['supplier'] instanceof Supplier ? $data['supplier'] : Supplier::find($data['supplier']);
        }

        $subjectId = (int) ($data['id'] ?? $data['subject_id'] ?? rand(1000, 999999));
        $subjectType = match ($event) {
            'sale_created', 'payment_received' => \App\Models\Sale::class,
            'purchase_created' => \App\Models\Purchase::class,
            'quotation_created' => \App\Models\Quotation::class,
            'stock_transfer' => \App\Models\Transfer::class,
            default => \App\Models\Product::class,
        };

        return new NotificationEventData(
            event: $event,
            subjectType: $subjectType,
            subjectId: $subjectId,
            eventVersion: 'legacy_v1',
            reference: $data['reference'] ?? $data['reference_no'] ?? null,
            amount: isset($data['amount']) ? (float) $data['amount'] : (isset($data['grand_total']) ? (float) $data['grand_total'] : null),
            products: isset($data['products']) && is_array($data['products']) ? $data['products'] : [],
            totalQty: isset($data['qty']) ? (float) $data['qty'] : null,
            warehouseId: isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            customer: $customer,
            supplier: $supplier,
            date: $data['date'] ?? date('Y-m-d'),
            extra: $data
        );
    }
}
