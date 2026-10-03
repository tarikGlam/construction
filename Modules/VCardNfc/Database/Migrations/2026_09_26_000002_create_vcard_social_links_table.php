<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vcard_social_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vcard_profile_id');
            $table->string('platform', 30);
            $table->string('url');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('vcard_profile_id')->references('id')->on('vcard_profiles')->cascadeOnDelete();
            $table->unique(['vcard_profile_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vcard_social_links');
    }
};
