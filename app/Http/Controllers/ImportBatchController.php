<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\ImportBatch;
use App\Models\ImportBatchCost;
use App\Models\ImportStockLayer;
use App\Models\SaleImportCostAllocation;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\CurrencyNormalizationService;
use App\Services\ImportBatchProfitabilityService;
use App\Services\ImportBatchService;
use App\Services\WarehouseAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportBatchController extends Controller
{
    public function __construct(
        protected ImportBatchService $batchService,
        protected ImportBatchProfitabilityService $profitabilityService,
        protected WarehouseAccessService $warehouseAccess,
        protected CurrencyNormalizationService $currencyService
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $isRestricted = $this->warehouseAccess->isRestricted($user);
        $userWarehouseId = $this->warehouseAccess->warehouseId($user);

        $query = ImportBatch::with(['warehouse', 'baseCurrency', 'creator'])
            ->latest('received_at');

        if ($isRestricted) {
            abort_unless($userWarehouseId, 403, 'Warehouse access denied.');
            $query->where(function ($query) use ($userWarehouseId) {
                $query->where('warehouse_id', $userWarehouseId)
                    ->orWhereHas('stockLayers', fn ($layers) => $layers->where('warehouse_id', $userWarehouseId))
                    ->orWhereHas('saleAllocations', fn ($sales) => $sales->where('warehouse_id', $userWarehouseId));
            });
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('starting_date') && $request->filled('ending_date')) {
            $query->whereBetween('received_at', [
                $request->starting_date . ' 00:00:00',
                $request->ending_date . ' 23:59:59'
            ]);
        }

        $batches = $query->paginate(25);
        $warehousesQuery = Warehouse::where('is_active', true);
        if ($isRestricted) {
            $warehousesQuery->whereKey($userWarehouseId);
        }
        $warehouses = $warehousesQuery->get();

        return view('backend.import_batch.index', compact('batches', 'warehouses', 'isRestricted', 'userWarehouseId'));
    }

    public function create()
    {
        $user = Auth::user();
        $isRestricted = $this->warehouseAccess->isRestricted($user);
        $userWarehouseId = $this->warehouseAccess->warehouseId($user);

        $warehousesQuery = Warehouse::where('is_active', true);
        if ($isRestricted) {
            abort_unless($userWarehouseId, 403, 'Warehouse access denied.');
            $warehousesQuery->where('id', $userWarehouseId);
        }
        $warehouses = $warehousesQuery->get();

        $defaultWarehouseId = $userWarehouseId ?: ($warehouses->first()?->id ?? 0);
        $baseCurrencyId = $this->currencyService->getBaseCurrencyId();
        $baseCurrency = Currency::find($baseCurrencyId);

        return view('backend.import_batch.create', compact('warehouses', 'defaultWarehouseId', 'baseCurrency'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'reference_no' => 'nullable|string|max:191',
            'title' => 'required|string|max:191',
            'allocation_method' => 'required|in:purchase_value,quantity,manual',
            'received_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'purchase_ids' => 'nullable|array',
            'purchase_ids.*' => 'exists:purchases,id',
        ]);

        $requestedWarehouseId = $request->attributes->get('requested_warehouse_id');
        if ($requestedWarehouseId !== null && (int) $requestedWarehouseId !== (int) $validated['warehouse_id']) {
            abort(403, 'Warehouse access denied.');
        }
        $this->warehouseAccess->authorizeWarehouse((int)$validated['warehouse_id'], $request->user());

        try {
            $batch = $this->batchService->createBatch($validated, Auth::id());
            return redirect()->route('import-batches.landed-cost', $batch->id)
                ->with('message', "Import container batch {$batch->batch_number} created successfully. Now enter landed costs.");
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('not_permitted', $e->getMessage());
        }
    }

    public function edit($id)
    {
        $batch = ImportBatch::with(['warehouse', 'purchases.supplier', 'baseCurrency'])->findOrFail($id);

        $user = Auth::user();
        if ($this->warehouseAccess->isRestricted($user)) {
            $this->warehouseAccess->authorizeWarehouse((int)$batch->warehouse_id, request()->user());
        }

        $warehousesQuery = Warehouse::where('is_active', true);
        if ($this->warehouseAccess->isRestricted($user)) {
            $warehousesQuery->whereKey($this->warehouseAccess->warehouseId($user));
        }
        $warehouses = $warehousesQuery->get();

        // Available purchases belonging to batch warehouse that are unlinked or already linked to this batch
        $availablePurchases = Purchase::where('warehouse_id', $batch->warehouse_id)
            ->where(function ($q) use ($batch) {
                $q->whereNull('import_batch_id')
                  ->orWhere('import_batch_id', $batch->id);
            })
            ->with(['supplier', 'currency'])
            ->latest()
            ->get();

        return view('backend.import_batch.edit', compact('batch', 'warehouses', 'availablePurchases'));
    }

    public function update(Request $request, $id)
    {
        $batch = ImportBatch::findOrFail($id);
        $this->warehouseAccess->authorizeWarehouse((int)$batch->warehouse_id, request()->user());

        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'reference_no' => 'nullable|string|max:191',
            'title' => 'required|string|max:191',
            'allocation_method' => 'required|in:purchase_value,quantity,manual',
            'received_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'purchase_ids' => 'nullable|array',
            'purchase_ids.*' => 'exists:purchases,id',
        ]);

        $requestedWarehouseId = $request->attributes->get('requested_warehouse_id');
        if ($requestedWarehouseId !== null && (int) $requestedWarehouseId !== (int) $validated['warehouse_id']) {
            abort(403, 'Warehouse access denied.');
        }
        $this->warehouseAccess->authorizeWarehouse((int)$validated['warehouse_id'], $request->user());

        try {
            $batch = $this->batchService->updateBatch($batch, $validated);
            return redirect()->route('import-batches.index')
                ->with('message', "Import batch {$batch->batch_number} updated successfully.");
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('not_permitted', $e->getMessage());
        }
    }

    public function landedCost($id)
    {
        $batch = ImportBatch::with([
            'warehouse',
            'baseCurrency',
            'purchases.supplier',
            'purchases.products',
            'costs.currency',
            'costs.vendor',
            'stockLayers'
        ])->findOrFail($id);

        $user = Auth::user();
        if ($this->warehouseAccess->isRestricted($user)) {
            $this->warehouseAccess->authorizeWarehouse((int)$batch->warehouse_id, request()->user());
        }

        $currencies = Currency::where('is_active', true)->get();
        $suppliers = Supplier::where('is_active', true)->get();

        $allocations = $this->batchService->calculateAllocations($batch, $batch->allocation_method);

        return view('backend.import_batch.landed_cost', compact('batch', 'currencies', 'suppliers', 'allocations'));
    }

    public function addCost(Request $request, $id)
    {
        $batch = ImportBatch::findOrFail($id);
        $this->warehouseAccess->authorizeWarehouse((int)$batch->warehouse_id, request()->user());

        $validated = $request->validate([
            'cost_type' => 'required|string|max:100',
            'original_amount' => 'required|numeric|min:0.0001',
            'currency_id' => 'required|exists:currencies,id',
            'exchange_rate' => 'required|numeric|gt:0',
            'vendor_id' => 'nullable|exists:suppliers,id',
            'reference_no' => 'nullable|string|max:191',
            'notes' => 'nullable|string',
        ]);

        try {
            $this->batchService->addCost($batch, $validated);
            return redirect()->route('import-batches.landed-cost', $batch->id)
                ->with('message', 'Landed cost added successfully.');
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('not_permitted', $e->getMessage());
        }
    }

    public function updateCost(Request $request, $costId)
    {
        $cost = ImportBatchCost::with('importBatch')->findOrFail($costId);
        $batch = $cost->importBatch;
        $this->warehouseAccess->authorizeWarehouse((int)$batch->warehouse_id, request()->user());

        $validated = $request->validate([
            'cost_type' => 'required|string|max:100',
            'original_amount' => 'required|numeric|min:0.0001',
            'currency_id' => 'required|exists:currencies,id',
            'exchange_rate' => 'required|numeric|gt:0',
            'vendor_id' => 'nullable|exists:suppliers,id',
            'reference_no' => 'nullable|string|max:191',
            'notes' => 'nullable|string',
        ]);

        try {
            $this->batchService->updateCost($cost, $validated);
            return redirect()->route('import-batches.landed-cost', $batch->id)
                ->with('message', 'Landed cost line updated successfully.');
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('not_permitted', $e->getMessage());
        }
    }

    public function deleteCost($costId)
    {
        $cost = ImportBatchCost::with('importBatch')->findOrFail($costId);
        $batch = $cost->importBatch;
        $this->warehouseAccess->authorizeWarehouse((int)$batch->warehouse_id, request()->user());

        try {
            $this->batchService->deleteCost($cost);
            return redirect()->route('import-batches.landed-cost', $batch->id)
                ->with('message', 'Landed cost line deleted.');
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        } catch (\Throwable $e) {
            return redirect()->back()->with('not_permitted', $e->getMessage());
        }
    }

    public function finalize(Request $request, $id)
    {
        $batch = ImportBatch::findOrFail($id);
        $this->warehouseAccess->authorizeWarehouse((int)$batch->warehouse_id, request()->user());

        $method = $request->input('allocation_method', $batch->allocation_method);
        $manualAllocations = $request->input('manual_weights', null);

        try {
            $this->batchService->finalizeBatch($batch, $method, $manualAllocations);
            return redirect()->route('import-batches.landed-cost', $batch->id)
                ->with('message', "Import Batch {$batch->batch_number} finalized successfully. Stock layers created and costed.");
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        } catch (\Throwable $e) {
            return redirect()->back()->with('not_permitted', $e->getMessage());
        }
    }

    public function reopen($id)
    {
        $batch = ImportBatch::findOrFail($id);
        $this->warehouseAccess->authorizeWarehouse((int)$batch->warehouse_id, request()->user());

        try {
            $this->batchService->reopenBatch($batch);
            return redirect()->route('import-batches.landed-cost', $batch->id)
                ->with('message', "Import Batch {$batch->batch_number} reopened to draft.");
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        } catch (\Throwable $e) {
            return redirect()->back()->with('not_permitted', $e->getMessage());
        }
    }

    public function profitability($id)
    {
        $batch = ImportBatch::findOrFail($id);
        $user = Auth::user();

        $isRestricted = $this->warehouseAccess->isRestricted($user);
        $userWarehouseId = $this->warehouseAccess->warehouseId($user);

        if ($isRestricted) {
            abort_unless($userWarehouseId, 403, 'Warehouse access denied.');
            $visible = (int) $batch->warehouse_id === $userWarehouseId
                || ImportStockLayer::where('import_batch_id', $batch->id)->where('warehouse_id', $userWarehouseId)->exists()
                || SaleImportCostAllocation::where('import_batch_id', $batch->id)->where('warehouse_id', $userWarehouseId)->exists();
            abort_unless($visible, 403, 'Warehouse access denied.');
        }

        $report = $this->profitabilityService->getBatchProfitability($batch, $userWarehouseId, $isRestricted);
        $batch = $report['batch'];

        return view('backend.import_batch.profitability', compact('report', 'batch'));
    }

    public function destroy($id)
    {
        $batch = ImportBatch::findOrFail($id);
        $this->warehouseAccess->authorizeWarehouse((int)$batch->warehouse_id, request()->user());

        try {
            $this->batchService->assertNotLocked($batch);

            DB::transaction(function () use ($batch) {
                Purchase::where('import_batch_id', $batch->id)->update(['import_batch_id' => null]);
                $batch->stockLayers()->delete();
                $batch->costs()->delete();
                $batch->delete();
            });

            return redirect()->route('import-batches.index')
                ->with('message', "Import batch deleted successfully.");
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        } catch (\Throwable $e) {
            return redirect()->back()->with('not_permitted', $e->getMessage());
        }
    }

    public function getPurchasesByWarehouse($warehouseId)
    {
        $this->warehouseAccess->authorizeWarehouse((int) $warehouseId, request()->user());
        $purchases = Purchase::where('warehouse_id', $warehouseId)
            ->whereNull('import_batch_id')
            ->with(['supplier', 'currency'])
            ->latest()
            ->get();

        return response()->json($purchases);
    }
}
