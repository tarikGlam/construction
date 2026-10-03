<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vcard_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('vcard_profiles', 'linked_user_id')) {
                $table->unsignedBigInteger('linked_user_id')->nullable()->index()->after('user_id');
            }
            if (!Schema::hasColumn('vcard_profiles', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->index()->after('linked_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vcard_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('vcard_profiles', 'employee_id')) {
                $table->dropColumn('employee_id');
            }
            if (Schema::hasColumn('vcard_profiles', 'linked_user_id')) {
                $table->dropColumn('linked_user_id');
            }
        });
    }
};
