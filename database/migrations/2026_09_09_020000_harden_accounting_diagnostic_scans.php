<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('accounting_diagnostic_scans', function (Blueprint $table) {
            // Existing Phase 2.1 rows intentionally remain v1 and cannot be resumed as v2.
            $table->unsignedSmallInteger('format_version')->default(1)->after('version');
            $table->string('consistency_status', 24)->default('bounded')->after('status')->index();
            $table->json('watermarks')->nullable()->after('watermark');
            $table->json('checkpoints')->nullable()->after('watermarks');
            $table->json('fingerprints')->nullable()->after('checkpoints');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_diagnostic_scans', function (Blueprint $table) {
            $table->dropIndex(['consistency_status']);
            $table->dropColumn(['format_version', 'consistency_status', 'watermarks', 'checkpoints', 'fingerprints']);
        });
    }
};
