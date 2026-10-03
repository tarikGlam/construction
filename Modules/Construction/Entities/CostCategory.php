<?php

namespace Modules\Construction\Entities;

use Illuminate\Database\Eloquent\Model;

class CostCategory extends Model
{
    protected $table = 'cost_categories';
    protected $guarded = [];
    protected $casts = ['active' => 'boolean'];

    public function expenseCategory()
    {
        return $this->belongsTo(\App\Models\ExpenseCategory::class, 'expense_category_id');
    }
}
