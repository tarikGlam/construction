<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class FixedAsset extends Model {
    protected $table='construction_fixed_assets';
    protected $guarded=[];
    protected $casts=['acquisition_date'=>'date','acquisition_value'=>'decimal:4','salvage_value'=>'decimal:4'];
    public function equipment(){return $this->hasMany(Equipment::class,'fixed_asset_id');}
    public function getMonthlyDepreciationAttribute(): float {
        if ($this->depreciation_method !== 'straight_line' || !$this->useful_life_months) return 0;
        return max(0, ((float)$this->acquisition_value-(float)$this->salvage_value)/(int)$this->useful_life_months);
    }
}
