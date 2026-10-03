<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounting_source_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->string('source_type'); $table->unsignedBigInteger('source_id');
            $table->string('action', 40); $table->string('status', 32);
            $table->unsignedBigInteger('related_source_id')->nullable();
            $table->string('related_source_type')->nullable();
            $table->unsignedBigInteger('original_journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('evidence'); $table->string('evidence_hash', 64);
            $table->timestamp('occurred_at')->index();
            $table->index(['source_type', 'source_id']);
            $table->index('original_journal_entry_id', 'acct_lifecycle_original_journal');
        });
    }
    public function down(): void { Schema::dropIfExists('accounting_source_lifecycle_events'); }
};
