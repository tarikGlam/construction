<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    public function __construct(private ModuleAccessService $access)
    {
    }

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $this->access->assertUserCanAccess($request->user(), $module);
        return $next($request);
    }
}
