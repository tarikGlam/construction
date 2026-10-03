<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Product_Sale;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SaleActivityLogService
{
    /**
     * Record a Sale Created entry without allowing logging failures to break
     * a completed customer transaction.
     */
    public function logCreated(Sale $sale): ?ActivityLog
    {
        if (!Schema::hasTable('activity_logs')) {
            return null;
        }

        try {
            $existing = ActivityLog::where('action', 'Sale Created')
                ->where('reference_no', $sale->reference_no)
                ->first();

            if ($existing) {
                return $existing;
            }

            $items = Product_Sale::query()
                ->leftJoin('products', 'product_sales.product_id', '=', 'products.id')
                ->where('product_sales.sale_id', $sale->id)
                ->orderBy('product_sales.id')
                ->get([
                    'products.name',
                    'products.code',
                    'product_sales.qty',
                ]);

            $itemDescription = $items->map(function ($item) {
                $label = trim((string) ($item->name ?? ''));
                $code = trim((string) ($item->code ?? ''));
                if ($code !== '') {
                    $label .= ($label !== '' ? '-' : '') . $code;
                }
                if ($label === '') {
                    $label = 'Product';
                }

                return e($label) . ' ' . rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.') . '<br>';
            })->implode('');

            if ($itemDescription === '') {
                $itemDescription = 'Sale';
            }

            return ActivityLog::create([
                'date' => optional($sale->created_at)->toDateString() ?: now()->toDateString(),
                'user_id' => (int) ($sale->user_id ?: auth()->id() ?: 1),
                'reference_no' => (string) $sale->reference_no,
                'action' => 'Sale Created',
                'item_description' => $itemDescription,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to create Sale activity log', [
                'sale_id' => $sale->id,
                'reference_no' => $sale->reference_no,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
