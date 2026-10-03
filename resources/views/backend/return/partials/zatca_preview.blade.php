// The server recomputes all amounts on save; this preview grants no authority.
var zatcaReturnPreview = (function () {
    var url = @json($zatcaReturnPreviewUrl);
    if (!url) { return null; }
    var request = null, timer = null, generation = 0, readyFingerprint = null;
    var roundingRequired = false, roundingExpires = 0;
    var $form = $('.sale-return-form');
    var $status = $('#zatca-return-preview-status');
    var messages = {
        select: @json(__('Select return quantities to preview the discounted credit.')),
        loading: @json(__('Checking original invoice amounts and previous returns…')),
        ready: @json(__('Fiscal preview ready. Original discounts are already included; do not deduct them again.')),
        failed: @json(__('Fiscal preview could not be verified. Refresh the page or ask your administrator.')),
        shipping: @json(__('Shipping')),
        service: @json(__('Service'))
    };
    function clearRounding() {
        roundingRequired = false;
        roundingExpires = 0;
        $('#zatca-return-rounding').prop('hidden', true);
        $('#zatca-return-rounding-details').text('');
        $('#zatca-return-rounding-accept').prop('checked', false);
        $('#zatca-return-rounding-token').val('');
    }
    function selection() {
        var data = { product_sale_id: [], qty: [] };
        $('.return-line-selector:checked').each(function () {
            var $row = $(this).closest('tr');
            data.product_sale_id.push(String($row.find('[name="product_sale_id[]"]').val()));
            data.qty.push(String($row.find('.qty').val()));
        });
        var charges = {};
        $('.zatca-charge-refund').each(function () {
            charges[String($(this).attr('data-source'))] = String($(this).val());
        });
        if (Object.keys(charges).length) { data.zatca_charge_refunds = charges; }
        return data;
    }
    function fingerprint() { return JSON.stringify(selection()); }
    function message(text, failed) {
        $status.toggleClass('alert-danger', !!failed).toggleClass('alert-info', !failed).text(text);
    }
    function clearTotals() {
        $form.find('[name="total_qty"], [name="total_tax"], [name="total_price"], [name="grand_total"], [name="item"], [name="change_sale_status"]').val('0');
        $('#subtotal, #grand_total, #item').text('—');
        $('#zatca-charge-refund-summary').text('');
    }
    // These credits already contain their share of the original discount and VAT.
    $form.find('[name="order_tax_rate"]').val('0').prop('disabled', true);
    $form.find('[name="total_sale_discount"]').val('0').prop('readonly', true);
    $form.find('[name="order_tax"], [name="total_discount"]').val('0.00');
    $('#order_discount, #order_tax').text('0.00');
    function refresh() {
        generation++;
        var current = generation;
        readyFingerprint = null;
        $('#submit-button').prop('disabled', true);
        clearRounding();
        clearTimeout(timer);
        if (request) { request.abort(); request = null; }
        clearTotals();
        var data = selection(), expected = JSON.stringify(data);
        if (!data.product_sale_id.length) { message(messages.select); return; }
        message(messages.loading);
        timer = setTimeout(function () {
            request = $.ajax({
                url: url, method: 'POST', dataType: 'json', timeout: 30000,
                headers: { 'Accept': 'application/json' },
                data: Object.assign({ _token: $form.find('[name="_token"]').val() }, data)
            }).done(function (result) {
                if (current !== generation || expected !== fingerprint()) { return; }
                if (!result || !Array.isArray(result.lines) || result.lines.length !== data.product_sale_id.length || !result.totals) {
                    message(messages.failed, true); return;
                }
                var byId = {};
                result.lines.forEach(function (line) { byId[String(line.source_line_id)] = line; });
                if (!data.product_sale_id.every(function (id) { return !!byId[id]; })) {
                    message(messages.failed, true); return;
                }
                $('.return-line-selector:checked').each(function () {
                    var $row = $(this).closest('tr');
                    var line = byId[String($row.find('[name="product_sale_id[]"]').val())];
                    $row.find('input.net_unit_price').val(line.unit_price);
                    $row.find('td.net_unit_price').text(line.unit_price);
                    $row.find('.tax-rate').val(line.vat_rate);
                    $row.find('.tax-value').val(line.vat_amount);
                    $row.find('td.tax').text(line.vat_amount);
                    $row.find('.subtotal-value').val(line.line_total);
                    $row.find('.sub-total').text(line.line_total);
                    $row.find('.discount-value').val('0.00');
                    $row.find('td.discount').text('0.00');
                });
                var total = result.totals.grand_total;
                var money = function (value) { return typeof value === 'string' && /^\d{1,12}\.\d{2}$/.test(value); };
                if (!money(total) || !money(result.totals.vat_total)
                    || (data.zatca_charge_refunds && !Object.keys(data.zatca_charge_refunds).every(function (source) {
                        return money(result.totals[source]);
                    }))) { message(messages.failed, true); return; }
                var adjustments = result.rounding_adjustments === undefined ? [] : result.rounding_adjustments;
                if (!Array.isArray(adjustments)) { message(messages.failed, true); return; }
                if (adjustments.length) {
                    if (typeof result.rounding_consent_token !== 'string' || !result.rounding_consent_token
                        || result.rounding_consent_token.length > 4096
                        || !Number.isInteger(result.rounding_consent_expires_at)
                        || result.rounding_consent_expires_at <= Math.floor(Date.now() / 1000)
                        || !adjustments.every(function (entry) {
                            var item = entry && (entry.kind === undefined || entry.kind === 'item');
                            var charge = entry && entry.kind === 'charge';
                            return entry && ((item && typeof entry.source_line_id === 'string'
                                    && money(entry.ideal_gross))
                                || (charge && (entry.source === 'shipping_cost' || entry.source === 'service_charge')
                                    && money(entry.requested_gross)))
                                && money(entry.gross) && typeof entry.rounding_adjustment === 'string'
                                && /^-?\d{1,12}\.\d{2}$/.test(entry.rounding_adjustment);
                        })) { message(messages.failed, true); return; }
                    roundingRequired = true;
                    roundingExpires = result.rounding_consent_expires_at;
                    $('#zatca-return-rounding-token').val(result.rounding_consent_token);
                    $('#zatca-return-rounding-details').text(adjustments.map(function (entry) {
                        var charge = entry.kind === 'charge';
                        var label = charge ? (entry.source === 'shipping_cost' ? messages.shipping : messages.service)
                            : entry.source_line_id;
                        return label + ': ' + (charge ? 'requested ' : 'proportional ')
                            + (charge ? entry.requested_gross : entry.ideal_gross)
                            + ' SAR → reviewed ' + entry.gross + ' SAR (adjustment '
                            + entry.rounding_adjustment + ' SAR)';
                    }).join('; '));
                    $('#zatca-return-rounding').prop('hidden', false);
                }
                $form.find('[name="total_qty"]').val(result.total_qty);
                $form.find('[name="total_tax"]').val(result.totals.vat_total);
                $form.find('[name="total_price"], [name="grand_total"]').val(total);
                $form.find('[name="item"]').val(result.lines.length);
                $form.find('[name="change_sale_status"]').val(result.change_sale_status);
                $('#item').text(result.lines.length + '(' + result.total_qty + ')');
                $('#subtotal, #grand_total').text(total);
                if (data.zatca_charge_refunds) {
                    $('#zatca-charge-refund-summary').text(@json(__('Charge refunds included in this credit')) + ': '
                        + Object.keys(data.zatca_charge_refunds).map(function (source) {
                            return (source === 'shipping_cost' ? messages.shipping : messages.service) + ' ' + result.totals[source] + ' SAR';
                        }).join(' + '));
                }
                $('#refund_amount').attr('max', total);
                if (Number($('#refund_amount').val()) > Number(total)) { $('#refund_amount').val(total); }
                readyFingerprint = expected;
                $('#submit-button').prop('disabled', roundingRequired);
                message(messages.ready);
            }).fail(function (xhr, status) {
                if (status === 'abort' || current !== generation) { return; }
                var errors = xhr.responseJSON && xhr.responseJSON.errors;
                var first = errors && Object.values(errors)[0];
                message(Array.isArray(first) ? first[0] : messages.failed, true);
            });
        }, 250);
    }
    $form.on('input change', '.zatca-charge-refund', refresh);
    $form.on('change', '#zatca-return-rounding-accept', function () {
        $('#submit-button').prop('disabled', !canSubmit());
    });
    function canSubmit() {
        return readyFingerprint !== null && readyFingerprint === fingerprint()
            && (!roundingRequired || ($('#zatca-return-rounding-accept').prop('checked')
                && $('#zatca-return-rounding-token').val()
                && roundingExpires > Math.floor(Date.now() / 1000)));
    }
    return { refresh: refresh, canSubmit: function () {
        return !!canSubmit();
    }, submitting: function () {
        readyFingerprint = null;
        clearRounding();
        $('#submit-button').prop('disabled', true);
    } };
})();
