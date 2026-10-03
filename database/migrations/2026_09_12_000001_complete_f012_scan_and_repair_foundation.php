<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('accounting_diagnostic_scans', function (Blueprint $table) {
            $table->string('detector_fingerprint', 64)->nullable()->after('format_version')->index();
            $table->timestamp('dataset_as_of')->nullable()->after('fingerprints');
            $table->timestamp('heartbeat_at')->nullable()->after('started_at')->index();
            $table->timestamp('paused_at')->nullable()->after('heartbeat_at');
            $table->timestamp('failed_at')->nullable()->after('completed_at');
            $table->timestamp('superseded_at')->nullable()->after('cancelled_at');
            $table->timestamp('cancellation_requested_at')->nullable()->after('superseded_at');
            $table->unsignedInteger('retry_count')->default(0)->after('progress');
            $table->unsignedBigInteger('rows_examined')->default(0)->after('retry_count');
            $table->unsignedBigInteger('findings_count')->default(0)->after('rows_examined');
            $table->json('progress_details')->nullable()->after('checkpoints');
            $table->text('last_error')->nullable()->after('failure_summary');
            $table->index(['scope', 'warehouse_id', 'detector_fingerprint', 'status'], 'acct_diag_scope_detector_status');
        });

        Schema::create('accounting_repair_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('plan_key')->unique();
            $table->string('idempotency_key', 100)->unique();
            $table->string('check_key', 150)->index();
            $table->string('detector_version', 40);
            $table->string('detector_fingerprint', 64);
            $table->string('repair_key', 150)->index();
            $table->string('scope', 24);
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->string('status', 32)->index();
            $table->json('target_ids');
            $table->json('expected_state_fingerprints');
            $table->json('proposed_mutations');
            $table->json('expected_effect');
            $table->json('preconditions');
            $table->string('required_permission', 100);
            $table->boolean('backup_confirmation_required')->default(true);
            $table->boolean('backup_confirmed')->default(false);
            $table->string('closed_period_policy', 40)->default('refuse');
            $table->unsignedBigInteger('previewed_by');
            $table->timestamp('previewed_at');
            $table->timestamp('expires_at')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();
            $table->json('corrective_journal_ids')->nullable();
            $table->json('before_fingerprints')->nullable();
            $table->json('after_fingerprints')->nullable();
            $table->string('verification_status', 32)->nullable();
            $table->json('verification_evidence')->nullable();
            $table->text('failure_evidence')->nullable();
            $table->timestamps();
            $table->index(['scope', 'warehouse_id', 'status'], 'acct_repair_scope_status');
        });

        Schema::create('accounting_repair_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('repair_plan_id')->index();
            $table->uuid('event_key')->unique();
            $table->string('event_type', 40)->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('evidence');
            $table->string('previous_event_hash', 64)->nullable();
            $table->string('event_hash', 64);
            $table->timestamp('occurred_at')->index();
            $table->foreign('repair_plan_id')->references('id')->on('accounting_repair_plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_repair_audit_events');
        Schema::dropIfExists('accounting_repair_plans');
        Schema::table('accounting_diagnostic_scans', function (Blueprint $table) {
            $table->dropIndex('acct_diag_scope_detector_status');
            $table->dropIndex(['detector_fingerprint']);
            $table->dropIndex(['heartbeat_at']);
            $table->dropColumn([
                'detector_fingerprint', 'dataset_as_of', 'heartbeat_at', 'paused_at', 'failed_at',
                'superseded_at', 'cancellation_requested_at', 'retry_count', 'rows_examined',
                'findings_count', 'progress_details', 'last_error',
            ]);
        });
    }
};
