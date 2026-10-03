<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_stock_layers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedInteger('purchase_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variant_id')->nullable();
            $table->unsignedInteger('product_batch_id')->nullable();
            $table->unsignedInteger('warehouse_id');
            $table->decimal('received_qty', 14, 4)->default(0);
            $table->decimal('remaining_qty', 14, 4)->default(0);
            $table->decimal('unit_purchase_cost', 14, 4)->default(0);
            $table->decimal('unit_landed_cost', 14, 4)->default(0);
            $table->decimal('total_unit_cost', 14, 4)->default(0);
            $table->decimal('goods_base_amount', 18, 4)->default(0);
            $table->decimal('allocated_landed_amount', 18, 4)->default(0);
            $table->decimal('remaining_goods_amount', 18, 4)->default(0);
            $table->decimal('remaining_landed_amount', 18, 4)->default(0);
            $table->unsignedInteger('currency_id')->nullable();
            $table->string('source_type', 50)->default('purchase');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'remaining_qty'], 'layer_lookup_idx');
            $table->index('import_batch_id');
            $table->index('warehouse_id');
            $table->foreign('import_batch_id')->references('id')->on('import_batches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_stock_layers');
    }
};
