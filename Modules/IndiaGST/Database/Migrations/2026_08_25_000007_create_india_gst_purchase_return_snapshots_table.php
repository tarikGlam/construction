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
        if (Schema::hasTable('india_gst_purchase_return_snapshots')) {
            return;
        }

        Schema::create('india_gst_purchase_return_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('return_purchase_id')->index('idx_prs_ret_id');
            $table->unsignedInteger('purchase_id')->nullable()->index('idx_prs_pur_id');
            $table->unsignedBigInteger('india_gst_purchase_snapshot_id')->nullable()->index('idx_prs_snap_id');
            $table->unsignedBigInteger('gst_registration_id')->nullable()->index('idx_prs_reg_id');

            // Adjustment document identity (Supplier Credit Note / Purchase GST Adjustment)
            $table->string('adjustment_type')->default('supplier_credit_note'); // supplier_credit_note, supplier_debit_note
            $table->string('note_reference')->nullable()->index();
            $table->date('note_date')->nullable()->index();
            $table->string('original_invoice_reference')->nullable();
            $table->date('original_invoice_date')->nullable();
            $table->string('financial_year')->nullable();

            // Supplier snapshot
            $table->unsignedInteger('supplier_id')->nullable();
            $table->foreign('supplier_id', 'fk_prs_supp_id')->references('id')->on('suppliers')->onDelete('set null');
            $table->string('supplier_name')->nullable();
            $table->string('supplier_gstin')->nullable()->index();
            $table->string('supplier_state_code', 2)->nullable();
            $table->string('recipient_state_code', 2)->nullable();
            $table->string('place_of_supply_state_code', 2)->nullable();
            $table->boolean('is_inter_state')->default(false);

            // Reversed/Adjusted amounts (reducing input tax credit previously availed)
            $table->decimal('adjusted_taxable_value', 22, 4)->default(0);
            $table->decimal('adjusted_cgst', 22, 4)->default(0);
            $table->decimal('adjusted_sgst', 22, 4)->default(0);
            $table->decimal('adjusted_utgst', 22, 4)->default(0);
            $table->decimal('adjusted_igst', 22, 4)->default(0);
            $table->decimal('adjusted_cess', 22, 4)->default(0);
            $table->decimal('adjusted_total_tax', 22, 4)->default(0);
            $table->decimal('reversed_itc_amount', 22, 4)->default(0);
            $table->decimal('adjusted_grand_total', 22, 4)->default(0);

            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique('return_purchase_id', 'uniq_prs_ret_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('india_gst_purchase_return_snapshots');
    }
};
