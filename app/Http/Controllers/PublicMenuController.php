<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\GeneralSetting;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\User;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Product_Sale;
use App\Models\Currency;
use App\Models\Biller;
use App\Models\PosSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class PublicMenuController extends Controller
{
    public function placeOrder(Request $request)
    {
        // This channel has no reviewed Phase 2 fiscal transaction contract yet.
        if (app(\App\Services\ZatcaIntegrationService::class)->isPhase2Configured()) {
            return response()->json(['success' => false,
                'message' => 'Public menu orders are not yet available in ZATCA Phase 2. Please ask the seller to create a reviewed SalePro sale.'], 422);
        }
        try {
            $postData = $request->validate([
                'billing_name' => ['required', 'string', 'max:255'],
                'billing_phone' => ['required', 'string', 'max:50'],
                'billing_address' => ['nullable', 'string', 'max:1000'],
                'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
                'cart' => ['required', 'array', 'min:1'],
                'cart.*.id' => ['required', 'integer'],
                'cart.*.qty' => ['required', 'numeric', 'gt:0'],
                'cart.*.variant_id' => ['nullable'],
                'idempotency_key' => ['nullable', 'string', 'max:191'],
            ]);

            return DB::transaction(function () use ($postData) {
                $generalSetting = cache()->get('general_setting') ?: GeneralSetting::firstOrFail();
                $currency = Currency::whereKey($generalSetting->currency)->where('is_active', 1)->firstOrFail();
                $warehouse = Warehouse::whereKey($postData['warehouse_id'])->where('is_active', 1)->firstOrFail();
                $operator = Auth::user() ?: User::where('role_id', 1)->where('is_active', 1)->firstOrFail();
                $customer = Customer::where('phone_number', $postData['billing_phone'])->first();

                if (!$customer) {
                    $customer = Customer::create([
                        'name' => $postData['billing_name'],
                        'phone_number' => $postData['billing_phone'],
                        'address' => $postData['billing_address'] ?? 'N/A',
                        'city' => 'N/A',
                        'customer_group_id' => 1,
                        'is_active' => 1,
                    ]);
                }

                $biller = Biller::where('is_active', true)->firstOrFail();
                $lines = collect($postData['cart'])->values()->map(function (array $item): array {
                    $product = Product::whereKey($item['id'])->where('is_active', 1)->firstOrFail();
                    $productVariantId = !empty($item['variant_id']) && $item['variant_id'] !== 'null'
                        ? (int) $item['variant_id']
                        : null;
                    $variant = $productVariantId
                        ? $product->variant()->wherePivot('id', $productVariantId)->firstOrFail()
                        : null;
                    if ($product->is_variant && !$variant) {
                        throw new \RuntimeException("A variant selection is required for {$product->name}.");
                    }
                    if (!$product->is_variant && $variant) {
                        throw new \RuntimeException("{$product->name} does not support variant selection.");
                    }
                    $quantity = number_format((float) $item['qty'], 4, '.', '');
                    $unitPrice = number_format(
                        (float) $product->price + (float) ($variant?->pivot?->additional_price ?? 0),
                        4,
                        '.',
                        ''
                    );
                    return [
                        'product_id' => $product->id,
                        'product_variant_id' => $productVariantId,
                        'qty' => $quantity,
                        'net_unit_price' => $unitPrice,
                        'subtotal' => number_format((float) $unitPrice * (float) $quantity, 4, '.', ''),
                    ];
                });
                $total = number_format((float) $lines->sum('subtotal'), 4, '.', '');

                $saleData = [
                    'reference_no' => app(\App\Services\InvoiceService::class)->generateInvoiceName('pm-', true),
                    'user_id' => $operator->id,
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'biller_id' => $biller->id,
                    'product_id' => $lines->pluck('product_id')->all(),
                    'product_variant_id' => $lines->pluck('product_variant_id')->all(),
                    'qty' => $lines->pluck('qty')->all(),
                    'sale_unit' => array_fill(0, $lines->count(), 'n/a'),
                    'net_unit_price' => $lines->pluck('net_unit_price')->all(),
                    'discount' => array_fill(0, $lines->count(), 0),
                    'tax_rate' => array_fill(0, $lines->count(), 0),
                    'tax' => array_fill(0, $lines->count(), 0),
                    'subtotal' => $lines->pluck('subtotal')->all(),
                    'item' => $lines->count(),
                    'total_qty' => number_format((float) $lines->sum('qty'), 4, '.', ''),
                    'total_price' => $total,
                    'grand_total' => $total,
                    'total_discount' => 0,
                    'total_tax' => 0,
                    'order_tax_rate' => 0,
                    'order_tax' => 0,
                    'order_discount' => 0,
                    'shipping_cost' => 0,
                    'sale_status' => 1,
                    'payment_status' => 1,
                    'paid_amount' => 0,
                    'sale_note' => $postData['sale_note'] ?? null,
                    'sale_type' => 'online',
                    'payment_mode' => 'WhatsApp Order',
                    'currency_id' => $currency->id,
                    'exchange_rate' => (string) $currency->exchange_rate,
                    'accounting_event_type' => 'public_menu_order_created',
                ];
                if (!empty($postData['idempotency_key'])) {
                    $saleData['idempotency_key'] = $postData['idempotency_key'];
                }

                $sale = app(\App\Services\Domain\SaleDomainService::class)->createSale($saleData, $operator);
                if (!($sale->was_replayed ?? false)) {
                    app(\App\Services\SaleActivityLogService::class)->logCreated($sale);
                }

                return response()->json(['success' => true, 'reference' => $sale->reference_no]);
            }, 3);
        } catch (\Throwable $e) {
            \Log::error('Public menu order failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => __('db.Something went wrong'),
            ], 422);
        }
    }

    /**
     * Resolve a warehouse by its slug, or abort 404.
     */
    private function resolveWarehouse(string $slug): Warehouse
    {
        $warehouses = Warehouse::where('is_active', 1)->get();
        $warehouse  = $warehouses->first(function ($w) use ($slug) {
            return Str::slug($w->name) === $slug;
        });

        if (! $warehouse) {
            abort(404, 'Business not found.');
        }

        return $warehouse;
    }

    /**
     * Build the grouped variants_data structure expected by the front-end.
     * Applied to a single Product model instance.
     */
    private function buildVariantsData(Product $product): array
    {
        if (! $product->is_variant || $product->variant->isEmpty() || ! $product->variant_option) {
            return [];
        }

        $groups       = json_decode($product->variant_option, true) ?? [];
        $valueStrings = json_decode($product->variant_value,  true) ?? [];
        $pivotRows    = $product->variant->values(); // ordered by position
        $offset       = 0;
        $grouped      = [];

        foreach ($groups as $i => $groupLabel) {
            $optionNames = array_filter(
                array_map('trim', explode(',', $valueStrings[$i] ?? ''))
            );
            $options = [];
            foreach (array_values($optionNames) as $j => $optName) {
                $pv = $pivotRows->get($offset + $j);
                if (! $pv) {
                    continue;
                }
                $options[] = [
                    'id'               => $pv->pivot->id,
                    'name'             => $optName,
                    'additional_price' => (float) ($pv->pivot->additional_price ?? 0),
                ];
            }
            $offset += count($optionNames);
            if (count($options)) {
                $grouped[] = ['group' => $groupLabel, 'options' => $options];
            }
        }

        return $grouped;
    }

    // ─── Public page ─────────────────────────────────────────────────────────

    public function index(Request $request, $slug)
    {
        $warehouse = $this->resolveWarehouse($slug);

        // Handle optional table (restaurant module)
        $table           = null;
        $isRestaurant    = false;
        $general_setting = cache()->get('general_setting');

        if ($general_setting && $general_setting->modules) {
            $modules      = explode(',', $general_setting->modules);
            $isRestaurant = in_array('restaurant', $modules);
        }

        if ($isRestaurant && $request->table_id) {
            $table = DB::table('tables')
                ->join('floors', 'tables.floor_id', '=', 'floors.id')
                ->where('tables.id', $request->table_id)
                ->where('floors.warehouse_id', $warehouse->id)
                ->where('tables.is_active', 1)
                ->select('tables.id', 'tables.name', 'tables.floor_id')
                ->first();
        }

        $qr_catelog_setting = \App\Models\QrCatelogSetting::latest()->first();

        // Build categories that actually have active products in this warehouse.
        // This prevents empty categories from appearing in the nav.
        $productWarehouseQuery = DB::table('product_warehouse')
            ->where('warehouse_id', $warehouse->id);

        if ($qr_catelog_setting && $qr_catelog_setting->show_stock_out_product == 0) {
            $productWarehouseQuery->where('qty', '>', 0);
        }

        $productIds = $productWarehouseQuery->pluck('product_id');

        $activeCategoryIds = Product::whereIn('id', $productIds)
            ->where('is_active', 1)
            ->whereNotNull('category_id')
            ->pluck('category_id')
            ->filter()
            ->unique();

        $categories = Category::whereIn('id', $activeCategoryIds)
            ->where('is_active', 1)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return view('public_menu.index', compact(
            'warehouse',
            'table',
            'categories',
            'general_setting',
            'slug'
        ));
    }

    // ─── AJAX products endpoint ───────────────────────────────────────────────

    public function getProducts(Request $request, $slug)
    {
        $warehouse  = $this->resolveWarehouse($slug);
        $categoryId = (int) $request->query('category_id');
        $page       = max(1, (int) $request->query('page', 1));
        $limit      = 5;
        $general_setting = cache()->get('general_setting');

        $productIds = DB::table('product_warehouse')
            ->where('warehouse_id', $warehouse->id)
            ->pluck('product_id');

        $query = Product::join('product_warehouse', 'products.id', '=', 'product_warehouse.product_id')
            ->where('product_warehouse.warehouse_id', $warehouse->id)
            ->where('products.is_active', 1)
            ->with(['variant' => function ($q) {
                $q->orderBy('product_variants.position');
            }])
            ->select(
                'products.id',
                'products.name',
                'products.image',
                'products.price',
                'products.category_id',
                'products.is_variant',
                'products.variant_option',
                'products.variant_value',
                DB::raw('SUM(product_warehouse.qty) as qty')
            )
            ->groupBy('products.id');

        if ($categoryId) {
            $query->where('products.category_id', $categoryId);
        } else {
            // null category – uncategorised products
            $query->whereNull('products.category_id');
        }

        $qr_catelog_setting = \App\Models\QrCatelogSetting::latest()->first();
        if ($qr_catelog_setting && $qr_catelog_setting->show_stock_out_product == 0) {
            $query->having('qty', '>', 0);
        }

        $paginated = $query->paginate($limit, ['*'], 'page', $page);

        $data = $paginated->map(function (Product $product) {
            // Support comma-separated image filenames (multi-image products)
            $rawImages  = $product->image ? array_filter(array_map('trim', explode(',', $product->image))) : [];
            $imageUrls  = [];
            foreach ($rawImages as $filename) {
                if ($filename && file_exists(public_path('images/product/' . $filename))) {
                    $imageUrls[] = asset('images/product/' . $filename);
                }
            }
            $primaryImage = $imageUrls[0] ?? null;

            return [
                'id'                => $product->id,
                'name'              => $product->name,
                'price'             => (float) $product->price,
                'image'             => $primaryImage,
                'images'            => $imageUrls,
                'qty'               => (float) $product->qty,
                'is_variant'        => (bool) $product->is_variant,
                'has_variants'      => count($this->buildVariantsData($product)) > 0,
                'variants_data'     => $this->buildVariantsData($product),
            ];
        });

        return response()->json([
            'data'         => $data->values(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
        ]);
    }
}
