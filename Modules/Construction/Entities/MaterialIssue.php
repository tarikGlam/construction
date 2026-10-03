<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class MaterialIssue extends Model { use \App\Traits\WarehouseScoped; protected $guarded=[]; protected $casts=['issue_date'=>'date']; public function items(){return $this->hasMany(MaterialIssueItem::class);} public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} public function warehouse(){return $this->belongsTo(\App\Models\Warehouse::class);} }
