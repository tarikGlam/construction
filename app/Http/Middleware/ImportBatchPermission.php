<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportBatchPermission
{
    public function handle(Request $request, Closure $next, string $abilities)
    {
        $user = $request->user();
        abort_unless($user && $user->role_id, 403);

        $allowed = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $user->role_id)
            ->where('permissions.guard_name', 'web')
            ->whereIn('permissions.name', explode('|', $abilities))
            ->exists();

        abort_unless($allowed, 403, 'Import batch permission denied.');

        return $next($request);
    }
}
