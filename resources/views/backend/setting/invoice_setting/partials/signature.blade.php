@php
    $layout = $layout ?? 'a4';
    $invoice_settings = $invoice_settings ?? $invoiceSetting ?? $invoice ?? null;
    $signaturesEnabled = isset($invoice_settings) && method_exists($invoice_settings, 'hasSignaturesEnabled') && $invoice_settings->hasSignaturesEnabled();
@endphp

@if ($signaturesEnabled)
    @php
        $showPrepared = $invoice_settings->showPreparedBy();
        $showAuthorized = $invoice_settings->showAuthorizedBy();
        $showCustomer = $invoice_settings->showCustomerSignature();

        $activeCount = ($showPrepared ? 1 : 0) + ($showAuthorized ? 1 : 0) + ($showCustomer ? 1 : 0);
        $resolvedPreparedName = $preparedByName ?? (!empty($lims_sale_data->user->name) ? $lims_sale_data->user->name : (!empty($lims_bill_by['name']) ? $lims_bill_by['name'] : ''));
    @endphp

    @if ($activeCount > 0)
        @if ($layout === '80mm')
            {{-- 80mm Thermal Receipt Signature Section (compact, clean stacked) --}}
            <div class="invoice-signature-section thermal-signature" style="margin-top: 16px; margin-bottom: 10px; width: 100%; page-break-inside: avoid; font-family: inherit;">
                <table style="width: 100%; border-collapse: collapse; border: none;">
                    <tbody>
                        @if ($showPrepared)
                            <tr>
                                <td style="text-align: center; padding: 6px 4px;">
                                    @if (!empty($resolvedPreparedName))
                                        <div style="font-size: 11px; margin-bottom: 2px; color: #444;">{{ $resolvedPreparedName }}</div>
                                    @endif
                                    <div style="border-top: 1px dashed #333; width: 80%; margin: 0 auto 3px auto;"></div>
                                    <span style="font-size: 11px; font-weight: bold;">{{ $invoice_settings->getPreparedByLabel() }}</span>
                                </td>
                            </tr>
                        @endif
                        @if ($showAuthorized)
                            <tr>
                                <td style="text-align: center; padding: 10px 4px 6px 4px;">
                                    <div style="border-top: 1px dashed #333; width: 80%; margin: 12px auto 3px auto;"></div>
                                    <span style="font-size: 11px; font-weight: bold;">{{ $invoice_settings->getAuthorizedByLabel() }}</span>
                                </td>
                            </tr>
                        @endif
                        @if ($showCustomer)
                            <tr>
                                <td style="text-align: center; padding: 10px 4px 6px 4px;">
                                    <div style="border-top: 1px dashed #333; width: 80%; margin: 12px auto 3px auto;"></div>
                                    <span style="font-size: 11px; font-weight: bold;">{{ $invoice_settings->getCustomerSignatureLabel() }}</span>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        @else
            {{-- A4 / Modal Full Width Signature Section --}}
            @php
                $colWidth = round(100 / $activeCount, 1) . '%';
            @endphp
            <div class="invoice-signature-section a4-signature" style="margin-top: 32px; margin-bottom: 20px; width: 100%; page-break-inside: avoid; clear: both;">
                <table style="width: 100%; border-collapse: collapse; border: none; margin: 0; padding: 0;">
                    <tbody>
                        <tr>
                            @if ($showPrepared)
                                <td style="width: {{ $colWidth }}; text-align: center; vertical-align: bottom; padding: 0 15px;">
                                    @if (!empty($resolvedPreparedName))
                                        <div class="signature-name" style="font-size: 13px; font-weight: 600; margin-bottom: 4px; color: #222;">
                                            {{ $resolvedPreparedName }}
                                        </div>
                                    @else
                                        <div style="height: 18px;"></div>
                                    @endif
                                    <div class="signature-line" style="border-top: 1px solid #333; width: 100%; margin: 0 auto 6px auto;"></div>
                                    <div class="signature-label" style="font-size: 12px; font-weight: bold; color: #333; word-break: break-word;">
                                        {{ $invoice_settings->getPreparedByLabel() }}
                                    </div>
                                </td>
                            @endif

                            @if ($showAuthorized)
                                <td style="width: {{ $colWidth }}; text-align: center; vertical-align: bottom; padding: 0 15px;">
                                    <div style="height: 18px;"></div>
                                    <div class="signature-line" style="border-top: 1px solid #333; width: 100%; margin: 0 auto 6px auto;"></div>
                                    <div class="signature-label" style="font-size: 12px; font-weight: bold; color: #333; word-break: break-word;">
                                        {{ $invoice_settings->getAuthorizedByLabel() }}
                                    </div>
                                </td>
                            @endif

                            @if ($showCustomer)
                                <td style="width: {{ $colWidth }}; text-align: center; vertical-align: bottom; padding: 0 15px;">
                                    <div style="height: 18px;"></div>
                                    <div class="signature-line" style="border-top: 1px solid #333; width: 100%; margin: 0 auto 6px auto;"></div>
                                    <div class="signature-label" style="font-size: 12px; font-weight: bold; color: #333; word-break: break-word;">
                                        {{ $invoice_settings->getCustomerSignatureLabel() }}
                                    </div>
                                </td>
                            @endif
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endif
