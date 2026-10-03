<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_batch_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedInteger('purchase_id')->nullable();
            $table->string('cost_type', 100);
            $table->decimal('original_amount', 14, 4);
            $table->unsignedInteger('currency_id');
            $table->decimal('exchange_rate', 16, 8)->default(1.00000000);
            $table->decimal('base_amount', 14, 4);
            $table->unsignedInteger('vendor_id')->nullable();
            $table->string('reference_no', 191)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_posted_to_accounts')->default(false);
            $table->unsignedBigInteger('accounting_transaction_id')->nullable();
            $table->timestamps();

            $table->index('import_batch_id');
            $table->index('purchase_id');
            $table->foreign('import_batch_id')->references('id')->on('import_batches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_costs');
    }
};
