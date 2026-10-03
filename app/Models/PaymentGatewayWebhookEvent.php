<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGatewayWebhookEvent extends Model
{
    protected $table = 'payment_gateway_webhook_events';

    protected $fillable = [
        'gateway',
        'event_id',
        'event_type',
        'payment_id',
        'order_id',
        'payload',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public static function hasBeenProcessed(string $gateway, string $eventId): bool
    {
        return static::where('gateway', $gateway)
            ->where('event_id', $eventId)
            ->whereNotNull('processed_at')
            ->exists();
    }
}
