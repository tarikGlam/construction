<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('adjustment_import_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('stock_adjustment_id');
            $table->unsignedInteger('product_adjustment_id');
            $table->unsignedBigInteger('import_stock_layer_id');
            $table->decimal('adjusted_qty', 14, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('total_cost', 14, 4);
            $table->decimal('goods_cost', 18, 4)->default(0);
            $table->decimal('landed_cost', 18, 4)->default(0);
            $table->timestamps();

            $table->index('stock_adjustment_id', 'adj_imp_adj_id_idx');
            $table->index('product_adjustment_id', 'adj_imp_prod_adj_id_idx');
            $table->index('import_stock_layer_id', 'adj_imp_layer_id_idx');
            $table->foreign('import_stock_layer_id', 'fk_adj_alloc_layer')
                ->references('id')->on('import_stock_layers')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustment_import_cost_allocations');
    }
};
