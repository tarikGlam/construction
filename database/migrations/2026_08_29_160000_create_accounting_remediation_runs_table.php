<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_remediation_runs')) {
            return;
        }

        Schema::create('accounting_remediation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('remediation_key')->index();
            $table->string('status')->index();
            $table->string('database_name');
            $table->string('plan_hash', 64)->index();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->text('backup_path');
            $table->string('backup_sha256', 64);
            $table->longText('backup_verification_json');
            $table->string('fingerprint_before', 64);
            $table->string('fingerprint_after', 64)->nullable();
            $table->longText('selectors_json');
            $table->longText('before_state_json');
            $table->longText('proposed_actions_json');
            $table->longText('result_json')->nullable();
            $table->text('planned_manifest_path')->nullable();
            $table->text('manifest_path')->nullable();
            $table->text('manifest_temp_path')->nullable();
            $table->string('manifest_sha256', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_remediation_runs');
    }
};
