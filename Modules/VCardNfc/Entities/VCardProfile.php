<?php

namespace Modules\VCardNfc\Entities;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VCardProfile extends Model
{
    protected $table = 'vcard_profiles';

    protected $fillable = [
        'user_id', 'linked_user_id', 'employee_id', 'slug', 'name', 'profile_photo',
        'designation', 'company', 'phone', 'whatsapp', 'email', 'website', 'address',
        'bio', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** User who originally created the profile (legacy user_id column). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** SalePro login linked to the person represented by this card. */
    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    /** HR employee linked to the person represented by this card. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function socialLinks(): HasMany
    {
        return $this->hasMany(VCardSocialLink::class, 'vcard_profile_id');
    }

    public function nfcCards(): HasMany
    {
        return $this->hasMany(NfcCard::class, 'vcard_profile_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VCardEvent::class, 'vcard_profile_id');
    }
}
