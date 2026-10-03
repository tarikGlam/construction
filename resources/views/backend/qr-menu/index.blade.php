@extends('backend.layout.main') @section('content')

@php
    $qrRequestFailed = __('db.Unable to complete the QR request.');
    if ($qrRequestFailed === 'db.Unable to complete the QR request.') {
        $qrRequestFailed = 'Unable to complete the QR request.';
    }
@endphp

<div class="container-fluid mt-5">

   <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" href="#qr-code" role="tab"
                data-toggle="tab">{{ __('db.QR Code') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#settings" role="tab"
                data-toggle="tab">{{ __('db.catalogue settings') }}</a>
        </li>
    </ul>

    <div class="tab-content">
        <div role="tabpanel" class="tab-pane fade show active" id="qr-code">

            @include('backend.qr-menu.includes')

        </div>
        <div role="tabpanel" class="tab-pane fade" id="settings">
            <div class="card p-4 mb-4">
                <form id="qr-settings-form" method="POST">
                    @csrf
                    <label>{{ __('db.out_of_stock_products') }}</label>
                    <div>
                        <label><input type="radio" name="show_stock_out_product" value="1" {{ $qr_catelog_setting->show_stock_out_product == 1 ? 'checked' : '' }}> {{ __('db.show') }}</label>
                        <label class="ml-3"><input type="radio" name="show_stock_out_product" value="0" {{ $qr_catelog_setting->show_stock_out_product == 0 ? 'checked' : '' }}> {{ __('db.hide') }}</label>
                    </div>

                    <button type="submit" class="btn btn-success mt-3">{{ __('db.Save') }}</button>
                </form>

                @push('scripts')
                <script>
                    $(document).on('submit', '#qr-settings-form', function(event) {
                        event.preventDefault();
                        let stock_status = $('input[name="show_stock_out_product"]:checked').val();
                        $.ajax({
                            url: "{{ route('qr.saveSettings') }}",
                            method: 'POST',
                            data: $(this).serialize(),
                            success: function(res) {
                                if (res.success) {
                                    window.saleProToast(res.message, 'success');
                                }
                            },
                            error: function(xhr) {
                                window.saleProToast(
                                    xhr.responseJSON?.message || @json($qrRequestFailed),
                                    'error'
                                );
                            }
                        });
                    });
                </script>
                @endpush

                {{-- <!-- WHATSAPP -->
                <div class="card p-4">

                    <div class="form-check mb-2">
                        <input type="checkbox" id="enable_whatsapp" checked>
                        <label>{{ __('db.enable_whatsapp_ordering') }}</label>
                    </div>

                    <div class="form-group">
                        <label>{{ __('db.WhatsApp Number') }}</label>
                        <input type="text" id="whatsapp_number" class="form-control" placeholder="e.g. +1234567890">
                    </div>

                    <button class="btn btn-success mt-2" onclick="saveWhatsapp()">{{ __('db.Save') }}</button>
                </div> --}}
            </div>
        </div>
    </div>

</div>


@endsection
