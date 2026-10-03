@php($zatcaSalePreviewUrl = app(\App\Services\ZatcaIntegrationService::class)->salePreviewUrl())
@if($zatcaSalePreviewUrl)
    <div class="alert alert-info m-3" data-zatca-sale-preview="{{ $zatcaSalePreviewUrl }}" role="region" aria-label="ZATCA checkout review">
        <strong>ZATCA Phase 2 — totals review</strong>
        <p class="mb-2">Before completing any Phase 2 sale, review the server-calculated VAT and payable. Charges include VAT and need an explicit tax mapping; 0% VAT products need their correct ZATCA tax-category mapping. Review again after changing the sale. Drafts do not need a review.</p>
        <p class="mb-2 text-danger"><strong>Order Tax:</strong> leave this at “No Tax” for Phase 2. SalePro adds it on top of product VAT, and this extra tax is not yet mapped into a fiscal invoice. A completed sale with Order Tax will be rejected before saving.</p>
        <button type="button" class="btn btn-outline-primary btn-sm" data-zatca-review>Review ZATCA totals</button>
        <div class="mt-2" data-zatca-result role="status" aria-live="polite"></div>
        <input type="hidden" name="zatca_sale_preview" value="">
    </div>
    @once
        <script src="{{ asset('js/zatca-sale-preview.js') }}?v=20260926-modal" defer></script>
    @endonce
@endif
