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
        if (Schema::hasTable('india_gst_return_snapshots')) {
            return;
        }

        Schema::create('india_gst_return_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('return_id')->index();
            $table->unsignedInteger('sale_id')->nullable()->index();
            $table->unsignedBigInteger('india_gst_sale_snapshot_id')->nullable()->index();
            $table->unsignedBigInteger('gst_registration_id')->nullable()->index();

            // Adjustment document identity (Seller Credit Note)
            $table->string('adjustment_type')->default('seller_credit_note');
            $table->string('note_reference')->nullable()->index();
            $table->date('note_date')->nullable()->index();
            $table->string('original_invoice_reference')->nullable();
            $table->date('original_invoice_date')->nullable();
            $table->string('financial_year')->nullable();

            // Customer snapshot
            $table->unsignedInteger('customer_id')->nullable();
            $table->foreign('customer_id', 'fk_ret_snap_cust_id')->references('id')->on('customers')->onDelete('set null');
            $table->string('customer_name')->nullable();
            $table->string('customer_gstin')->nullable()->index();
            $table->string('customer_state_code', 2)->nullable();
            $table->string('place_of_supply_state_code', 2)->nullable();
            $table->boolean('is_inter_state')->default(false);

            // Adjusted amounts (negative/reducing output GST)
            $table->decimal('adjusted_taxable_value', 22, 4)->default(0);
            $table->decimal('adjusted_cgst', 22, 4)->default(0);
            $table->decimal('adjusted_sgst', 22, 4)->default(0);
            $table->decimal('adjusted_utgst', 22, 4)->default(0);
            $table->decimal('adjusted_igst', 22, 4)->default(0);
            $table->decimal('adjusted_cess', 22, 4)->default(0);
            $table->decimal('adjusted_total_tax', 22, 4)->default(0);
            $table->decimal('adjusted_grand_total', 22, 4)->default(0);

            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique('return_id', 'uniq_ret_snap_ret_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('india_gst_return_snapshots');
    }
};
