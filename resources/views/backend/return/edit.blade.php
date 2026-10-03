@extends('backend.layout.main') @section('content')
    <x-error-message key="not_permitted" />

    <section class="forms">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <h4>{{ __('db.Update Return') }}</h4>
                        </div>
                        <div class="card-body">
                            <p class="italic">
                                <small>{{ __('db.The field labels marked with are required input fields') }}.</small>
                            </p>
                            <form class="sale-return-form" action="{{ route('return-sale.update', $lims_return_data->id) }}" method="post" enctype="multipart/form-data" id="payment-form">
                                @csrf
                                @method('PUT')
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>{{ __('db.reference') }}</label>
                                                <p><strong>{{ $lims_return_data->reference_no }}</strong></p>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>{{ __('db.Sale Reference') }}</label>
                                                <p><strong>{{ $lims_return_data->sale->reference_no }}</strong></p>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="warehouse_id" value="{{ $lims_return_data->warehouse_id ?? 1 }}">
                                    <input type="hidden" name="customer_id" value="{{ $lims_return_data->customer_id ?? 1 }}">

                                    <div class="row mt-5">
                                        <div class="col-md-12">
                                            <h5>{{ __('db.Order Table') }} *</h5>
                                            <div class="table-responsive mt-3">
                                                <table id="myTable" class="table table-hover order-list">
                                                    <thead>
                                                        <tr>
                                                            <th class="text-center align-middle">
                                                                <div class="form-check m-0 d-inline-flex align-items-center">
                                                                    <input type="checkbox" class="form-check-input position-static m-0" id="select-all-return-lines"
                                                                        aria-label="{{ __('db.Select All') }}" checked>
                                                                    <label class="sr-only" for="select-all-return-lines">{{ __('db.Select All') }}</label>
                                                                </div>
                                                                <span class="ml-1">{{ __('db.Return') }}</span>
                                                            </th>
                                                            <th>{{ __('db.name') }}</th>
                                                            <th>{{ __('db.Code') }}</th>
                                                            <th>{{ __('db.Batch No') }}</th>
                                                            <th>{{ __('db.sale qty') }}  <x-info title="Actual Sale Quantity"/> </th>
                                                            <th>{{ __('db.return_quantity') }}  <x-info title="Current Return Quantity"/> </th>
                                                            <th>{{ __('db.Net Unit Price') }}  <x-info title="Product Price - Unit Discount = Unit Price"/> </th>
                                                            <th>{{ __('db.Discount') }} <x-info title="Total unit discount / Total Qty = Unit Dicount"/> </th>
                                                            <th>{{ __('db.Tax') }}</th>
                                                            <th>{{ __('db.Subtotal') }} <x-info title="Qty * Unit Price = SubTotal"/> </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($lims_product_return_data as $key => $product_return)
                                                            <tr class="return-line table-active">
                                                                <?php
                                                                $product_data = DB::table('products')->find($product_return->product_id);

                                                                // Handle variant
                                                                if ($product_return->variant_id) {
                                                                    $product_variant_data = \App\Models\ProductVariant::select('id', 'item_code')->FindExactProduct($product_data->id, $product_return->variant_id)->first();
                                                                    if ($product_variant_data) {
                                                                        $product_variant_id = $product_variant_data->id;
                                                                        $product_data->code = $product_variant_data->item_code;
                                                                    } else {
                                                                        $product_variant_id = null;
                                                                        $product_data->code = $product_data->code ?? 'N/A';
                                                                    }
                                                                } else {
                                                                    $product_variant_id = null;
                                                                }

                                                                $qty = (float) $product_return->qty;
                                                                $hasValidQuantity = $qty > 0;

                                                                // Calculate product price safely
                                                                if ($product_data->tax_method == 1) {
                                                                    $product_price = $product_return->net_unit_price + ($hasValidQuantity ? $product_return->discount / $qty : 0);
                                                                } elseif ($product_data->tax_method == 2) {
                                                                    $product_price = ($hasValidQuantity ? $product_return->total / $qty : $product_return->net_unit_price) + ($hasValidQuantity ? $product_return->discount / $qty : 0);
                                                                } else {
                                                                    $product_price = $product_return->net_unit_price;
                                                                }

                                                                // Tax info
                                                                $tax = DB::table('taxes')->where('rate', $product_return->tax_rate)->first();

                                                                // Units
                                                                $unit_name = [];
                                                                $unit_operator = [];
                                                                $unit_operation_value = [];

                                                                if ($product_data->type == 'standard') {
                                                                    $units = DB::table('units')->where('base_unit', $product_data->unit_id)->orWhere('id', $product_data->unit_id)->get();

                                                                    foreach ($units as $unit) {
                                                                        if ($product_return->sale_unit_id == $unit->id) {
                                                                            array_unshift($unit_name, $unit->unit_name);
                                                                            array_unshift($unit_operator, $unit->operator);
                                                                            array_unshift($unit_operation_value, $unit->operation_value);
                                                                        } else {
                                                                            $unit_name[] = $unit->unit_name;
                                                                            $unit_operator[] = $unit->operator;
                                                                            $unit_operation_value[] = $unit->operation_value;
                                                                        }
                                                                    }

                                                                    // Adjust price based on unit operator safely
                                                                    if (isset($unit_operator[0]) && isset($unit_operation_value[0])) {
                                                                        if ($unit_operator[0] == '*' && $unit_operation_value[0] != 0) {
                                                                            $product_price = $product_price / $unit_operation_value[0];
                                                                        } elseif ($unit_operator[0] == '/') {
                                                                            $product_price = $product_price * $unit_operation_value[0];
                                                                        }
                                                                    }
                                                                } else {
                                                                    $unit_name[] = 'n/a';
                                                                    $unit_operator[] = 'n/a';
                                                                    $unit_operation_value[] = 'n/a';
                                                                }

                                                                $temp_unit_name = $unit_name = implode(',', $unit_name) . ',';
                                                                $temp_unit_operator = $unit_operator = implode(',', $unit_operator) . ',';
                                                                $temp_unit_operation_value = $unit_operation_value = implode(',', $unit_operation_value) . ',';

                                                                $product_batch_data = \App\Models\ProductBatch::select('batch_no')->find($product_return->product_batch_id);
                                                                $unit = DB::table('units')->where('id', $lims_product_return_data[$key]->sale_unit_id)->first();
                                                                $sale_unit_name = $unit->unit_name ?? '';
                                                                ?>
                                                                <td class="text-center align-middle">
                                                                    <div class="form-check m-0 d-inline-flex">
                                                                        <input type="checkbox" class="form-check-input position-static m-0 return-line-selector"
                                                                            id="return-line-{{ $product_return->id }}" name="selected_items[]"
                                                                            value="{{ $returnSourceLineIds[$product_return->id] ?? '' }}" checked
                                                                            aria-label="{{ __('db.Return') }} {{ $product_data->name }}"
                                                                            @disabled(empty($returnSourceLineIds[$product_return->id]))>
                                                                        <label class="sr-only" for="return-line-{{ $product_return->id }}">
                                                                            {{ __('db.Return') }} {{ $product_data->name }}
                                                                        </label>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    {{ $product_data->name }}
                                                                </td>
                                                                <td>{{ $product_data->code }}</td>
                                                                @if ($product_batch_data)
                                                                    <td>
                                                                        <input type="hidden" class="product-batch-id"
                                                                            name="product_batch_id[]"
                                                                            value="{{ $product_return->product_batch_id }}">
                                                                        <input type="text" class="form-control batch-no"
                                                                            name="batch_no[]"
                                                                            value="{{ $product_batch_data->batch_no }}"
                                                                            required />
                                                                    </td>
                                                                @else
                                                                    <td>
                                                                        <input type="hidden" class="product-batch-id"
                                                                            name="product_batch_id[]" value="">
                                                                        <input type="text" class="form-control batch-no"
                                                                            name="batch_no[]" value="" disabled data-return-readonly />
                                                                    </td>
                                                                @endif
                                                                <td>
                                                                    <input type="text" name="actual_qty[]"
                                                                        class="form-control actual_qty" disabled
                                                                        data-return-readonly
                                                                        data-actual_qty="{{ $lims_product_return_data[$key]->qty ?? 0 }}"
                                                                        value="{{ $lims_product_return_data[$key]->qty ?? '' }}">
                                                                </td>
                                                                <td>
                                                                    <input type="number" class="form-control qty sale_qty"
                                                                        name="qty[]" value="{{ $product_return->qty }}"
                                                                        data-sale_qty="{{ $product_return->qty }}" required
                                                                        step="any" min="0.000001"
                                                                        max="{{ $lims_product_return_data[$key]->qty ?? 0 }}" />
                                                                </td>
                                                                <td class="net_unit_price">
                                                                    {{ number_format((float) $product_return->net_unit_price, gen_setting()->decimal, '.', '') }}
                                                                </td>
                                                                <td class="discount"
                                                                    data-unit_discount="{{ $hasValidQuantity ? $product_return->discount / $qty : 0 }}">
                                                                    {{ number_format((float) $product_return->discount * $product_return->qty, gen_setting()->decimal, '.', '') }}
                                                                </td>
                                                                <td class="tax">
                                                                    {{ number_format((float) $product_return->tax, gen_setting()->decimal, '.', '') }}
                                                                </td>
                                                                <td class="sub-total">
                                                                    {{ number_format((float) $product_return->total, gen_setting()->decimal, '.', '') }}
                                                                </td>
                                                                <input type="hidden" class="product-code"
                                                                    name="product_code[]"
                                                                    value="{{ $product_data->code }}" />
                                                                <input type="hidden" name="product_sale_id[]"
                                                                    value="{{ $returnSourceLineIds[$product_return->id] ?? '' }}" />
                                                                <input type="hidden" name="product_id[]" class="product-id"
                                                                    value="{{ $product_data->id }}" />
                                                                <input type="hidden" name="product_variant_id[]"
                                                                    value="{{ $product_variant_id }}" />
                                                                <input type="hidden" class="product-price"
                                                                    name="product_price[]" value="{{ $product_price }}" />
                                                                <input type="hidden" class="sale-unit" name="sale_unit[]"
                                                                    value="{{ $sale_unit_name }}" />
                                                                <input type="hidden" class="sale-unit-operator"
                                                                    value="{{ $unit_operator }}" />
                                                                <input type="hidden" class="sale-unit-operation-value"
                                                                    value="{{ $unit_operation_value }}" />
                                                                <input type="hidden" class="net_unit_price"
                                                                    name="net_unit_price[]"
                                                                    value="{{ $product_return->net_unit_price }}" />
                                                                <input type="hidden" class="discount-value"
                                                                    name="discount[]"
                                                                    value="{{ $product_return->discount }}" />
                                                                <input type="hidden" class="tax-rate" name="tax_rate[]"
                                                                    value="{{ $product_return->tax_rate }}" />
                                                                @if ($tax)
                                                                    <input type="hidden" class="tax-name"
                                                                        value="{{ $tax->name }}" />
                                                                @else
                                                                    <input type="hidden" class="tax-name"
                                                                        value="No Tax" />
                                                                @endif
                                                                <input type="hidden" class="tax-method"
                                                                    value="{{ $product_data->tax_method }}" />
                                                                <input type="hidden" class="tax-value" name="tax[]"
                                                                    value="{{ $product_return->tax }}" />
                                                                <input type="hidden" class="subtotal-value"
                                                                    name="subtotal[]"
                                                                    value="{{ $product_return->total }}" />
                                                                <input type="hidden" class="imei-number"
                                                                    name="imei_number[]"
                                                                    value="{{ $product_return->imei_number }}" />
                                                            </tr>
                                                        @endforeach
                                                    </tbody>

                                                    <tfoot class="tfoot active">
                                                        <th colspan="4">{{ __('db.Total') }}</th>
                                                        <th></th>
                                                        <th id="total-qty">{{ $lims_return_data->total_qty }}</th>
                                                        <th></th>
                                                        <th id="total-discount">
                                                            {{ number_format((float) $lims_return_data->total_discount, gen_setting()->decimal, '.', '') }}
                                                        </th>
                                                        <th id="total-tax">
                                                            {{ number_format((float) $lims_return_data->total_tax, gen_setting()->decimal, '.', '') }}
                                                        </th>
                                                        <th id="total">
                                                            {{ number_format((float) $lims_return_data->total_price, gen_setting()->decimal, '.', '') }}
                                                        </th>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <input type="hidden" name="total_qty"
                                                    value="{{ $lims_return_data->total_qty }}" />
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <input type="hidden" name="total_discount" class="total_discount"
                                                    value="{{ $lims_return_data->total_discount }}" />
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <input type="hidden" name="total_tax"
                                                    value="{{ $lims_return_data->total_tax }}" />
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <input type="hidden" name="total_price"
                                                    value="{{ $lims_return_data->total_price }}" />
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <input type="hidden" name="item"
                                                    value="{{ $lims_return_data->item }}" />
                                                <input type="hidden" name="order_tax"
                                                    value="{{ $lims_return_data->order_tax }}" />
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <input type="hidden" name="grand_total"
                                                    value="{{ $lims_return_data->grand_total }}" />
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-3">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <input type="hidden" name="order_tax_rate_hidden"
                                                    value="{{ $lims_return_data->order_tax_rate }}">
                                                <label>{{ __('db.Order Tax') }}</label>
                                                <select class="form-control" name="order_tax_rate">
                                                    <option value="0">No Tax</option>
                                                    @foreach ($lims_tax_list as $tax)
                                                        <option value="{{ $tax->rate }}">{{ $tax->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>{{ __('db.Attach Document') }}</label>
                                                <i class="ti ti-info-circle" data-toggle="tooltip"
                                                    title="Only jpg, jpeg, png, gif, pdf, csv, docx, xlsx and txt file is supported"></i>
                                                <input type="file" name="document" class="form-control" />
                                                @if ($errors->has('extension'))
                                                    <span>
                                                        <strong>{{ $errors->first('extension') }}</strong>
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>{{ __('db.Return Discount') }}  </label> <x-info title="Actual discount: {{ $lims_return_data->sale->total_discount ?? 0 }}, Previews Return Discount: {{ $lims_return_data->total_discount ?? 0 }}, Available Discount: {{ $lims_return_data->sale->total_discount - $lims_return_data->total_discount }}" />
                                                <input type="number" name="total_sale_discount" id="total_sale_discount"
                                                    class="form-control"
                                                    value="{{ $lims_return_data->total_discount ?? '' }}" data-actual_discount="{{ $lims_return_data->total_discount ?? '' }}" />
                                                @if ($errors->has('extension'))
                                                    <span>
                                                        <strong>{{ $errors->first('extension') }}</strong>
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>{{ __('db.Return Note') }}</label>
                                                <textarea rows="5" class="form-control" name="return_note">{{ $lims_return_data->return_note }}</textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>{{ __('db.Staff Note') }}</label>
                                                <textarea rows="5" class="form-control" name="staff_note">{{ $lims_return_data->staff_note }}</textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <input type="submit" value="{{ __('db.submit') }}" class="btn btn-primary"
                                            id="submit-button">
                                    </div>
                                </div>
                            </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="container-fluid">
            <table class="table table-bordered table-condensed totals">
                <td><strong>{{ __('db.Items') }}</strong>
                    <span class="pull-right"
                        id="item">{{ number_format(0, gen_setting()->decimal, '.', '') }}</span>
                </td>
                <td><strong>{{ __('db.Total') }}</strong>
                    <span class="pull-right"
                        id="subtotal">{{ number_format(0, gen_setting()->decimal, '.', '') }}</span>
                </td>
                <td><strong>{{ __('db.Order Tax') }}</strong>
                    <span class="pull-right"
                        id="order_tax">{{ number_format(0, gen_setting()->decimal, '.', '') }}</span>
                </td>
                <td><strong>{{ __('db.Discount') }} <x-info title="Current Return Qty"/> </strong>
                    <span class="pull-right"
                        id="order_discount" data-actual_discount="{{ $lims_return_data->total_discount  ?? 0 }}">{{ number_format($lims_return_data->total_discount ?? '', gen_setting()->decimal, '.', '') }}<x-info title="Current Return Qty"/> </span>
                </td>

                <td><strong>{{ __('db.grand total') }} <x-info title="(SubTotal + tax) - (Current Return Dscount + Previws return discount) = Grand Total"/> </strong>
                    <span class="pull-right"
                        id="grand_total">{{ number_format(0, gen_setting()->decimal, '.', '') }}</span>
                </td>
            </table>
        </div>
        <!-- add cash register modal -->
        <div id="cash-register-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
            aria-hidden="true" class="modal fade text-left">
            <div role="document" class="modal-dialog">
                <div class="modal-content">
                    <form action="{{ route('cashRegister.store') }}" method="post">
                        @csrf
                    <div class="modal-header">
                        <h5 id="exampleModalLabel" class="modal-title">{{ __('db.Add Cash Register') }}</h5>
                        <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span
                                aria-hidden="true"><i class="ti ti-x"></i></span></button>
                    </div>
                    <div class="modal-body">
                        <p class="italic">
                            <small>{{ __('db.The field labels marked with are required input fields') }}.</small>
                        </p>
                        <div class="row">
                            <div class="col-md-6 form-group warehouse-section">
                                <label>{{ __('db.Warehouse') }} *</strong> </label>
                                <select required name="warehouse_id" class="selectpicker form-control"
                                    data-live-search="true" data-live-search-style="begins" title="Select warehouse...">
                                    @foreach ($lims_warehouse_list as $warehouse)
                                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>{{ __('db.Cash in Hand') }} *</strong> </label>
                                <input type="number" name="cash_in_hand" required class="form-control">
                            </div>
                            <div class="col-md-12 form-group">
                                <button type="submit" class="btn btn-primary">{{ __('db.submit') }}</button>
                            </div>
                        </div>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection
@push('scripts')
<script type="text/javascript">

    @include('backend.return.partials.quantity_validation')

    // Initial setup
    var row_product_price = [];
    var product_unit_discount = [];
    var tax_rate = [];
    var tax_method = [];

    var rownumber = $('table.order-list tbody tr:last').index();

    for (var rowindex = 0; rowindex <= rownumber; rowindex++) {
        var $row = $('table.order-list tbody tr').eq(rowindex);

        var net_unit_price = parseFloat($row.find('.net_unit_price').text()) || 0;
        row_product_price.push(net_unit_price);

        // Always read unit discount from data attribute
        var unit_discount = parseFloat($row.find('.discount').data('unit_discount')) || 0;
        product_unit_discount.push(unit_discount);

        var tax = parseFloat($row.find('.tax').text()) || 0;
        tax_rate.push(tax);

        tax_method.push(1); // Default tax method
    }

    // ✅ Set max discount limit from backend
    var maxDiscount = {{ $lims_return_data->total_discount ?? 0 }};
    $('#total_sale_discount').attr('max', maxDiscount);

    // Prevent discount from exceeding max
    $('#total_sale_discount').on('input', function() {
        var entered = parseFloat($(this).val()) || 0;
        if (entered > maxDiscount) {
            $(this).val(maxDiscount);
            SaleProToast.show("Discount cannot be greater than " + maxDiscount);
        }
        calculateGrandTotal();
    });

    var selectReturnLineMessage = @json(__('db.sale_return_line_required'));

    function setReturnLineState($row, selected) {
        var $selector = $row.find('.return-line-selector');
        selected = Boolean(selected && !$selector.is(':disabled'));
        $selector.prop('checked', selected);
        $row.find(':input').not('.return-line-selector, [data-return-readonly]').prop('disabled', !selected);
        $row.toggleClass('table-light text-muted', !selected).toggleClass('table-active', selected);

        if (!selected) {
            $row.find('.discount, .tax, .sub-total').text((0).toFixed({{ gen_setting()->decimal }}));
        } else {
            $row.find('.qty').trigger('input');
        }
    }

    function updateSelectAllState() {
        var $available = $('.return-line-selector:not(:disabled)');
        var selectedCount = $available.filter(':checked').length;
        $('#select-all-return-lines')
            .prop('checked', $available.length > 0 && selectedCount === $available.length)
            .prop('indeterminate', selectedCount > 0 && selectedCount < $available.length)
            .prop('disabled', $available.length === 0);
    }

    $('#select-all-return-lines').on('change', function() {
        var selected = this.checked;
        $('.return-line-selector:not(:disabled)').each(function() {
            setReturnLineState($(this).closest('tr'), selected);
        });
        updateSelectAllState();
        calculateTotal();
    });

    $('#myTable').on('change', '.return-line-selector', function() {
        setReturnLineState($(this).closest('tr'), this.checked);
        updateSelectAllState();
        calculateTotal();
    });

    // Quantity change
    $("#myTable").on('input', '.qty', function() {
        var $row = $(this).closest('tr');
        var index = $row.index();

        var qty = parseFloat($(this).val()) || 0; // default 0 if blank
        var maxQty = parseFloat($row.find('.actual_qty').data('actual_qty')) || 0;

        if (qty > maxQty) {
            qty = maxQty;
            $(this).val(qty);
            SaleProToast.show("Quantity can't be greater than " + maxQty);
        }

        // Discount
        var discount = product_unit_discount[index] * qty;
        $row.find('.discount').text(discount.toFixed({{ gen_setting()->decimal }}));

        // Net unit price after discount
        var net_price = row_product_price[index];

        // Tax
        var tax = tax_method[index] == 1
            ? net_price * qty * (tax_rate[index] / 100)
            : (net_price * qty * tax_rate[index] / 100);
        $row.find('.tax').text(tax.toFixed({{ gen_setting()->decimal }}));

        // Subtotal
        var subtotal = net_price * qty + tax;
        $row.find('.sub-total').text(subtotal.toFixed({{ gen_setting()->decimal }}));

        // ✅ Update hidden inputs for this row
        updateRowHiddenInputs($row, index, qty, net_price, discount, tax, subtotal);

        calculateTotal();
    });

    calculateTotal();

    // Calculate totals
    function calculateTotal() {
        var total_qty = 0,
            total_discount = 0,
            total_tax = 0,
            total = 0;

        $("table.order-list tbody tr").each(function(i) {
            var $row = $(this);
            if (!$row.find('.return-line-selector').is(':checked')) {
                return;
            }
            var qty = parseFloat($row.find('.qty').val()) || 0;
            total_qty += qty;

            total_discount += parseFloat($row.find('.discount').text()) || 0;
            total_tax += parseFloat($row.find('.tax').text()) || 0;
            total += parseFloat($row.find('.sub-total').text()) || 0;
        });

        $("#total-qty").text(total_qty);
        $("#total-discount").text(total_discount.toFixed({{ gen_setting()->decimal }}));
        $("#total-tax").text(total_tax.toFixed({{ gen_setting()->decimal }}));
        $("#total").text(total.toFixed({{ gen_setting()->decimal }}));
        $('input[name="total_qty"]').val(total_qty);
        $('input[name="total_tax"]').val(total_tax.toFixed({{ gen_setting()->decimal }}));
        $('input[name="total_price"]').val(total.toFixed({{ gen_setting()->decimal }}));
        $('input[name="item"]').val($('.return-line-selector:checked').length);

        calculateGrandTotal();
    }

    // Calculate grand total
    function calculateGrandTotal() {
        var subtotal = parseFloat($('#total').text()) || 0;
        var order_tax_rate = parseFloat($('select[name="order_tax_rate"]').val()) || 0;
        var order_tax = subtotal * (order_tax_rate / 100);

        var sale_discount = parseFloat($('#total_sale_discount').val()) || 0;
        var actual_discount = parseFloat($('#total_sale_discount').data('actual_discount')) || 0;
        var grand_total = (subtotal + order_tax) - (sale_discount + actual_discount);
        var total_qty = $('#total-qty').text() || 0;

        $(".total_discount").val(sale_discount.toFixed({{ gen_setting()->decimal }}));
        $('#order_discount').text(sale_discount) || 0;
        $('#item').text($('.return-line-selector:checked').length + '(' + total_qty + ')');
        $('#subtotal').text(subtotal.toFixed({{ gen_setting()->decimal }}));
        $('#order_tax').text(order_tax.toFixed({{ gen_setting()->decimal }}));
        $('#grand_total').text(grand_total.toFixed({{ gen_setting()->decimal }}));
    }

    // ✅ Update hidden inputs per row
    function updateRowHiddenInputs($row, index, qty, net_price, discount, tax, subtotal) {
        $row.find('.product-price').val(row_product_price[index].toFixed({{ gen_setting()->decimal }}));
        $row.find('.net_unit_price').val(net_price.toFixed({{ gen_setting()->decimal }}));
        $row.find('.discount-value').val(discount.toFixed({{ gen_setting()->decimal }}));
        $row.find('.tax-rate').val(tax_rate[index]);
        $row.find('.tax-value').val(tax.toFixed({{ gen_setting()->decimal }}));
        $row.find('.subtotal-value').val(subtotal.toFixed({{ gen_setting()->decimal }}));
    }

    // Trigger recalculation on discount/order tax change
    $('#total_sale_discount, select[name="order_tax_rate"]').on("change keyup", calculateGrandTotal);

    // Prevent form submission without products
    $('#payment-form').on('submit', function(e) {
        if (!$('.return-line-selector:checked').length) {
            SaleProToast.show(selectReturnLineMessage);
            e.preventDefault();
            return false;
        } else if (!validateSaleReturnQuantities($(this))) {
            e.preventDefault();
            return false;
        } else {
            $("#submit-button").prop('disabled', true);
        }
    });

    $('.return-line').each(function() {
        setReturnLineState($(this), !$(this).find('.return-line-selector').is(':disabled'));
    });
    updateSelectAllState();
    calculateTotal();
</script>
@endpush
