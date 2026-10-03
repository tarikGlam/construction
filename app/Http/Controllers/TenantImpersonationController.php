<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\landlord\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantImpersonationController extends Controller
{
    /**
     * Create a short-lived, one-time login handoff from the central domain.
     */
    public function create(Request $request, string $id): RedirectResponse
    {
        if (! env('USER_VERIFIED')) {
            return back()->with('not_permitted', 'This feature is disabled for demo!');
        }

        $tenant = Tenant::query()->with('domains')->findOrFail($id);

        abort_unless((int) ($tenant->status ?? 1) === 1, 422, 'The tenant is inactive.');

        $domain = $tenant->domains->first()?->domain;
        abort_unless($domain, 422, 'The tenant does not have a domain.');

        $tenantUserId = null;
        $tenant->run(function () use (&$tenantUserId): void {
            $tenantUserId = DB::table('users')
                ->where('role_id', 1)
                ->where('is_active', 1)
                ->where(function ($query) {
                    $query->whereNull('is_deleted')->orWhere('is_deleted', 0);
                })
                ->orderBy('id')
                ->value('id');
        });

        abort_unless($tenantUserId, 422, 'No active tenant administrator was found.');

        $plainToken = Str::random(64);

        DB::connection(config('tenancy.database.central_connection'))
            ->table('tenant_impersonations')
            ->insert([
                'tenant_id' => (string) $tenant->getTenantKey(),
                'superadmin_id' => (int) Auth::id(),
                'tenant_user_id' => (int) $tenantUserId,
                'token_hash' => hash('sha256', $plainToken),
                'created_ip' => $request->ip(),
                'created_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                'expires_at' => now()->addSeconds(60),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $scheme = $request->isSecure() ? 'https' : 'http';
        $host = $this->normalizeDomain($domain);

        return redirect()->away("{$scheme}://{$host}/impersonate/{$plainToken}");
    }

    /**
     * Consume the handoff after tenant middleware has selected the database.
     */
    public function consume(Request $request, string $token): RedirectResponse
    {
        abort_unless(tenancy()->initialized, 404);

        $connection = DB::connection(config('tenancy.database.central_connection'));
        $tokenHash = hash('sha256', $token);

        $impersonation = $connection->transaction(function () use ($connection, $request, $tokenHash) {
            $record = $connection->table('tenant_impersonations')
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            abort_unless(
                $record
                && hash_equals((string) $record->tenant_id, (string) tenant('id'))
                && $record->consumed_at === null
                && now()->lt($record->expires_at),
                403,
                'This impersonation link is invalid or has expired.'
            );

            $connection->table('tenant_impersonations')
                ->where('id', $record->id)
                ->update([
                    'consumed_at' => now(),
                    'consumed_ip' => $request->ip(),
                    'consumed_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                    'updated_at' => now(),
                ]);

            return $record;
        });

        $user = User::query()
            ->whereKey($impersonation->tenant_user_id)
            ->where('role_id', 1)
            ->where('is_active', 1)
            ->where(function ($query) {
                $query->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->firstOrFail();

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonated_by_superadmin', $impersonation->superadmin_id);
        $request->session()->put('tenant_impersonation_id', $impersonation->id);

        return redirect('/dashboard')->with('message', 'You are now logged in as the tenant administrator.');
    }

    private function normalizeDomain(string $domain): string
    {
        $host = parse_url(Str::contains($domain, '://') ? $domain : "//{$domain}", PHP_URL_HOST);
        $port = parse_url(Str::contains($domain, '://') ? $domain : "//{$domain}", PHP_URL_PORT);

        abort_unless($host, 422, 'The tenant domain is invalid.');

        return $port ? "{$host}:{$port}" : $host;
    }
}
