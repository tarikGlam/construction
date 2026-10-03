<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class TransportRecord extends Model
{
    protected $table='construction_transport_records';
    protected $guarded=[];
    protected $casts=['transport_date'=>'date','transport_cost'=>'decimal:4'];
    public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);}
    public function site(){return $this->belongsTo(ConstructionSite::class,'site_id');}
    public function transfer(){return $this->belongsTo(\App\Models\Transfer::class);}
    public function purchase(){return $this->belongsTo(\App\Models\Purchase::class);}
}
