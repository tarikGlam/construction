<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/dashboard';

    /**
     * Required by legacy routes such as:
     * 'HomeController@method'
     */
    protected $namespace = 'App\Http\Controllers';

    public function boot(): void
    {
        $this->configureRateLimiting();

        parent::boot();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $identity = $request->user()?->getAuthIdentifier()
                ?: $request->ip();

            $tenantId = function_exists('tenant')
                ? tenant('id')
                : null;

            return Limit::perMinute(60)->by(
                $tenantId
                    ? $tenantId . '_' . $identity
                    : $identity
            );
        });
    }

    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    protected function mapWebRoutes(): void
    {
        foreach ($this->centralRouteDomains() as $domain) {
            $routes = Route::middleware('web')
                ->namespace($this->namespace);

            if ($domain !== null) {
                $routes->domain($domain);
            }

            $routes->group(base_path('routes/web.php'));
        }
    }

    protected function mapApiRoutes(): void
    {
        foreach ($this->centralRouteDomains() as $domain) {
            $routes = Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace);

            if ($domain !== null) {
                $routes->domain($domain);
            }

            $routes->group(base_path('routes/api.php'));
        }
    }

    /**
     * SaaS: return configured central domains.
     * Single SalePro: return null for routes without domain restriction.
     */
    protected function centralRouteDomains(): array
    {
        if (! config()->has('tenancy.central_domains')) {
            return [null];
        }

        $domains = array_values(array_filter(
            (array) config('tenancy.central_domains'),
            fn ($domain) => is_string($domain) && $domain !== ''
        ));

        // During the initial SaaS installation CENTRAL_DOMAIN is blank.
        // Load the central routes without a domain restriction so that the
        // installer route remains reachable and can save the current domain.
        return $domains !== [] ? $domains : [null];
    }
}
