<?php

namespace Modules\Construction\Entities;

use Illuminate\Database\Eloquent\Model;

class SubcontractorPayment extends Model
{
    use \App\Traits\WarehouseScoped;

    protected $guarded = [];
    protected $casts = ['payment_date' => 'date', 'amount' => 'decimal:4'];

    public function contract() { return $this->belongsTo(SubcontractorContract::class, 'subcontractor_contract_id'); }
    public function project() { return $this->belongsTo(\Modules\Project\Entities\Project::class); }
    public function subcontractor() { return $this->belongsTo(Subcontractor::class); }
    public function warehouse() { return $this->belongsTo(\App\Models\Warehouse::class); }
    public function account() { return $this->belongsTo(\App\Models\Account::class); }
    public function expense() { return $this->belongsTo(\App\Models\Expense::class); }
}
