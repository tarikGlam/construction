<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\StockLedgerService;
use App\Services\WarehouseAccessService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockLedgerController extends Controller
{
    public function __construct(private WarehouseAccessService $warehouseAccess)
    {
    }

    public function index(Request $request, StockLedgerService $ledger)
    {
        $filters = $this->filters($request);
        $report = $ledger->report($filters);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(200, max(25, (int) $request->input('per_page', 50)));
        $rows = $report['movements'];
        $movements = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('backend.report.stock_ledger', array_merge($report, $this->filterLists(), compact('filters', 'movements')));
    }

    public function export(Request $request, StockLedgerService $ledger): StreamedResponse
    {
        $report = $ledger->report($this->filters($request));
        $filename = 'stock_ledger_'.$report['start_date'].'_'.$report['end_date'].'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date/Time','Source','Source ID','Reference','Warehouse','Product','SKU','Category','Brand','Variant','UOM','Batch','IMEI/Serial','Qty In','Qty Out','Running Balance','Note']);
            foreach ($report['movements'] as $row) {
                fputcsv($out, [
                    $this->csvSafe($row['movement_at']),
                    $this->csvSafe($this->sourceLabel($row['source_type'])),
                    (int) $row['source_id'],
                    $this->csvSafe($row['reference_no']),
                    $this->csvSafe($row['warehouse_name']),
                    $this->csvSafe($row['product_name']),
                    $this->csvSafe($row['product_code']),
                    $this->csvSafe($row['category_name']),
                    $this->csvSafe($row['brand_name']),
                    $this->csvSafe($row['variant_name']),
                    $this->csvSafe($row['uom']),
                    $this->csvSafe($row['batch_no']),
                    $this->csvSafe($row['imei_number']),
                    (float) $row['qty_in'],
                    (float) $row['qty_out'],
                    (float) $row['running_balance'],
                    $this->csvSafe($row['note']),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function print(Request $request, StockLedgerService $ledger)
    {
        $filters = $this->filters($request);
        $report = $ledger->report($filters);
        return view('backend.report.stock_ledger_print', array_merge($report, compact('filters')));
    }

    public static function sourceUrl(string $sourceType, int $sourceId): ?string
    {
        $routeMap = [
            'purchase' => 'purchases.edit',
            'sale' => 'sales.edit',
            'sale_combo_component' => 'sales.edit',
            'sale_modifier_component' => 'sales.edit',
            'sale_return' => 'return-sale.edit',
            'sale_return_combo_component' => 'return-sale.edit',
            'purchase_return' => 'return-purchase.edit',
            'transfer_out' => 'transfers.edit',
            'transfer_in' => 'transfers.edit',
            'adjustment' => 'qty_adjustment.edit',
            'damage' => 'damage-stock.edit',
        ];

        $routeName = $routeMap[$sourceType] ?? null;
        if ($routeName && \Illuminate\Support\Facades\Route::has($routeName)) {
            try {
                return route($routeName, $sourceId);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function filters(Request $request): array
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $dateRange = $request->input('date_range') ?: $request->input('daterange');
        if ((!$startDate || !$endDate) && $dateRange && str_contains($dateRange, ' To ')) {
            [$startDate, $endDate] = explode(' To ', $dateRange, 2);
            $request->merge(['start_date' => trim($startDate), 'end_date' => trim($endDate)]);
        }

        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'variant_id' => ['nullable', 'integer', 'exists:variants,id'],
            'product_batch_id' => ['nullable', 'integer', 'exists:product_batches,id'],
            'imei' => ['nullable', 'string', 'max:191'],
            'source_type' => ['nullable', 'in:purchase,sale,sale_return,purchase_return,transfer_out,transfer_in,adjustment,damage,production_output,production_component'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100,200'],
        ]);

        $warehouseId = $request->filled('warehouse_id') ? (int) $request->warehouse_id : null;
        if ($this->warehouseAccess->isRestricted()) {
            $allowed = $this->warehouseAccess->warehouseId();
            abort_if(!$allowed, 403, 'Warehouse assignment is required.');
            if ($warehouseId && $warehouseId !== $allowed) abort(403, 'Warehouse access denied.');
            $warehouseId = $allowed;
        }

        return [
            'start_date' => $request->input('start_date') ?: date('Y-m-01'),
            'end_date' => $request->input('end_date') ?: date('Y-m-d'),
            'warehouse_id' => $warehouseId,
            'allowed_warehouse_id' => $this->warehouseAccess->isRestricted() ? $warehouseId : null,
            'product_id' => $request->filled('product_id') ? (int) $request->product_id : null,
            'category_id' => $request->filled('category_id') ? (int) $request->category_id : null,
            'brand_id' => $request->filled('brand_id') ? (int) $request->brand_id : null,
            'variant_id' => $request->filled('variant_id') ? (int) $request->variant_id : null,
            'product_batch_id' => $request->filled('product_batch_id') ? (int) $request->product_batch_id : null,
            'imei' => trim((string) $request->input('imei', '')) ?: null,
            'source_type' => trim((string) $request->input('source_type', '')) ?: null,
        ];
    }

    private function filterLists(): array
    {
        $warehouses = Warehouse::withoutGlobalScope('authorized_warehouse')->where('is_active', true);
        if ($this->warehouseAccess->isRestricted()) $warehouses->whereKey($this->warehouseAccess->warehouseId() ?: 0);

        return [
            'warehouses' => $warehouses->orderBy('name')->get(['id','name']),
            'products' => Product::whereIn('is_active', [1,3])->orderBy('name')->get(['id','name','code']),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id','name']),
            'brands' => Brand::where('is_active', true)->orderBy('title')->get(['id','title']),
            'variants' => DB::table('variants')->orderBy('name')->get(['id','name']),
            'batches' => DB::table('product_batches')->orderByDesc('id')->get(['id','batch_no']),
            'sourceTypes' => [
                'purchase' => 'Purchase', 'sale' => 'Sale', 'sale_return' => 'Sale Return', 'purchase_return' => 'Purchase Return',
                'transfer_out' => 'Transfer Out', 'transfer_in' => 'Transfer In', 'adjustment' => 'Adjustment', 'damage' => 'Damage',
                'production_output' => 'Production Output', 'production_component' => 'Production Component',
            ],
        ];
    }

    public function sourceLabel(string $source): string
    {
        return ucwords(str_replace('_', ' ', $source));
    }

    public function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) return "'".$value;
        return $value;
    }
}
