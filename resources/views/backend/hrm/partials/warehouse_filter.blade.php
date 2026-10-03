@if(isset($lims_warehouse_list) && ($lims_warehouse_list->count() > 1 || app(\App\Services\WarehouseAccessService::class)->isGlobal()))
    <form method="GET" action="{{ url()->current() }}" class="form-inline mb-3">
        <label class="mr-2" for="hrm-warehouse-filter">{{ __('db.Warehouse') }}</label>
        <select id="hrm-warehouse-filter" name="warehouse_id" class="form-control selectpicker" onchange="this.form.submit()">
            @if(app(\App\Services\WarehouseAccessService::class)->isGlobal())
                <option value="">{{ __('db.All Warehouse') }}</option>
                @if($supports_global_warehouse_filter ?? false)
                    <option value="global" @selected(request('warehouse_id') === 'global')>{{ __('Global') }}</option>
                @endif
            @endif
            @foreach($lims_warehouse_list as $warehouse)
                <option value="{{ $warehouse->id }}" @selected((string) request('warehouse_id') === (string) $warehouse->id)>
                    {{ $warehouse->name }}
                </option>
            @endforeach
        </select>
    </form>
@endif
