<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call(Tenant\TenantDatabaseSeeder::class);

        if (Schema::hasTable('general_settings')) {
            $general_setting = DB::table('general_settings')->select('modules')->first();

        }
    }
}
