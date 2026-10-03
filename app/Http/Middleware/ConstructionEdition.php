<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ConstructionEdition
{
    private const BLOCKED_PREFIXES = [
        'pos', 'sales', 'return-sale', 'exchange', 'cash-register', 'register-reconciliation',
        'gift_cards', 'coupons', 'discount', 'discounts', 'discount-plans', 'reward-point', 'sale-agents',
        'biller', 'billers', 'booking', 'bookings', 'courier', 'couriers', 'delivery', 'packing-slips', 'challans', 'printers',
        'gift-cards', 'pos-setting', 'reward-point-setting',
        'quotations', 'installment-plans', 'labels', 'barcode',
        'tables', 'qr-menu', 'ecommerce', 'woocommerce', 'socialcommerce', 'restaurant', 'gym',
        'repair', 'ai-assistant', 'vcard-nfc', 'zatca-ksa', 'manufacturing',
        'menu', 'setting/pos_setting', 'setting/reward-point-setting', 'setting/reward_point_setting',
        'setting/modules', 'setting/qr-catalog', 'new-release', 'version-upgrade',
        'saas-install', 'ecommerce-install', 'woocommerce-install', 'api-install',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (config('app.vertical') !== 'construction') {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/') || str_starts_with($path, 'api/'.$prefix)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
