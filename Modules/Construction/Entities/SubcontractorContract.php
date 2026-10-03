<?php

namespace Modules\Construction\Entities;

use Illuminate\Database\Eloquent\Model;

class SubcontractorContract extends Model
{
    protected $guarded = [];
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'contract_value' => 'decimal:4',
        'paid_amount' => 'decimal:4',
    ];

    public function subcontractor() { return $this->belongsTo(Subcontractor::class); }
    public function project() { return $this->belongsTo(\Modules\Project\Entities\Project::class); }
    public function payments() { return $this->hasMany(SubcontractorPayment::class); }
    public function getOutstandingAmountAttribute() { return max(0, (float) $this->contract_value - (float) $this->paid_amount); }
}
