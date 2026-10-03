<?php

namespace Modules\VCardNfc\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VCardEvent extends Model
{
    public $timestamps = false;

    protected $table = 'vcard_events';

    protected $fillable = [
        'vcard_profile_id', 'nfc_card_id', 'event_type', 'source', 'ip_hash', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(VCardProfile::class, 'vcard_profile_id');
    }

    public function nfcCard(): BelongsTo
    {
        return $this->belongsTo(NfcCard::class, 'nfc_card_id');
    }
}
