<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('subcontractor_payments')) {
            Schema::create('subcontractor_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subcontractor_contract_id')->index();
                $table->unsignedBigInteger('project_id')->index();
                $table->unsignedBigInteger('subcontractor_id')->index();
                $table->date('payment_date')->index();
                $table->decimal('amount', 20, 4);
                $table->unsignedInteger('warehouse_id')->index();
                $table->unsignedInteger('account_id')->index();
                $table->unsignedInteger('expense_id')->nullable()->index();
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->string('posting_status', 30)->default('operational')->index();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subcontractor_payments');
    }
};
