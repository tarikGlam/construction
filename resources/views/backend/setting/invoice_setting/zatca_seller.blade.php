<section style="margin:8px 0;overflow-wrap:anywhere" data-zatca-fiscal-header>
    @if ($zatcaInvoice['is_test'])
        <div style="border:2px solid #a76500;padding:7px;margin-bottom:8px;font-weight:bold">
            {{ strtoupper($zatcaInvoice['environment']) }} — TEST ONLY / للاختبار فقط<br>
            Not a real tax invoice
        </div>
    @endif
    <h2 style="margin:0 0 5px">{{ $zatcaInvoice['seller_name'] }}</h2>
    <div>Address / العنوان: {{ $zatcaInvoice['seller_address'] }}</div>
    <div>VAT No. / الرقم الضريبي: {{ $zatcaInvoice['seller_vat'] }}</div>
    <div>Registration No.: {{ $zatcaInvoice['seller_registration'] }}</div>
</section>
