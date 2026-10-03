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
        if (Schema::hasTable('india_gst_expense_snapshots')) {
            return;
        }

        Schema::create('india_gst_expense_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('expense_id')->index();
            $table->unsignedBigInteger('gst_registration_id')->nullable()->index();
            $table->unsignedInteger('expense_category_id')->nullable()->index();

            // Document identity
            $table->string('reference_no')->nullable()->index();
            $table->date('expense_date')->nullable()->index();
            $table->string('financial_year')->nullable();

            // Vendor info
            $table->string('vendor_name')->nullable();
            $table->string('vendor_gstin')->nullable()->index();
            $table->string('vendor_state_code', 2)->nullable();
            $table->string('vendor_state_name')->nullable();

            // Recipient (Warehouse / Business) info
            $table->string('recipient_state_code', 2)->nullable();
            $table->string('recipient_state_name')->nullable();

            // Place of Supply & Jurisdiction
            $table->string('place_of_supply_state_code', 2)->nullable()->index();
            $table->string('place_of_supply_state_name')->nullable();
            $table->string('jurisdiction_code')->nullable();
            $table->boolean('is_inter_state')->default(false);

            // Classification & Tax Profile
            $table->string('hsn_sac_code')->nullable();
            $table->unsignedBigInteger('gst_tax_profile_id')->nullable();
            $table->foreign('gst_tax_profile_id', 'fk_exp_snap_tax_prof_id')->references('id')->on('gst_tax_profiles')->onDelete('set null');
            $table->decimal('gst_rate', 8, 4)->default(0);

            // RCM & ITC
            $table->boolean('is_reverse_charge')->default(false);
            $table->boolean('is_itc_eligible')->default(true);
            $table->decimal('rcm_liability', 22, 4)->default(0);
            $table->decimal('eligible_itc', 22, 4)->default(0);
            $table->decimal('ineligible_itc', 22, 4)->default(0);

            // Amounts
            $table->decimal('taxable_amount', 22, 4)->default(0);
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
            $table->decimal('total_tax', 22, 4)->default(0);
            $table->decimal('total_amount', 22, 4)->default(0);

            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique('expense_id', 'uniq_exp_snap_exp_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('india_gst_expense_snapshots');
    }
};
