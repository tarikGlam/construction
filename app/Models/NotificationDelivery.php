<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $table = 'notification_deliveries';

    protected $fillable = [
        'event',
        'tenant_id',
        'subject_type',
        'subject_id',
        'event_version',
        'channel',
        'recipient_category',
        'recipient_hash',
        'recipient_destination',
        'rendered_subject',
        'rendered_body',
        'payload_snapshot',
        'status',
        'attempts',
        'idempotency_key',
        'provider_message_id',
        'error_summary',
        'queued_at',
        'sent_at',
        'failed_at',
    ];

    protected $casts = [
        'recipient_destination' => 'encrypted',
        'rendered_body'         => 'encrypted',
        'payload_snapshot'      => 'array',
        'attempts'              => 'integer',
        'queued_at'             => 'datetime',
        'sent_at'               => 'datetime',
        'failed_at'             => 'datetime',
    ];

    public static function generateIdempotencyKey(
        ?string $tenantId,
        string $event,
        string $subjectType,
        int|string $subjectId,
        string $eventVersion,
        string $channel,
        string $recipientCategory,
        string $recipientHash
    ): string {
        return hash('sha256', implode(':', [
            $tenantId ?: 'default',
            $event,
            $subjectType,
            (string) $subjectId,
            $eventVersion,
            $channel,
            $recipientCategory,
            $recipientHash,
        ]));
    }
}
