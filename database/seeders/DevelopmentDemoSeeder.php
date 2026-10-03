<?php

namespace Database\Seeders;

use Database\Seeders\Tenant\TenantDatabaseSeeder;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * Opt-in sample catalogue for local development and automated demonstrations.
 * Public installation and the normal DatabaseSeeder never invoke this class.
 */
class DevelopmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo data may only be seeded in local or testing environments.');
        }

        config(['salepro.seed_demo_data' => true]);

        $this->call(TenantDatabaseSeeder::class);
    }
}
