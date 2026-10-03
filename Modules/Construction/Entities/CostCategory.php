<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class CostCategory extends Model { protected $table='cost_categories'; protected $guarded=[]; protected $casts=['active'=>'boolean']; }
