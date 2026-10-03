<?php

namespace App\Models;

use App\Traits\HolidayWarehouseScoped;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HolidayWarehouseScoped;

    protected $fillable = ["user_id", "warehouse_id", "from_date", "to_date", "note", "is_approved",'recurring','region'];

    public static function createHoliday($data) {
    	Holiday::create($data);
    }

    public function user() {
    	return $this->belongsTo('App\Models\User');
    }

    public function warehouse() {
        return $this->belongsTo(Warehouse::class);
    }
}
