<?php

namespace Modules\AIAssistant\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The module namespace to assume when generating URLs to actions.
     */
    protected string $moduleNamespace = 'Modules\AIAssistant\Http\Controllers';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        parent::boot();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ai-assistant', function (Request $request) {
            $limit = (int) config('aiassistant.rate_limit', 60);

            $tenantId = 'global';
            if (function_exists('tenant') && tenant('id')) {
                $tenantId = (string) tenant('id');
            } elseif (app()->bound('tenant') && app('tenant')) {
                $tenant = app('tenant');
                $tenantId = is_object($tenant) ? ($tenant->id ?? 'global') : (string) $tenant;
            }

            $userOrIp = $request->user()?->id ? ('u:' . $request->user()->id) : ('ip:' . $request->ip());

            return Limit::perMinute($limit)->by($tenantId . '|' . $userOrIp);
        });
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->moduleNamespace)
            ->group(module_path('AIAssistant', '/Routes/web.php'));
    }
}
