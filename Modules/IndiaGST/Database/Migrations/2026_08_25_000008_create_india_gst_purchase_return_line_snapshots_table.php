<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('india_gst_purchase_return_line_snapshots')) {
            return;
        }

        Schema::create('india_gst_purchase_return_line_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('india_gst_purchase_return_snapshot_id');
            $table->foreign('india_gst_purchase_return_snapshot_id', 'fk_pur_ret_line_snap_id')->references('id')->on('india_gst_purchase_return_snapshots')->onDelete('cascade');
            
            $table->unsignedInteger('purchase_product_return_id')->index('idx_prls_ret_id');
            $table->unsignedInteger('product_id')->nullable()->index('idx_prls_prod_id');
            $table->string('hsn_sac_code')->nullable();
            $table->decimal('quantity', 22, 4)->default(0);
            $table->decimal('unit_cost', 22, 4)->default(0);
            $table->decimal('taxable_value', 22, 4)->default(0);

            $table->decimal('cgst_rate', 8, 4)->default(0);
            $table->decimal('cgst_amount', 22, 4)->default(0);
            $table->decimal('sgst_rate', 8, 4)->default(0);
            $table->decimal('sgst_amount', 22, 4)->default(0);
            $table->decimal('utgst_rate', 8, 4)->default(0);
            $table->decimal('utgst_amount', 22, 4)->default(0);
            $table->decimal('igst_rate', 8, 4)->default(0);
            $table->decimal('igst_amount', 22, 4)->default(0);
            $table->decimal('cess_rate', 8, 4)->default(0);
            $table->decimal('cess_amount', 22, 4)->default(0);
            $table->decimal('reversed_itc_amount', 22, 4)->default(0);
            $table->decimal('line_total', 22, 4)->default(0);

            $table->timestamps();
            $table->unique('purchase_product_return_id', 'uniq_pur_prod_ret_snap');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('india_gst_purchase_return_line_snapshots');
    }
};
