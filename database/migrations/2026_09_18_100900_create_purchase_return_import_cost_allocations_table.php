<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_return_import_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('return_purchase_id');
            $table->unsignedInteger('purchase_product_return_id');
            $table->unsignedBigInteger('import_stock_layer_id');
            $table->decimal('returned_qty', 14, 4);
            $table->decimal('goods_cost', 18, 4);
            $table->decimal('landed_cost', 18, 4);
            $table->timestamps();
            $table->index('return_purchase_id', 'purchase_return_import_return_idx');
            $table->unique(['purchase_product_return_id', 'import_stock_layer_id'], 'purchase_return_import_line_layer_unique');
            $table->foreign('import_stock_layer_id', 'purchase_return_import_layer_fk')->references('id')->on('import_stock_layers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_import_cost_allocations');
    }
};
