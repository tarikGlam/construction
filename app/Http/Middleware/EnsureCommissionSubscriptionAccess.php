<?php

namespace App\Http\Middleware;

use App\Services\Billing\CommissionBillingService;
use Closure;
use Illuminate\Http\Request;

class EnsureCommissionSubscriptionAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('database.connections.saleprosaas_landlord')
            || !tenant() || tenant('subscription_type') !== 'profit_commission'
            || ($request->isMethodSafe() && !$request->routeIs('challan.finalize', 'approveHoliday'))
            || $request->is('subscription/billing*', 'login', 'logout', 'password/*', 'api/login', 'api/logout')
            || $request->routeIs('payment.*.webhook', 'payment.*.callback', 'payment.*.notify', 'payment.*.return')
            || !app(CommissionBillingService::class)->isRestricted((string) tenant('id'))) {
            return $next($request);
        }
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Subscription invoice overdue. Ask the account owner to open Subscription Billing.', 'code' => 'subscription_overdue'], 402);
        }
        return response()->view('backend.commission_billing.restricted', [], 402);
    }
}
