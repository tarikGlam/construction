<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transfer_import_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('transfer_id');
            $table->unsignedInteger('product_transfer_id');
            $table->unsignedBigInteger('source_import_stock_layer_id');
            $table->unsignedBigInteger('destination_import_stock_layer_id');
            $table->decimal('transferred_qty', 14, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->timestamps();

            $table->index('transfer_id', 'trf_imp_trf_id_idx');
            $table->index('product_transfer_id', 'trf_imp_prod_trf_id_idx');
            $table->index('source_import_stock_layer_id', 'trf_imp_src_layer_idx');
            $table->index('destination_import_stock_layer_id', 'trf_imp_dst_layer_idx');
            $table->foreign('source_import_stock_layer_id', 'fk_trf_alloc_src_layer')
                ->references('id')->on('import_stock_layers')->onDelete('restrict');
            $table->foreign('destination_import_stock_layer_id', 'fk_trf_alloc_dst_layer')
                ->references('id')->on('import_stock_layers')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_import_cost_allocations');
    }
};
