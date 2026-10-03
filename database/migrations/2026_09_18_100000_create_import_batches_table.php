<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 100)->unique();
            $table->string('reference_no', 191)->nullable()->index();
            $table->string('title', 255);
            $table->unsignedInteger('warehouse_id');
            $table->unsignedInteger('base_currency_id');
            $table->string('status', 50)->default('draft');
            $table->boolean('is_locked')->default(false);
            $table->string('allocation_method', 50)->default('purchase_value');
            $table->decimal('total_goods_cost', 14, 4)->default(0.0000);
            $table->decimal('total_landed_cost', 14, 4)->default(0.0000);
            $table->decimal('total_cost', 14, 4)->default(0.0000);
            $table->text('notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('warehouse_id');
            $table->index('status');
            $table->index('is_locked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
