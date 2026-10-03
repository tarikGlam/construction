<?php

namespace App\Services;

use App\Exceptions\SaleValidationException;
use App\Exceptions\ZatcaPhase2WorkflowUnavailable;
use App\Models\Returns;
use App\Models\GeneralSetting;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Salla\ZATCA\GenerateQrCode;
use Salla\ZATCA\Tags\InvoiceDate;
use Salla\ZATCA\Tags\InvoiceTaxAmount;
use Salla\ZATCA\Tags\InvoiceTotalAmount;
use Salla\ZATCA\Tags\Seller;
use Salla\ZATCA\Tags\TaxNumber;

/**
 * Stable core bridge for the optional ZATCA module.
 *
 * Core SalePro code must never resolve a Modules\ZatcaIntegrationKsa class
 * unless the module physically exists and is enabled.
 */
class ZatcaIntegrationService
{
    private const MODULE = 'zatcaintegrationksa';
    private const MODE_SERVICE = 'Modules\\ZatcaIntegrationKsa\\Services\\ZatcaModeService';

    public function moduleIsAvailable(): bool
    {
        try {
            $access = app(ModuleAccessService::class);

            return $access->isInstalled(self::MODULE) && $access->isCodeEnabled(self::MODULE);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Resolve the optional mode service only after code availability is proven. */
    public function isPhase2Active(): bool
    {
        if (! $this->moduleIsAvailable()) {
            return false;
        }

        try {
            return class_exists(self::MODE_SERVICE) && app(self::MODE_SERVICE)->isPhase2();
        } catch (\Throwable) {
            return false;
        }
    }

    public function processSale(Sale $sale): void
    {
        $this->process('createAndSubmitSale', $sale);
    }

    /** Read the current database, not a shared settings cache or an optional module class. */
    public function isPhase2Configured(): bool
    {
        return (GeneralSetting::query()->latest()->first()->zatca_mode ?? null) === 'phase2';
    }

    public function assertConfiguredPhase2ModuleAvailable(): void
    {
        if ($this->isPhase2Configured() && ! $this->moduleIsAvailable()) {
            throw new SaleValidationException(
                'ZATCA Phase 2 is selected, but the ZatcaIntegrationKsa module is missing or disabled. Restore and enable the module before saving a sale or return.',
                'zatca_mode'
            );
        }
    }

    /** Browser-queued POS receipts are not a verified Phase 2 offline fiscal path. */
    public function validateOfflineReplay(array $data): void
    {
        if ((string) ($data['zatca_offline_replay'] ?? '') !== '1'
            || (int) ($data['sale_status'] ?? 1) === 3
            || ! $this->moduleIsAvailable()) {
            return;
        }
        if ($this->isPhase2Active()) {
            throw new SaleValidationException(
                'A browser-queued sale cannot become a Phase 2 invoice automatically. Keep the local entry for reconciliation and create a reviewed online sale only after checking it was not already invoiced.',
                'zatca_offline_replay'
            );
        }
    }

    /** A reviewed Phase 2 checkout must keep one stable transaction identity. */
    public function validatePhase2RequestIdentity(array $data): void
    {
        if (! $this->moduleIsAvailable()
            || ! in_array((int) ($data['sale_status'] ?? 1), [1, 5], true)
            || ! $this->isPhase2Active()) {
            return;
        }
        $key = $data['idempotency_key'] ?? null;
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key)) {
            throw new SaleValidationException(
                'This Phase 2 sale needs a stable checkout request key. Refresh Add Sale or POS, review ZATCA totals again, then save.',
                'idempotency_key'
            );
        }
    }

    /** Storefront orders and provider callbacks do not use the reviewed fiscal checkout. */
    public function assertStorefrontCheckoutAvailable(): void
    {
        if ($this->isPhase2Configured()) {
            throw new ZatcaPhase2WorkflowUnavailable(
                'Online storefront checkout and payment are not yet available in ZATCA Phase 2. Please create a reviewed SalePro sale instead. No storefront order or payment was processed.'
            );
        }
    }

    /** Exchange rewrites sale totals, payment and stock without fiscal credit/reissue. */
    public function assertExchangeAvailable(?int $saleId = null): void
    {
        if ($this->isPhase2Configured() || ($saleId !== null && $this->isSourceLocked('sale', $saleId))) {
            throw new ZatcaPhase2WorkflowUnavailable(
                'Sale Exchange is not yet available in ZATCA Phase 2. Use Sale Return for an accepted invoice, then create a new reviewed sale for the replacement. No exchange, stock or payment was changed.'
            );
        }
    }

    /** Legacy fulfillment paths may not promote an old sale into an invoice. */
    public function assertExistingSaleCanEnterFiscalStatus(Sale $sale): void
    {
        if (! $this->isPhase2Configured()) {
            return;
        }

        $this->assertConfiguredPhase2ModuleAvailable();
        try {
            $this->qrForSale($sale);
        } catch (RuntimeException $exception) {
            throw new ZatcaPhase2WorkflowUnavailable(
                'This sale has no issuable Phase 2 fiscal invoice. Fulfillment cannot mark it Processing or Completed. Review and invoice it through the fiscal checkout first.'
            );
        }
    }

    public function validateSaleData(array $data): void
    {
        if (! $this->moduleIsAvailable()) {
            return;
        }
        $validator = 'Modules\\ZatcaIntegrationKsa\\Services\\ZatcaSalePreflight';
        if ($this->isPhase2Active() && class_exists($validator)) {
            app($validator)->validateCurrency($data);
            app($validator)->validate($data);
        }
    }

    public function prepareSaleData(array $data): array
    {
        $this->assertConfiguredPhase2ModuleAvailable();
        unset($data['_zatca_sale_plan'], $data['_zatca_sale_coupon']);
        if (!$this->moduleIsAvailable()) { return $data; }
        $phase2 = $this->isPhase2Active();
        $completed = in_array((int) ($data['sale_status'] ?? 1), [1, 5], true);
        if ($phase2 && $completed && $this->requiresAdjustedSalePreview($data)) {
            return app('Modules\\ZatcaIntegrationKsa\\Services\\ZatcaAdjustedSaleWorkflow')->prepare($data);
        }
        $this->validateSaleData($data);
        if ($phase2 && $completed) {
            app('Modules\\ZatcaIntegrationKsa\\Services\\ZatcaSalePreview')->verify($data);
        }
        return $data;
    }

    /** The preview and save must choose the same fiscal calculation path. */
    public function requiresAdjustedSalePreview(array $data): bool
    {
        return (float) ($data['order_discount'] ?? 0) != 0.0 || (float) ($data['coupon_discount'] ?? 0) != 0.0
            || !empty($data['coupon_id']) || !empty($data['coupon_code'])
            || (float) ($data['shipping_cost'] ?? 0) != 0.0 || (float) ($data['service_charge'] ?? 0) != 0.0
            || collect($data['reconciled_lines'] ?? [])->contains(fn ($line) => (float) ($line['tax_rate'] ?? -1) == 0.0);
    }

    public function persistSalePricing(Sale $sale, array $data, array $lineIds): void
    {
        if (!isset($data['_zatca_sale_plan'])) { return; }
        if (!$this->moduleIsAvailable()) { throw new RuntimeException('Restore the ZATCA module before saving fiscal pricing.'); }
        app('Modules\\ZatcaIntegrationKsa\\Services\\ZatcaAdjustedSaleWorkflow')->persist($sale, $data['_zatca_sale_plan'], $lineIds, $data['_zatca_sale_coupon'] ?? null);
    }

    public function salePreviewUrl(): ?string
    {
        if (! $this->isPhase2Active()) { return null; }
        return route('zatca.ksa.sales.preview');
    }

    public function processReturn(Returns $return): void
    {
        $this->process('createAndSubmitReturn', $return);
    }

    public function saleAccountingComponents(Sale $sale): ?array
    {
        // A saved fiscal plan remains authoritative even after mode changes.
        // Check its source connection before resolving any optional module class.
        $connection = $sale->getConnection();
        if (! $connection->getSchemaBuilder()->hasTable('zatca_sale_pricing_plans')
            || ! $connection->table('zatca_sale_pricing_plans')->where('sale_id', $sale->getKey())->exists()) {
            return null;
        }
        $service = 'Modules\\ZatcaIntegrationKsa\\Services\\ZatcaPlanAccounting';
        if (! $this->moduleIsAvailable() || ! class_exists($service)) {
            throw new RuntimeException('Restore the ZATCA module before posting accounting for this fiscal pricing plan.');
        }
        return app($service)->sale($sale);
    }

    public function returnAccountingComponents(Returns $return): ?array
    {
        $connection = $return->getConnection();
        $hasPlan = $connection->getSchemaBuilder()->hasTable('zatca_return_pricing_plans')
            && $connection->table('zatca_return_pricing_plans')->where('return_id', $return->getKey())->exists();
        if (! $hasPlan) {
            $saleId = $connection->table('returns')->where('id', $return->getKey())->value('sale_id');
            if ($saleId && $connection->getSchemaBuilder()->hasTable('zatca_sale_pricing_plans')
                && $connection->table('zatca_sale_pricing_plans')->where('sale_id', $saleId)->exists()) {
                throw new RuntimeException('Restore the fiscal return pricing plan before posting this discounted return.');
            }
            return null;
        }
        $service = 'Modules\\ZatcaIntegrationKsa\\Services\\ZatcaPlanAccounting';
        if (! $this->moduleIsAvailable() || ! class_exists($service)) {
            throw new RuntimeException('Restore the ZATCA module before posting this fiscal return pricing plan.');
        }
        return app($service)->saleReturn($return);
    }

    public function prepareReturnData(Sale $sale, array $data, ?array $submittedTotals = null): array
    {
        $this->assertConfiguredPhase2ModuleAvailable();
        // Never accept a client-supplied internal pricing plan.
        unset($data['_zatca_return_plan'], $data['_zatca_submitted_return_totals']);
        if (! $this->moduleIsAvailable()) {
            if ($this->isSourceLocked('sale', (int) $sale->id)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['sale_id' => 'Restore the ZATCA module before returning this fiscal invoice.']);
            }
            return $data;
        }
        if (! $this->isPhase2Active() && ! $this->isSourceLocked('sale', (int) $sale->id)) {
            return $data;
        }

        $data['_zatca_submitted_return_totals'] = array_intersect_key($submittedTotals ?? $data, array_flip(['grand_total', 'total_tax']));
        return app('Modules\\ZatcaIntegrationKsa\\Services\\ZatcaReturnPreflight')->prepare($sale, $data);
    }

    public function beginReturnRequest(Sale $sale, array $data, int $userId): ?array
    {
        $this->assertConfiguredPhase2ModuleAvailable();
        if (!$this->moduleIsAvailable()) { return null; }
        if (! $this->isPhase2Active() && !$this->isSourceLocked('sale', (int) $sale->id)) { return null; }
        return app('Modules\\ZatcaIntegrationKsa\\Services\\ZatcaReturnRequestStore')->begin($sale, $data, $userId);
    }

    public function completeReturnRequest(Returns $return, ?array $ticket): void
    {
        if ($ticket === null) { return; }
        if (!$this->moduleIsAvailable()) { throw new RuntimeException('Restore the ZATCA module before completing this return request.'); }
        app('Modules\\ZatcaIntegrationKsa\\Services\\ZatcaReturnRequestStore')->complete($return, $ticket);
    }

    public function persistReturnPricing(Returns $return, array $data): void
    {
        if (! isset($data['_zatca_return_plan'])) { return; }
        $service = 'Modules\\ZatcaIntegrationKsa\\Services\\ZatcaReturnPlanStore';
        if (! $this->moduleIsAvailable() || ! class_exists($service)) {
            throw new RuntimeException('Restore the ZATCA module before saving this fiscal return.');
        }
        app($service)->save($return, $data['_zatca_return_plan']);
    }

    public function returnPreviewUrl(Sale $sale): ?string
    {
        if (! $this->moduleIsAvailable() || ! $sale->getConnection()->getSchemaBuilder()->hasTable('zatca_sale_pricing_plans')
            || ! $sale->getConnection()->table('zatca_sale_pricing_plans')->where('sale_id', $sale->id)->exists()) {
            return null;
        }
        return route('zatca.ksa.returns.preview', $sale->id);
    }

    /** Original maxima for display only; locked history sets the remaining limit. */
    public function returnChargeOptions(Sale $sale): array
    {
        if (!$this->moduleIsAvailable() || $this->returnPreviewUrl($sale) === null) { return []; }
        $plan = app('Modules\\ZatcaIntegrationKsa\\Services\\ZatcaPricingPlanStore')->load($sale);
        $options = [];
        foreach (($plan['charge_allocations'] ?? []) as $source => $charge) {
            $options[$source] = ['label' => $source === 'shipping_cost' ? 'Shipping charge refund' : 'Service charge refund',
                'maximum' => $charge['remaining_gross']];
        }
        return $options;
    }

    public function isSourceLocked(string $sourceType, int $sourceId): bool
    {
        if ($sourceType === 'return' && Schema::hasTable('zatca_return_requests')
            && DB::table('zatca_return_requests')->where('return_id', $sourceId)->exists()) {
            return true;
        }
        if ($sourceType === 'return' && Schema::hasTable('zatca_return_pricing_plans')
            && DB::table('zatca_return_pricing_plans')->where('return_id', $sourceId)->exists()) {
            return true;
        }
        if ($sourceType === 'sale' && Schema::hasTable('zatca_sale_pricing_plans')
            && DB::table('zatca_sale_pricing_plans')->where('sale_id', $sourceId)->exists()) {
            return true;
        }
        // Keep fiscal records immutable even if the optional module is later
        // disabled or its directory is accidentally removed.
        return Schema::hasTable('zatca_ksa_documents')
            && DB::table('zatca_ksa_documents')
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->exists();
    }

    public function qrForSale(Sale $sale): string
    {
        // Fiscal mode and Phase 1 identity must follow the current tenant DB,
        // not a potentially shared general-setting cache.
        $settings = GeneralSetting::query()->latest()->first();
        $mode = $settings->zatca_mode
            ?? (($settings->is_zatca ?? false) ? 'phase1' : 'disabled');

        // Existing fiscal invoices must not fall back to a Phase 1/reference QR
        // merely because an administrator subsequently changes the global mode.
        if ($mode === 'phase2' || $this->isSourceLocked('sale', (int) $sale->getKey())) {
            $resolver = 'Modules\\ZatcaIntegrationKsa\\Services\\ZatcaQrResolver';
            if (! $this->moduleIsAvailable() || ! class_exists($resolver)) {
                throw new RuntimeException('ZATCA Phase 2 is selected, but the ZatcaIntegrationKsa module is unavailable. Restore and enable the module before printing this fiscal invoice.');
            }

            return app($resolver)->forFiscalSale($sale);
        }

        if ($mode !== 'phase1') {
            return (string) $sale->reference_no;
        }

        return GenerateQrCode::fromArray([
            new Seller((string) ($settings->company_name ?? config('company_name'))),
            new TaxNumber((string) ($settings->vat_registration_number ?? config('vat_registration_number'))),
            new InvoiceDate($sale->created_at->format('Y-m-d\TH:i:s')),
            new InvoiceTotalAmount(number_format((float) $sale->grand_total, 4, '.', '')),
            new InvoiceTaxAmount(number_format((float) ($sale->total_tax + $sale->order_tax), 4, '.', '')),
        ])->toBase64();
    }

    public function presentationForSale(Sale $sale): ?array
    {
        if (! $this->isSourceLocked('sale', (int) $sale->getKey())) {
            return null;
        }
        $document = DB::table('zatca_ksa_documents')->where('source_type', 'sale')
            ->where('source_id', $sale->getKey())->latest('id')->first();

        return app(ZatcaInvoicePresentation::class)->fromXml(
            (string) ($document->cleared_xml ?: $document->signed_xml),
            (string) $document->environment
        );
    }

    private function process(string $method, Sale|Returns $source): void
    {
        if (! $this->moduleIsAvailable()) {
            return;
        }

        $service = 'Modules\\ZatcaIntegrationKsa\\Services\\ZatcaDocumentService';
        if (! class_exists($service)) {
            return;
        }

        app($service)->{$method}($source);
    }
}
