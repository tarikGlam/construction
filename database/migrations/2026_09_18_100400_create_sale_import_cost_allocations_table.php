<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sale_import_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sale_id');
            $table->unsignedInteger('product_sale_id');
            $table->unsignedBigInteger('import_stock_layer_id');
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variant_id')->nullable();
            $table->unsignedInteger('warehouse_id');
            $table->decimal('allocated_qty', 14, 4);
            $table->decimal('unit_landed_cost', 14, 4);
            $table->decimal('total_cost', 14, 4);
            $table->decimal('goods_cost', 18, 4)->default(0);
            $table->decimal('landed_cost', 18, 4)->default(0);
            $table->decimal('selling_price', 14, 4)->default(0);
            $table->decimal('revenue', 14, 4)->default(0);
            $table->decimal('realized_gross_profit', 14, 4)->default(0);
            $table->unsignedInteger('currency_id')->nullable();
            $table->timestamps();

            $table->index('sale_id');
            $table->index('product_sale_id');
            $table->index('import_stock_layer_id');
            $table->index('import_batch_id');
            $table->index('warehouse_id');
            $table->foreign('import_stock_layer_id')->references('id')->on('import_stock_layers')->onDelete('restrict');
            $table->foreign('import_batch_id')->references('id')->on('import_batches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_import_cost_allocations');
    }
};
