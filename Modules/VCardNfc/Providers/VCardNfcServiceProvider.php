<?php

namespace Modules\VCardNfc\Providers;

use Illuminate\Support\ServiceProvider;

class VCardNfcServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'VCardNfc';
    protected string $moduleNameLower = 'vcardnfc';

    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));
        app()->make('router')->aliasMiddleware('vcardnfc.admin', \Modules\VCardNfc\Http\Middleware\VCardAdmin::class);
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'Config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');

        $this->mergeConfigFrom(
            module_path($this->moduleName, 'Config/config.php'),
            $this->moduleNameLower
        );
    }

    protected function registerViews(): void
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'Resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->moduleNameLower . '-module-views']);
        $this->loadViewsFrom([$sourcePath, $viewPath], $this->moduleNameLower);
    }

    protected function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);
        $this->loadTranslationsFrom(is_dir($langPath) ? $langPath : module_path($this->moduleName, 'Resources/lang'), $this->moduleNameLower);
    }
}
