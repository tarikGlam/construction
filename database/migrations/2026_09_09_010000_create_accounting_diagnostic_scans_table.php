<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounting_diagnostic_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('scan_key')->unique();
            $table->string('scope', 24);
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('status', 24)->index();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('progress')->default(0);
            $table->string('current_check')->nullable();
            $table->unsignedBigInteger('cursor')->nullable();
            $table->unsignedBigInteger('watermark')->default(0);
            $table->json('results')->nullable();
            $table->text('failure_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['scope', 'warehouse_id', 'status'], 'diagnostic_scan_scope_status');
            $table->index(['requested_by', 'created_at'], 'diagnostic_scan_owner_created');
        });
    }

    public function down(): void { Schema::dropIfExists('accounting_diagnostic_scans'); }
};
