<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('return_import_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('return_id');
            $table->unsignedInteger('product_return_id');
            $table->unsignedBigInteger('sale_import_cost_allocation_id');
            $table->unsignedBigInteger('import_stock_layer_id');
            $table->decimal('returned_qty', 14, 4);
            $table->decimal('unit_cost_restored', 14, 4);
            $table->decimal('total_cost_restored', 14, 4);
            $table->decimal('goods_cost_restored', 18, 4)->default(0);
            $table->decimal('landed_cost_restored', 18, 4)->default(0);
            $table->decimal('revenue_reversed', 18, 4)->default(0);
            $table->timestamps();

            $table->index('return_id', 'ret_imp_ret_id_idx');
            $table->index('product_return_id', 'ret_imp_prod_ret_id_idx');
            $table->index('sale_import_cost_allocation_id', 'ret_imp_sale_alloc_id_idx');
            $table->foreign('sale_import_cost_allocation_id', 'fk_ret_alloc_sale_alloc')
                ->references('id')->on('sale_import_cost_allocations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_import_cost_allocations');
    }
};
