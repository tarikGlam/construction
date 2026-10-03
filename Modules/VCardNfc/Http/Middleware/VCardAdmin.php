<?php

namespace Modules\VCardNfc\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VCardAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user && (int) $user->role_id <= 2, 403);

        return $next($request);
    }
}
