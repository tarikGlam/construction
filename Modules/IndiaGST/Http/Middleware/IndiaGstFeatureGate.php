<?php

namespace Modules\IndiaGST\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\IndiaGST\Services\IndiaGstFeature;

class IndiaGstFeatureGate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (!config('india-gst.enabled', false)) {
            // Protect direct access to India GST routes
            abort(403, 'India GST feature is currently disabled.');
        }

        return $next($request);
    }
}
