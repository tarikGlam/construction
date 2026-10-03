<?php

namespace Modules\VCardNfc\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VCardSocialLink extends Model
{
    protected $table = 'vcard_social_links';

    protected $fillable = [
        'vcard_profile_id', 'platform', 'url', 'sort_order',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(VCardProfile::class, 'vcard_profile_id');
    }
}
