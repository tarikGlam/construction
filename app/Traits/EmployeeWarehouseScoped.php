<?php

namespace App\Traits;

use App\Services\WarehouseAccessService;
use Illuminate\Database\Eloquent\Builder;

trait EmployeeWarehouseScoped
{
    protected static function bootEmployeeWarehouseScoped(): void
    {
        static::addGlobalScope('authorized_warehouse', function (Builder $builder): void {
            $access = app(WarehouseAccessService::class);

            if ($access->isPortalIdentity()) {
                $builder->whereRaw('1 = 0');
                return;
            }

            if ($access->isRestricted()) {
                $warehouseId = $access->warehouseId();
                if (!$warehouseId) {
                    $builder->whereRaw('1 = 0');
                    return;
                }

                $builder->whereHas('employee', function (Builder $employeeQuery) use ($warehouseId): void {
                    $employeeQuery->withoutGlobalScope('authorized_warehouse')
                        ->where('warehouse_id', $warehouseId);
                });
            }
        });
    }
}
