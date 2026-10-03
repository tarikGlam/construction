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
        if (Schema::hasTable('india_gst_purchase_line_snapshots')) {
            return;
        }

        Schema::create('india_gst_purchase_line_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('india_gst_purchase_snapshot_id');
            $table->foreign('india_gst_purchase_snapshot_id', 'fk_pur_line_snap_id')->references('id')->on('india_gst_purchase_snapshots')->onDelete('cascade');
            
            $table->unsignedInteger('product_purchase_id');
            $table->foreign('product_purchase_id', 'fk_pur_line_prod_pur_id')->references('id')->on('product_purchases')->onDelete('restrict');

            // Product info
            $table->unsignedInteger('product_id')->nullable();
            $table->foreign('product_id', 'fk_pur_line_prod_id')->references('id')->on('products')->onDelete('set null');
            $table->unsignedInteger('variant_id')->nullable();
            if (Schema::hasTable('variants')) {
                $table->foreign('variant_id', 'fk_pur_line_var_id')->references('id')->on('variants')->onDelete('set null');
            }
            $table->string('product_name')->nullable();
            $table->string('product_code')->nullable();
            $table->string('variant_name')->nullable();

            // Classification
            $table->string('classification')->nullable(); // goods, services
            $table->string('hsn_sac_code')->nullable();
            $table->string('uqc_code')->nullable();

            $table->unsignedInteger('purchase_unit_id')->nullable();
            $table->foreign('purchase_unit_id', 'fk_pur_line_unit_id')->references('id')->on('units')->onDelete('set null');
            $table->string('purchase_unit_name')->nullable();

            $table->decimal('quantity', 22, 4)->default(0);
            $table->decimal('unit_cost', 22, 4)->default(0);
            $table->boolean('tax_inclusive')->default(false);

            // Amounts
            $table->decimal('gross_value', 22, 4)->default(0);
            $table->decimal('line_discount', 22, 4)->default(0);
            $table->decimal('allocated_invoice_discount', 22, 4)->default(0);
            $table->decimal('taxable_charges', 22, 4)->default(0);
            $table->decimal('non_taxable_charges', 22, 4)->default(0);
            $table->decimal('taxable_value', 22, 4)->default(0);

            // Tax Profile
            $table->string('taxability_type')->nullable(); // taxable, nil-rated, exempt, non-gst
            $table->unsignedBigInteger('gst_tax_profile_id')->nullable();
            $table->foreign('gst_tax_profile_id', 'fk_pur_line_tax_prof_id')->references('id')->on('gst_tax_profiles')->onDelete('set null');
            $table->string('gst_tax_profile_code')->nullable();
            $table->integer('gst_tax_profile_version')->nullable();
            $table->string('profile_resolution_source')->nullable();

            // Components
            $table->decimal('cgst_rate', 8, 4)->default(0);
            $table->decimal('cgst_amount', 22, 4)->default(0);
            $table->decimal('sgst_rate', 8, 4)->default(0);
            $table->decimal('sgst_amount', 22, 4)->default(0);
            $table->decimal('utgst_rate', 8, 4)->default(0);
            $table->decimal('utgst_amount', 22, 4)->default(0);
            $table->decimal('igst_rate', 8, 4)->default(0);
            $table->decimal('igst_amount', 22, 4)->default(0);

            // Cess
            $table->string('cess_calculation_type')->nullable();
            $table->decimal('cess_rate', 8, 4)->default(0);
            $table->decimal('cess_amount_per_unit', 22, 4)->default(0);
            $table->decimal('cess_amount', 22, 4)->default(0);

            // RCM & ITC
            $table->boolean('is_reverse_charge')->default(false);
            $table->string('itc_eligibility')->default('eligible');
            $table->decimal('eligible_itc_amount', 22, 4)->default(0);
            $table->decimal('ineligible_itc_amount', 22, 4)->default(0);

            // Totals
            $table->decimal('rounding_adjustment', 22, 4)->default(0);
            $table->decimal('line_total', 22, 4)->default(0);
            $table->string('calculation_rule_code')->nullable();

            $table->timestamps();

            $table->unique('product_purchase_id', 'idx_uniq_prod_pur_snap');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('india_gst_purchase_line_snapshots');
    }
};
