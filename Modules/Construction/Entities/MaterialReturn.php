<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class MaterialReturn extends Model { protected $guarded=[]; protected $casts=['return_date'=>'date']; public function items(){return $this->hasMany(MaterialReturnItem::class);} public function issue(){return $this->belongsTo(MaterialIssue::class,'material_issue_id');} }
