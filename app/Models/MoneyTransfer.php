<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoneyTransfer extends Model
{
    use \App\Models\Concerns\ProtectsAccountingCutover;
    protected $fillable = [
        'reference_no',
        'from_account_id',
        'to_account_id',
        'amount',
        'currency_id',
        'exchange_rate',
        'note',
        'created_at',
        'project_id',
        'site_id',
        'external_reference',
    ];

    public function fromAccount()
    {
    	return $this->belongsTo('App\Models\Account');
    }

    public function toAccount()
    {
    	return $this->belongsTo('App\Models\Account');
    }

    public function currency()
    {
        return $this->belongsTo('App\Models\Currency');
    }

    public function project() { return $this->belongsTo(\Modules\Project\Entities\Project::class); }
    public function site() { return $this->belongsTo(\Modules\Construction\Entities\ConstructionSite::class, 'site_id'); }
}
