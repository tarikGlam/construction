<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessModuleEnabled
{
    public function __construct(private ModuleAccessService $access)
    {
    }

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $this->access->assertBusinessEnabled($module);
        return $next($request);
    }
}
