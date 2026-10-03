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
        if (Schema::hasTable('india_gst_supplier_profiles')) {
            return;
        }

        Schema::create('india_gst_supplier_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('supplier_id')->unique();
            $table->string('registration_type')->default('regular'); // regular, composition, unregistered, overseas, sez
            $table->string('gstin', 15)->nullable();
            $table->foreignId('state_id')->nullable()->constrained('india_gst_states');
            $table->boolean('is_sez')->default(false);
            $table->string('pan', 10)->nullable();
            $table->timestamps();

            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('india_gst_supplier_profiles');
    }
};
