<?php

namespace Modules\IndiaGST\Services;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\WarehouseAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\IndiaGST\Entities\IndiaGstExpenseSnapshot;
use Modules\IndiaGST\Entities\IndiaGstPurchaseReturnSnapshot;
use Modules\IndiaGST\Entities\IndiaGstPurchaseSnapshot;
use Modules\IndiaGST\Entities\IndiaGstRegistration;
use Modules\IndiaGST\Entities\IndiaGstReturnSnapshot;
use Modules\IndiaGST\Entities\IndiaGstSaleSnapshot;
use Modules\IndiaGST\Entities\IndiaGstState;

class IndiaGstReportService
{
    public function __construct(private WarehouseAccessService $warehouseAccess)
    {
    }

    public function report(array $filters): array
    {
        $registrationIds = $this->registrationIds($filters);

        $sales = $this->applyCommonFilters(
            IndiaGstSaleSnapshot::query(),
            $filters,
            $registrationIds,
            'invoice_date'
        )
            ->when($filters['customer_id'], fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        $saleReturns = $this->applyCommonFilters(
            IndiaGstReturnSnapshot::query(),
            $filters,
            $registrationIds,
            'note_date'
        )
            ->when($filters['customer_id'], fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->orderBy('note_date')
            ->orderBy('id')
            ->get();

        $purchases = $this->applyCommonFilters(
            IndiaGstPurchaseSnapshot::query(),
            $filters,
            $registrationIds,
            'invoice_date'
        )
            ->when($filters['supplier_id'], fn (Builder $query, int $id) => $query->where('supplier_id', $id))
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        $purchaseReturns = $this->applyCommonFilters(
            IndiaGstPurchaseReturnSnapshot::query(),
            $filters,
            $registrationIds,
            'note_date'
        )
            ->when($filters['supplier_id'], fn (Builder $query, int $id) => $query->where('supplier_id', $id))
            ->orderBy('note_date')
            ->orderBy('id')
            ->get();

        $expenses = $this->applyCommonFilters(
            IndiaGstExpenseSnapshot::query(),
            $filters,
            $registrationIds,
            'expense_date'
        )
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();

        $summary = $this->summary($sales, $saleReturns, $purchases, $purchaseReturns, $expenses);

        return compact('filters', 'sales', 'saleReturns', 'purchases', 'purchaseReturns', 'expenses', 'summary');
    }

    public function filterOptions(): array
    {
        $registrationQuery = IndiaGstRegistration::query()->with(['state', 'warehouse']);
        $this->warehouseAccess->scope($registrationQuery, 'warehouse_id');
        $registrations = $registrationQuery->orderBy('gstin')->get();
        $registrationIds = $registrations->pluck('id');

        $warehouseQuery = Warehouse::withoutGlobalScope('authorized_warehouse')->where('is_active', true);
        $this->warehouseAccess->scope($warehouseQuery, 'id');

        $customerIds = IndiaGstSaleSnapshot::query()
            ->whereIn('gst_registration_id', $registrationIds)
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id');
        $supplierIds = IndiaGstPurchaseSnapshot::query()
            ->whereIn('gst_registration_id', $registrationIds)
            ->whereNotNull('supplier_id')
            ->distinct()
            ->pluck('supplier_id');

        return [
            'warehouses' => $warehouseQuery->orderBy('name')->get(),
            'registrations' => $registrations,
            'states' => IndiaGstState::orderBy('state_code')->get(),
            'customers' => Customer::whereIn('id', $customerIds)->orderBy('name')->get(['id', 'name', 'company_name']),
            'suppliers' => Supplier::whereIn('id', $supplierIds)->orderBy('name')->get(['id', 'name', 'company_name']),
        ];
    }

    public function sections(array $report, string $tab = 'all'): array
    {
        $sections = [];

        if (in_array($tab, ['all', 'output'], true)) {
            $rows = [];
            foreach ($report['sales'] as $snapshot) {
                $sgstUtgst = (float) $snapshot->total_sgst + (float) $snapshot->total_utgst;
                $totalTax = (float) $snapshot->total_cgst + $sgstUtgst + (float) $snapshot->total_igst + (float) $snapshot->total_cess;
                $rows[] = [
                    optional($snapshot->invoice_date)->format('Y-m-d'), $snapshot->invoice_reference, 'Invoice',
                    $snapshot->customer_name, $snapshot->customer_gstin, $snapshot->place_of_supply_state_code,
                    (float) $snapshot->total_taxable_value, (float) $snapshot->total_cgst, $sgstUtgst,
                    (float) $snapshot->total_igst, (float) $snapshot->total_cess, $totalTax, (float) $snapshot->grand_total,
                ];
            }
            foreach ($report['saleReturns'] as $snapshot) {
                $sgstUtgst = (float) $snapshot->adjusted_sgst + (float) $snapshot->adjusted_utgst;
                $totalTax = (float) $snapshot->adjusted_cgst + $sgstUtgst + (float) $snapshot->adjusted_igst + (float) $snapshot->adjusted_cess;
                $rows[] = [
                    optional($snapshot->note_date)->format('Y-m-d'), $snapshot->note_reference, 'Seller Credit Note',
                    $snapshot->customer_name, $snapshot->customer_gstin, $snapshot->place_of_supply_state_code,
                    -(float) $snapshot->adjusted_taxable_value, -(float) $snapshot->adjusted_cgst, -$sgstUtgst,
                    -(float) $snapshot->adjusted_igst, -(float) $snapshot->adjusted_cess, -$totalTax, -(float) $snapshot->adjusted_grand_total,
                ];
            }
            $sections[] = $this->section('Output Tax', [
                'Date', 'Reference', 'Document Type', 'Customer', 'GSTIN', 'Place of Supply',
                'Taxable Value', 'CGST', 'SGST/UTGST', 'IGST', 'Cess', 'Total Tax', 'Total Value',
            ], $rows);
        }

        if (in_array($tab, ['all', 'input'], true)) {
            $rows = [];
            foreach ($report['purchases'] as $snapshot) {
                $sgstUtgst = (float) $snapshot->total_sgst + (float) $snapshot->total_utgst;
                $rows[] = [
                    optional($snapshot->invoice_date)->format('Y-m-d'), $snapshot->invoice_reference, 'Purchase Invoice',
                    $snapshot->supplier_name, $snapshot->supplier_gstin, $snapshot->supplier_state_code,
                    $snapshot->place_of_supply_state_code, $snapshot->is_reverse_charge ? 'Yes' : 'No',
                    $snapshot->itc_eligibility, (float) $snapshot->total_taxable_value, (float) $snapshot->total_cgst,
                    $sgstUtgst, (float) $snapshot->total_igst, (float) $snapshot->total_cess,
                    (float) $snapshot->rcm_total_liability, (float) $snapshot->total_eligible_itc, (float) $snapshot->grand_total,
                ];
            }
            foreach ($report['purchaseReturns'] as $snapshot) {
                $sgstUtgst = (float) $snapshot->adjusted_sgst + (float) $snapshot->adjusted_utgst;
                $rows[] = [
                    optional($snapshot->note_date)->format('Y-m-d'), $snapshot->note_reference, 'Supplier Credit Note',
                    $snapshot->supplier_name, $snapshot->supplier_gstin, $snapshot->supplier_state_code,
                    $snapshot->place_of_supply_state_code, 'No', 'Reversed ITC',
                    -(float) $snapshot->adjusted_taxable_value, -(float) $snapshot->adjusted_cgst, -$sgstUtgst,
                    -(float) $snapshot->adjusted_igst, -(float) $snapshot->adjusted_cess, 0,
                    -(float) $snapshot->reversed_itc_amount, -(float) $snapshot->adjusted_grand_total,
                ];
            }
            $sections[] = $this->section('Input Tax', [
                'Date', 'Reference', 'Document Type', 'Supplier', 'GSTIN', 'Supplier State', 'Place of Supply',
                'RCM', 'ITC Eligibility', 'Taxable Value', 'CGST', 'SGST/UTGST', 'IGST', 'Cess',
                'RCM Liability', 'Eligible ITC', 'Total Value',
            ], $rows);
        }

        if (in_array($tab, ['all', 'expense'], true)) {
            $rows = [];
            foreach ($report['expenses'] as $snapshot) {
                $sgstUtgst = (float) $snapshot->sgst_amount + (float) $snapshot->utgst_amount;
                $rows[] = [
                    optional($snapshot->expense_date)->format('Y-m-d'), $snapshot->reference_no,
                    $snapshot->vendor_name, $snapshot->vendor_gstin, $snapshot->vendor_state_code,
                    $snapshot->place_of_supply_state_code, $snapshot->is_reverse_charge ? 'Yes' : 'No',
                    $snapshot->is_itc_eligible ? 'Eligible' : 'Ineligible', (float) $snapshot->taxable_amount,
                    (float) $snapshot->cgst_amount, $sgstUtgst, (float) $snapshot->igst_amount,
                    (float) $snapshot->cess_amount, (float) $snapshot->rcm_liability,
                    (float) $snapshot->eligible_itc, (float) $snapshot->total_amount,
                ];
            }
            $sections[] = $this->section('Expense Tax', [
                'Date', 'Reference', 'Vendor', 'GSTIN', 'Vendor State', 'Place of Supply', 'RCM', 'ITC Eligibility',
                'Taxable Value', 'CGST', 'SGST/UTGST', 'IGST', 'Cess', 'RCM Liability', 'Eligible ITC', 'Total Value',
            ], $rows);
        }

        return $sections;
    }

    private function registrationIds(array $filters): Collection
    {
        $query = IndiaGstRegistration::query();
        $this->warehouseAccess->scope($query, 'warehouse_id');

        if ($filters['warehouse_id']) {
            $this->warehouseAccess->authorizeWarehouse($filters['warehouse_id']);
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if ($filters['gst_registration_id']) {
            $query->whereKey($filters['gst_registration_id']);
        }

        $ids = $query->pluck('id');
        if ($filters['gst_registration_id'] && !$ids->contains($filters['gst_registration_id'])) {
            abort(403, __('db.Warehouse access denied.'));
        }

        return $ids;
    }

    private function applyCommonFilters(Builder $query, array $filters, Collection $registrationIds, string $dateColumn): Builder
    {
        return $query
            ->whereIn('gst_registration_id', $registrationIds)
            ->whereBetween($dateColumn, [$filters['starting_date'], $filters['ending_date']])
            ->when($filters['state_code'], fn (Builder $builder, string $state) => $builder->where('place_of_supply_state_code', $state));
    }

    private function summary(Collection $sales, Collection $saleReturns, Collection $purchases, Collection $purchaseReturns, Collection $expenses): array
    {
        $grossSalesTax = $this->sumComponents($sales, 'total_');
        $saleReturnTax = $this->sumComponents($saleReturns, 'adjusted_');

        $regularPurchases = $purchases->where('is_reverse_charge', false);
        $rcmPurchases = $purchases->where('is_reverse_charge', true);
        $regularExpenses = $expenses->where('is_reverse_charge', false);
        $rcmExpenses = $expenses->where('is_reverse_charge', true);

        $originalPurchases = IndiaGstPurchaseSnapshot::query()
            ->whereIn('id', $purchaseReturns->pluck('india_gst_purchase_snapshot_id')->filter())
            ->get()
            ->keyBy('id');
        $regularPurchaseReturns = $purchaseReturns->filter(fn ($return) => !($originalPurchases->get($return->india_gst_purchase_snapshot_id)?->is_reverse_charge));
        $rcmPurchaseReturns = $purchaseReturns->filter(fn ($return) => (bool) ($originalPurchases->get($return->india_gst_purchase_snapshot_id)?->is_reverse_charge));

        $purchaseEligibleItc = (float) $regularPurchases->sum('total_eligible_itc');
        $purchaseReturnReversedItc = (float) $regularPurchaseReturns->sum('reversed_itc_amount');
        $netPurchaseItc = $purchaseEligibleItc - $purchaseReturnReversedItc;
        $expenseEligibleItc = (float) $regularExpenses->sum('eligible_itc');

        $rcmLiability = (float) $rcmPurchases->sum('rcm_total_liability')
            - (float) $rcmPurchaseReturns->sum('adjusted_total_tax')
            + (float) $rcmExpenses->sum('rcm_liability');
        $rcmEligibleItc = (float) $rcmPurchases->sum('total_eligible_itc')
            - (float) $rcmPurchaseReturns->sum('reversed_itc_amount')
            + (float) $rcmExpenses->sum('eligible_itc');

        $netOutputTax = $grossSalesTax - $saleReturnTax;
        $totalAvailableItc = $netPurchaseItc + $expenseEligibleItc + $rcmEligibleItc;

        return [
            'gross_output_tax' => $grossSalesTax,
            'credit_note_output_tax' => $saleReturnTax,
            'net_output_tax' => $netOutputTax,
            'net_output_taxable' => (float) $sales->sum('total_taxable_value') - (float) $saleReturns->sum('adjusted_taxable_value'),
            'net_output_cgst' => (float) $sales->sum('total_cgst') - (float) $saleReturns->sum('adjusted_cgst'),
            'net_output_sgst' => (float) $sales->sum('total_sgst') - (float) $saleReturns->sum('adjusted_sgst'),
            'net_output_utgst' => (float) $sales->sum('total_utgst') - (float) $saleReturns->sum('adjusted_utgst'),
            'net_output_igst' => (float) $sales->sum('total_igst') - (float) $saleReturns->sum('adjusted_igst'),
            'net_output_cess' => (float) $sales->sum('total_cess') - (float) $saleReturns->sum('adjusted_cess'),
            'purchase_eligible_itc' => $purchaseEligibleItc,
            'purchase_return_reversed_itc' => $purchaseReturnReversedItc,
            'net_purchase_itc' => $netPurchaseItc,
            'expense_eligible_itc' => $expenseEligibleItc,
            'total_ineligible_itc' => (float) $purchases->sum('total_ineligible_itc') + (float) $expenses->sum('ineligible_itc'),
            'rcm_liability' => $rcmLiability,
            'rcm_eligible_itc' => $rcmEligibleItc,
            'rcm_return_liability_reversal' => (float) $rcmPurchaseReturns->sum('adjusted_total_tax'),
            'rcm_return_itc_reversal' => (float) $rcmPurchaseReturns->sum('reversed_itc_amount'),
            'total_available_itc' => $totalAvailableItc,
            'indicative_net_position' => $netOutputTax + $rcmLiability - $totalAvailableItc,
        ];
    }

    private function sumComponents(Collection $records, string $prefix): float
    {
        return (float) $records->sum($prefix.'cgst')
            + (float) $records->sum($prefix.'sgst')
            + (float) $records->sum($prefix.'utgst')
            + (float) $records->sum($prefix.'igst')
            + (float) $records->sum($prefix.'cess');
    }

    private function section(string $title, array $headings, array $rows): array
    {
        $totals = array_fill(0, count($headings), '');
        $totals[0] = 'TOTAL';
        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                if (is_int($index) && $index >= 6 && is_numeric($value)) {
                    $totals[$index] = (float) ($totals[$index] ?: 0) + (float) $value;
                }
            }
        }

        return compact('title', 'headings', 'rows', 'totals');
    }
}
