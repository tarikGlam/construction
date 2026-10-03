<?php

namespace App\Traits;

use App\Services\WarehouseAccessService;
use Illuminate\Database\Eloquent\Builder;

trait HolidayWarehouseScoped
{
    protected static function bootHolidayWarehouseScoped(): void
    {
        static::addGlobalScope('authorized_warehouse', function (Builder $query): void {
            $access = app(WarehouseAccessService::class);
            if ($access->isPortalIdentity()) {
                $query->whereRaw('1 = 0');
                return;
            }

            if ($access->isRestricted()) {
                $warehouseId = $access->warehouseId();
                $query->where(function (Builder $visible) use ($warehouseId): void {
                    $visible->whereNull($visible->getModel()->qualifyColumn('warehouse_id'));
                    if ($warehouseId) {
                        $visible->orWhere($visible->getModel()->qualifyColumn('warehouse_id'), $warehouseId);
                    }
                });
            }
        });

        static::creating(function ($holiday): void {
            $access = app(WarehouseAccessService::class);
            if ($access->isRestricted()) {
                abort_unless($access->warehouseId(), 403, 'A warehouse assignment is required.');
                abort_if((int) $holiday->warehouse_id !== $access->warehouseId(), 403, 'Warehouse access denied.');
            }
        });

        static::updating(function ($holiday): void {
            static::authorizeRestrictedMutation($holiday);
        });

        static::deleting(function ($holiday): void {
            static::authorizeRestrictedMutation($holiday);
        });
    }

    private static function authorizeRestrictedMutation($holiday): void
    {
        $access = app(WarehouseAccessService::class);
        if (!$access->isRestricted()) {
            return;
        }

        $warehouseId = $access->warehouseId();
        abort_if(
            !$warehouseId
            || $holiday->getOriginal('warehouse_id') === null
            || (int) $holiday->getOriginal('warehouse_id') !== $warehouseId
            || ($holiday->isDirty('warehouse_id') && (int) $holiday->warehouse_id !== $warehouseId),
            403,
            'Warehouse access denied.'
        );
    }
}
