<?php

namespace Modules\Construction\Entities;

use Illuminate\Database\Eloquent\Model;

class ProjectCost extends Model
{
    use \App\Traits\WarehouseScoped;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:4'];

    public function project() { return $this->belongsTo(\Modules\Project\Entities\Project::class); }
    public function category() { return $this->belongsTo(CostCategory::class, 'cost_category_id'); }
    public function supplier() { return $this->belongsTo(\App\Models\Supplier::class); }
    public function expense() { return $this->belongsTo(\App\Models\Expense::class); }
    public function warehouse() { return $this->belongsTo(\App\Models\Warehouse::class); }
    public function account() { return $this->belongsTo(\App\Models\Account::class); }
}
