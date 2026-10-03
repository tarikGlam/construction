<?php

namespace Modules\Construction\Providers;

use Illuminate\Support\ServiceProvider;

class ConstructionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path('Construction', 'Database/Migrations'));
        $this->loadViewsFrom(module_path('Construction', 'Resources/views'), 'construction');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(module_path('Construction', 'Config/config.php'), 'construction');
        $this->app->register(RouteServiceProvider::class);
    }
}
