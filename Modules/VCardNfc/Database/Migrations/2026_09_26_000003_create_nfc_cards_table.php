<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nfc_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vcard_profile_id')->nullable()->index();
            $table->string('token', 64)->unique();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->foreign('vcard_profile_id')->references('id')->on('vcard_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_cards');
    }
};
