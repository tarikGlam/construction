<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPurchase extends Model
{
    protected $table = 'product_purchases';
    protected $fillable =[

        "purchase_id", "product_id", "product_batch_id", "variant_id", "imei_number", "qty", "recieved", "return_qty", "purchase_unit_id", "net_unit_cost", "net_unit_price", "net_unit_margin", "net_unit_margin_type", "discount", "tax_rate", "tax", "total", "project_id", "site_id", "cost_category_id"
    ];

    /**
     * Get the purchase that this product purchase belongs to
     */
    protected static function booted(): void
    {
        static::creating(function (ProductPurchase $line) {
            if (!$line->purchase_id || ($line->project_id && $line->site_id)) return;
            $purchase = Purchase::find($line->purchase_id);
            if (!$purchase) return;
            $line->project_id = $line->project_id ?: $purchase->project_id;
            $line->site_id = $line->site_id ?: $purchase->site_id;
        });

        static::created(function (ProductPurchase $line) {
            if (!class_exists(\Modules\Construction\Entities\SupplierProductReference::class)
                || !\Illuminate\Support\Facades\Schema::hasTable('supplier_product_references')) return;
            $purchase = Purchase::find($line->purchase_id);
            if (!$purchase?->supplier_id) return;
            \Modules\Construction\Entities\SupplierProductReference::updateOrCreate(
                ['supplier_id' => $purchase->supplier_id, 'product_id' => $line->product_id],
                ['last_purchase_price' => $line->net_unit_cost]
            );
        });
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    /**
     * Get the product for this purchase
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
