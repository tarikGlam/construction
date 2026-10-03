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
        if (!Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $table) {
                $table->id();
                $table->string('event', 64)->index();
                $table->string('tenant_id', 64)->nullable()->index();
                $table->string('subject_type', 128);
                $table->unsignedBigInteger('subject_id');
                $table->string('event_version', 64);
                $table->string('channel', 32); // in_app, whatsapp, sms, mail
                $table->string('recipient_category', 32); // admin, customer, supplier
                $table->string('recipient_hash', 64)->index(); // sha256(normalized destination) for privacy
                $table->text('recipient_destination')->nullable(); // Encrypted at rest
                $table->text('rendered_subject')->nullable(); // Frozen snapshot
                $table->text('rendered_body')->nullable(); // Frozen snapshot (encrypted at rest)
                $table->json('payload_snapshot')->nullable(); // Frozen snapshot
                $table->enum('status', ['pending', 'processing', 'sent', 'failed', 'skipped'])->default('pending')->index();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('idempotency_key', 191)->unique();
                $table->string('provider_message_id', 191)->nullable();
                $table->text('error_summary')->nullable(); // Sanitized, no secrets/PII
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id']);
                $table->index(['status', 'attempts']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
