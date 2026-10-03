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
        if (Schema::hasTable('india_gst_purchase_snapshots')) {
            return;
        }

        Schema::create('india_gst_purchase_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('purchase_id');
            $table->foreign('purchase_id', 'fk_pur_snap_pur_id')->references('id')->on('purchases')->onDelete('restrict');
            $table->unsignedBigInteger('gst_registration_id');
            $table->foreign('gst_registration_id', 'fk_pur_snap_reg_id')->references('id')->on('india_gst_registrations')->onDelete('restrict');

            // Document identity
            $table->string('invoice_reference')->nullable()->index();
            $table->date('invoice_date')->nullable()->index();
            $table->date('transaction_date')->nullable();
            $table->string('financial_year')->nullable();

            // Supplier snapshot
            $table->unsignedInteger('supplier_id')->nullable();
            $table->foreign('supplier_id', 'fk_pur_snap_supp_id')->references('id')->on('suppliers')->onDelete('set null');
            $table->string('supplier_name')->nullable();
            $table->string('supplier_legal_name')->nullable();
            $table->string('supplier_trade_name')->nullable();
            $table->string('supplier_gstin')->nullable()->index();
            $table->string('supplier_registration_type')->nullable();
            $table->text('supplier_address')->nullable();
            $table->string('supplier_state_code', 2)->nullable();
            $table->string('supplier_state_name')->nullable();

            // Recipient (Warehouse / Business) snapshot
            $table->string('recipient_legal_name')->nullable();
            $table->string('recipient_trade_name')->nullable();
            $table->string('recipient_gstin')->nullable();
            $table->text('recipient_address')->nullable();
            $table->string('recipient_state_code', 2)->nullable();
            $table->string('recipient_state_name')->nullable();

            // Place of supply
            $table->string('place_of_supply_state_code', 2)->nullable()->index();
            $table->string('place_of_supply_state_name')->nullable();
            $table->string('supply_rule_code')->nullable();
            $table->string('jurisdiction_code')->nullable();
            $table->boolean('is_inter_state')->default(false);

            // Reverse Charge Mechanism (RCM)
            $table->boolean('is_reverse_charge')->default(false);
            $table->decimal('rcm_liability_cgst', 22, 4)->default(0);
            $table->decimal('rcm_liability_sgst', 22, 4)->default(0);
            $table->decimal('rcm_liability_utgst', 22, 4)->default(0);
            $table->decimal('rcm_liability_igst', 22, 4)->default(0);
            $table->decimal('rcm_liability_cess', 22, 4)->default(0);
            $table->decimal('rcm_total_liability', 22, 4)->default(0);

            // Input Tax Credit (ITC) eligibility
            $table->string('itc_eligibility')->default('eligible'); // eligible, ineligible, blocked
            $table->decimal('itc_cgst', 22, 4)->default(0);
            $table->decimal('itc_sgst', 22, 4)->default(0);
            $table->decimal('itc_utgst', 22, 4)->default(0);
            $table->decimal('itc_igst', 22, 4)->default(0);
            $table->decimal('itc_cess', 22, 4)->default(0);
            $table->decimal('total_eligible_itc', 22, 4)->default(0);
            $table->decimal('total_ineligible_itc', 22, 4)->default(0);

            // Manual override
            $table->boolean('manual_pos_override_used')->default(false);
            $table->string('manual_pos_override_reason')->nullable();
            $table->unsignedInteger('manual_pos_override_user_id')->nullable();
            $table->foreign('manual_pos_override_user_id', 'fk_pur_snap_user_id')->references('id')->on('users')->onDelete('set null');

            // Currency
            $table->string('currency_code', 3)->nullable();
            $table->decimal('exchange_rate', 15, 6)->default(1);

            // Totals
            $table->decimal('total_gross_value', 22, 4)->default(0);
            $table->decimal('total_line_discount', 22, 4)->default(0);
            $table->decimal('total_invoice_discount', 22, 4)->default(0);
            $table->decimal('total_taxable_charges', 22, 4)->default(0);
            $table->decimal('total_non_taxable_charges', 22, 4)->default(0);
            $table->decimal('total_taxable_value', 22, 4)->default(0);

            $table->decimal('total_cgst', 22, 4)->default(0);
            $table->decimal('total_sgst', 22, 4)->default(0);
            $table->decimal('total_utgst', 22, 4)->default(0);
            $table->decimal('total_igst', 22, 4)->default(0);
            $table->decimal('total_cess', 22, 4)->default(0);

            $table->decimal('rounding_adjustment', 22, 4)->default(0);
            $table->decimal('grand_total', 22, 4)->default(0);

            // State
            $table->integer('snapshot_version')->default(1);
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            // Unique index for 1 snapshot per purchase
            $table->unique('purchase_id', 'uniq_pur_snap_pur_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('india_gst_purchase_snapshots');
    }
};
