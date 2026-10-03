<span style="display:block;margin:8px 0;overflow-wrap:anywhere" data-zatca-fiscal-buyer>
    @if (!empty($zatcaInvoice['buyer']))
        <strong>Customer / العميل: {{ $zatcaInvoice['buyer']['name'] }}</strong><br>
        Address / العنوان: {{ $zatcaInvoice['buyer']['address'] }}<br>
        @if ($zatcaInvoice['buyer']['vat'] !== '')
            VAT No. / الرقم الضريبي: {{ $zatcaInvoice['buyer']['vat'] }}<br>
        @endif
        @if ($zatcaInvoice['buyer']['registration'] !== '')
            Registration No.: {{ $zatcaInvoice['buyer']['registration'] }}<br>
        @endif
    @else
        Customer / العميل: Walk-in / individual customer
    @endif
</span>
