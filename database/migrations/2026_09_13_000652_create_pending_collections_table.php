<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_collections', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('idempotency_key')->unique();
            $table->string('reference_no')->unique();
            $table->integer('customer_id')->unsigned();
            $table->integer('sale_id')->unsigned();
            $table->integer('warehouse_id')->unsigned();
            $table->integer('account_id')->unsigned();
            $table->decimal('amount', 20, 4);
            $table->string('paying_method');
            $table->string('tender_reference')->nullable();
            $table->text('payment_note')->nullable();
            $table->integer('collected_by')->unsigned();
            $table->timestamp('collected_at');
            $table->integer('handed_over_to')->unsigned()->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->text('handover_notes')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'reversed'])->default('pending');
            $table->integer('approved_by')->unsigned()->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('rejected_by')->unsigned()->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->integer('reversed_by')->unsigned()->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('payment_id')->unsigned()->nullable()->unique();
            $table->timestamps();

            // Actor Identity Snapshots
            $table->string('collected_by_name')->nullable();
            $table->string('handed_over_to_name')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->string('rejected_by_name')->nullable();
            $table->string('reversed_by_name')->nullable();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('restrict');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('restrict');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('restrict');
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('restrict');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('restrict');
            $table->foreign('collected_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('handed_over_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('rejected_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reversed_by')->references('id')->on('users')->onDelete('set null');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('pending_collections');
    }
};
