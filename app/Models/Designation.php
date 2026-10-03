<?php

namespace App\Models;

use App\Traits\WarehouseScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Designation extends Model
{
    use HasFactory, WarehouseScoped;

    protected $fillable = [
        'name',
        'warehouse_id',
        'is_active',
    ];

    /**
     * A designation can have many employees.
     */
    public function employees()
    {
        return $this->hasMany(Employee::class, 'designation_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Scope for only active designations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
