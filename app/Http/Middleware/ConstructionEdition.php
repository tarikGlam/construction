<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ConstructionEdition
{
    private const BLOCKED_PREFIXES = [
        'pos', 'sales', 'return-sale', 'exchange', 'cash-register', 'register-reconciliation',
        'gift_cards', 'coupons', 'discount', 'discount-plans', 'reward-point', 'sale-agents',
        'biller', 'billers', 'booking', 'bookings', 'courier', 'couriers', 'delivery', 'packing-slips', 'challans', 'printers',
        'quotations', 'installment-plans', 'labels', 'barcode',
        'tables', 'qr-menu', 'ecommerce', 'woocommerce', 'socialcommerce', 'restaurant', 'gym',
        'repair', 'ai-assistant', 'vcard-nfc', 'zatca-ksa', 'manufacturing',
        'setting/pos_setting', 'setting/reward_point_setting', 'setting/modules', 'setting/qr-catalog',
    ];

    public function handle(Request $request, Closure $next)
    {
        $path = trim($request->path(), '/');
        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/') || str_starts_with($path, 'api/'.$prefix)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
