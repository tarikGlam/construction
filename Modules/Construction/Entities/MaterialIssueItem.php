<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class MaterialIssueItem extends Model { protected $guarded=[]; protected $casts=['quantity'=>'decimal:4','unit_cost'=>'decimal:4','total_cost'=>'decimal:4']; public function issue(){return $this->belongsTo(MaterialIssue::class,'material_issue_id');} public function product(){return $this->belongsTo(\App\Models\Product::class);} }
