<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class Subcontractor extends Model { protected $guarded=[]; public function contracts(){return $this->hasMany(SubcontractorContract::class);} }
