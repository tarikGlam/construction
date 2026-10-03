<?php

namespace Modules\VCardNfc\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NfcCard extends Model
{
    protected $table = 'nfc_cards';

    protected $fillable = [
        'vcard_profile_id', 'token', 'label', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(VCardProfile::class, 'vcard_profile_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VCardEvent::class, 'nfc_card_id');
    }
}
