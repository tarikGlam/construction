<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class ProjectReceipt extends Model { protected $guarded=[]; protected $casts=['receipt_date'=>'date','amount'=>'decimal:4']; public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} public function customer(){return $this->belongsTo(\App\Models\Customer::class);} public function account(){return $this->belongsTo(\App\Models\Account::class);} }
