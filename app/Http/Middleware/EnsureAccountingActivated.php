<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AccountingModeService;

class EnsureAccountingActivated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!app(AccountingModeService::class)->isDoubleEntryAuthoritative()) {
            return redirect()->route('accounting.activation.index');
        }
        
        return $next($request);
    }
}
