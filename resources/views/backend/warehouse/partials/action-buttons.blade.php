<div class="btn-group">
    <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">{{__('db.action')}}
        <span class="caret"></span>
        <span class="sr-only">Toggle Dropdown</span>
    </button>
    <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
        <li>
            <button type="button" data-id="{{$warehouse->id}}" class="open-EditWarehouseDialog btn btn-link" data-toggle="modal" data-target="#editModal"><i class="ti ti-edit"></i> {{__('db.edit')}}
        </button>
        </li>
        @can('warehouse')
            @if($warehouse->qr_code_id)
                <li>
                    <button type="button" data-qr-id="{{$warehouse->qr_code_id}}" class="btn btn-link btn-view-qr">
                        <i class="fa fa-qrcode"></i> {{ __('db.View QR Code') }}</button>
                </li>
                <li>
                    <a href="{{ route('qr.download', $warehouse->qr_code_id) }}" class="btn btn-link"><i class="ti ti-download"></i> {{ __('db.Download QR') }}</a>
                </li>
                <li>
                    <button type="button" data-qr-id="{{$warehouse->id}}" data-qr-type="warehouse" class="btn btn-link btn-generate-qr"><i class="fa fa-refresh"></i> {{ __('db.Regenerate QR Code') }}</button>
                </li>
            @else
                <li>
                    <button type="button" data-qr-id="{{$warehouse->id}}" data-qr-type="warehouse" class="btn btn-link btn-generate-qr"><i class="fa fa-qrcode"></i> {{ __('db.Generate QR Code') }}</button>
                </li>
            @endif
        @endcan
        <li class="divider"></li>
        <form action="{{ route('warehouse.destroy', $warehouse->id) }}" method="POST">
            @csrf
            @method('DELETE')
            <li>
                <button type="submit" class="btn btn-link" data-confirm-message="{{ __('db.Are you sure want to delete?') }}"><i class="ti ti-trash"></i> {{__('db.delete')}}</button>
            </li>
        </form>
    </ul>
</div>