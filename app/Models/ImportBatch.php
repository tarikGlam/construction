<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImportBatch extends Model
{
    use SoftDeletes;

    protected $table = 'import_batches';

    protected $fillable = [
        'batch_number',
        'reference_no',
        'title',
        'warehouse_id',
        'base_currency_id',
        'status',
        'is_locked',
        'allocation_method',
        'total_goods_cost',
        'total_landed_cost',
        'total_cost',
        'notes',
        'received_at',
        'finalized_at',
        'created_by',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'total_goods_cost' => 'decimal:4',
        'total_landed_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'received_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'import_batch_id');
    }

    public function costs()
    {
        return $this->hasMany(ImportBatchCost::class, 'import_batch_id');
    }

    public function stockLayers()
    {
        return $this->hasMany(ImportStockLayer::class, 'import_batch_id');
    }

    public function saleAllocations()
    {
        return $this->hasMany(SaleImportCostAllocation::class, 'import_batch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized' || $this->status === 'closed';
    }
}
