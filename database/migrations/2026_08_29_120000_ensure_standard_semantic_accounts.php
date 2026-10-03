<?php

use App\Services\StandardSemanticAccountService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(StandardSemanticAccountService::class)->apply();
    }

    public function down(): void
    {
        // Financial accounts and customer mappings are deliberately retained.
        // Removing them automatically could orphan journal history created after upgrade.
    }
};
