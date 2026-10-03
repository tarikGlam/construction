<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vcard_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vcard_profile_id')->index();
            $table->unsignedBigInteger('nfc_card_id')->nullable()->index();
            $table->string('event_type', 30)->index();
            $table->string('source', 20)->nullable()->index();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('vcard_profile_id')->references('id')->on('vcard_profiles')->cascadeOnDelete();
            $table->foreign('nfc_card_id')->references('id')->on('nfc_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vcard_events');
    }
};
