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
        if (!Schema::hasTable('pos_payment_attempts')) {
            Schema::create('pos_payment_attempts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('attempt_uuid', 64)->unique();
                $table->string('gateway', 50)->default('razorpay');
                $table->string('method', 50)->default('upi');
                $table->string('order_id', 100)->nullable();
                $table->string('payment_id', 100)->nullable();
                $table->decimal('expected_amount', 20, 4);
                $table->string('currency', 10)->default('INR');
                $table->string('state', 30)->default('initiated'); // initiated, verified, finalized, failed, cancelled
                $table->unsignedInteger('sale_id')->nullable();
                $table->unsignedBigInteger('payment_record_id')->nullable();
                $table->string('idempotency_key', 100)->nullable()->unique();
                $table->unsignedInteger('customer_id')->nullable();
                $table->unsignedInteger('warehouse_id')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->json('sale_context')->nullable();
                $table->json('verification_data')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();

                $table->index(['order_id', 'state']);
                $table->index(['sale_id', 'state']);
                $table->unique(['gateway', 'order_id'], 'pos_attempt_gw_order_unique');
                $table->unique(['gateway', 'payment_id'], 'pos_attempt_gw_pmt_unique');
            });
        }

        if (!Schema::hasTable('payment_gateway_webhook_events')) {
            Schema::create('payment_gateway_webhook_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('gateway', 50);
                $table->string('event_id', 100)->unique();
                $table->string('event_type', 100);
                $table->string('payment_id', 100)->nullable()->index();
                $table->string('order_id', 100)->nullable()->index();
                $table->json('payload')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_webhook_events');
        Schema::dropIfExists('pos_payment_attempts');
    }
};
