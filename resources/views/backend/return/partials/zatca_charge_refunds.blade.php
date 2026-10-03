@if($zatcaChargeOptions !== [])
    <div class="card border mb-3">
        <div class="card-body">
            <h5>{{ __('Original invoice charge refunds') }}</h5>
            <p class="text-muted">{{ __('Enter the amount including VAT to credit for each charge. Leave 0 to keep the charge. Original discounts are already included; the preview checks previous credits and remaining limits.') }}</p>
            <div class="row">
                @foreach($zatcaChargeOptions as $source => $option)
                    <div class="col-md-6 form-group">
                        <label for="zatca-refund-{{ $source }}">{{ __($option['label']) }} (SAR)</label>
                        <input id="zatca-refund-{{ $source }}" type="number" min="0" step="0.01" max="{{ $option['maximum'] }}" required
                            class="form-control zatca-charge-refund" data-source="{{ $source }}" name="zatca_charge_refunds[{{ $source }}]"
                            value="{{ old('zatca_charge_refunds.'.$source, '0.00') }}">
                        <small class="text-muted">{{ __('Original refundable charge after discounts') }}: {{ $option['maximum'] }} SAR</small>
                    </div>
                @endforeach
            </div>
            <div id="zatca-charge-refund-summary" role="status" aria-live="polite"></div>
        </div>
    </div>
@endif
