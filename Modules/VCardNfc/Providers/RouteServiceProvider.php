<?php

namespace Modules\VCardNfc\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected $moduleNamespace = 'Modules\\VCardNfc\\Http\\Controllers';

    public function map(): void
    {
        Route::middleware('web')
            ->namespace($this->moduleNamespace)
            ->group(module_path('VCardNfc', '/Routes/web.php'));
    }
}
