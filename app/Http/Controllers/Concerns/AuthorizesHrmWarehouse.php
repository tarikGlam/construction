<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use App\Models\Warehouse;
use App\Services\WarehouseAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait AuthorizesHrmWarehouse
{
    protected function warehouseAccess(): WarehouseAccessService
    {
        return app(WarehouseAccessService::class);
    }

    protected function authorizedWarehouses(): Collection
    {
        return Warehouse::where('is_active', true)->orderBy('name')->get();
    }

    protected function authorizedWarehouseId(Request $request, string $field = 'warehouse_id'): int
    {
        $request->validate([
            $field => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
        ]);

        $warehouseId = (int) $request->input($field);
        $this->warehouseAccess()->authorizeWarehouse($warehouseId);

        return $warehouseId;
    }

    protected function applyWarehouseFilter(Builder $query, Request $request, string $column = 'warehouse_id'): Builder
    {
        if ($request->filled('warehouse_id')) {
            $warehouseId = $this->authorizedWarehouseId($request);
            $query->where($column, $warehouseId);
        }

        return $query;
    }

    protected function authorizedEmployee(int $employeeId, ?int $warehouseId = null): Employee
    {
        $employee = Employee::whereKey($employeeId)->where('is_active', true)->firstOrFail();
        if ($warehouseId !== null && (int) $employee->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages([
                'employee_id' => __('db.Employee does not belong to the selected warehouse'),
            ]);
        }

        return $employee;
    }
}
