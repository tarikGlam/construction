<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatchCost extends Model
{
    protected $table = 'import_batch_costs';

    protected $fillable = [
        'import_batch_id',
        'cost_type',
        'original_amount',
        'currency_id',
        'exchange_rate',
        'base_amount',
        'vendor_id',
        'reference_no',
        'notes',
        'is_posted_to_accounts',
        'accounting_transaction_id',
    ];

    protected $casts = [
        'original_amount' => 'decimal:4',
        'exchange_rate' => 'decimal:8',
        'base_amount' => 'decimal:4',
        'is_posted_to_accounts' => 'boolean',
    ];

    public function importBatch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Supplier::class, 'vendor_id');
    }
}
