@extends('backend.layout.main')

@section('content')
@php
    $exportFilters = [
        'starting_date' => $startingDate,
        'ending_date' => $endingDate,
        'warehouse_id' => $warehouseId,
        'gst_registration_id' => $gstRegistrationId,
        'customer_id' => $customerId,
        'supplier_id' => $supplierId,
        'state_code' => $stateCode,
        'tab' => 'all',
    ];
@endphp
<section class="forms">
    <div class="container-fluid">
        <div class="card mb-3">
            <div class="card-header mt-2">
                <h3 class="text-center font-weight-bold"><i class="fa fa-file-text-o mr-2"></i>{{ __('db.Consolidated India GST Operational Register & Report') }}</h3>
                <p class="text-center text-muted mb-0">{{ __('db.Operational GST register and reconciliation tool. Not a statutory GSTN filing.') }}</p>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('report.gst') }}" id="gst-filter-form">
                    <div class="row">
                        <div class="col-md-2 form-group">
                            <label class="font-weight-bold">{{ __('db.From Date') }}</label>
                            <input type="date" name="starting_date" class="form-control" value="{{ $startingDate }}">
                        </div>
                        <div class="col-md-2 form-group">
                            <label class="font-weight-bold">{{ __('db.To Date') }}</label>
                            <input type="date" name="ending_date" class="form-control" value="{{ $endingDate }}">
                        </div>
                        <div class="col-md-2 form-group">
                            <label class="font-weight-bold">{{ __('db.Warehouse') }}</label>
                            <select name="warehouse_id" class="form-control selectpicker" data-live-search="true">
                                <option value="0">{{ __('db.All Warehouses') }}</option>
                                @foreach($warehouses as $wh)
                                    <option value="{{ $wh->id }}" {{ $warehouseId == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <label class="font-weight-bold">{{ __('db.GST Registration') }}</label>
                            <select name="gst_registration_id" class="form-control selectpicker" data-live-search="true">
                                <option value="0">{{ __('db.All GST Registrations') }}</option>
                                @foreach($registrations as $registration)
                                    <option value="{{ $registration->id }}" {{ $gstRegistrationId == $registration->id ? 'selected' : '' }}>
                                        {{ $registration->gstin }}{{ $registration->warehouse ? ' - '.$registration->warehouse->name : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <label class="font-weight-bold">{{ __('db.Place of Supply State') }}</label>
                            <select name="state_code" class="form-control selectpicker" data-live-search="true">
                                <option value="">{{ __('db.All States') }}</option>
                                @foreach($states as $st)
                                    <option value="{{ $st->state_code }}" {{ $stateCode == $st->state_code ? 'selected' : '' }}>{{ $st->state_code }} - {{ $st->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <label class="font-weight-bold">{{ __('db.Customer') }}</label>
                            <select name="customer_id" class="form-control selectpicker" data-live-search="true">
                                <option value="0">{{ __('db.All Customers') }}</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}" {{ $customerId == $customer->id ? 'selected' : '' }}>{{ $customer->company_name ?: $customer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-3 form-group">
                            <label class="font-weight-bold">{{ __('db.Supplier') }}</label>
                            <select name="supplier_id" class="form-control selectpicker" data-live-search="true">
                                <option value="0">{{ __('db.All Suppliers') }}</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" {{ $supplierId == $supplier->id ? 'selected' : '' }}>{{ $supplier->company_name ?: $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-9 text-right align-self-end form-group">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-filter mr-1"></i>{{ __('db.Apply Filters') }}</button>
                            <a href="{{ route('report.gst') }}" class="btn btn-secondary"><i class="fa fa-refresh mr-1"></i>{{ __('db.Reset') }}</a>
                            <a target="_blank" rel="noopener" href="{{ route('report.gst.print', $exportFilters) }}" class="btn btn-dark"><i class="fa fa-print mr-1"></i>{{ __('db.Print') }}</a>
                            <a href="{{ route('report.gst.export', array_merge($exportFilters, ['format' => 'csv'])) }}" class="btn btn-success"><i class="fa fa-file-text-o mr-1"></i>CSV</a>
                            <a href="{{ route('report.gst.export', array_merge($exportFilters, ['format' => 'excel'])) }}" class="btn btn-success"><i class="fa fa-file-excel-o mr-1"></i>{{ __('db.Excel') }}</a>
                            <a href="{{ route('report.gst.export', array_merge($exportFilters, ['format' => 'pdf'])) }}" class="btn btn-danger"><i class="fa fa-file-pdf-o mr-1"></i>PDF</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Top Summary KPI Cards -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card border-primary h-100 shadow-sm">
                    <div class="card-header bg-primary text-white font-weight-bold">
                        <i class="fa fa-arrow-circle-up mr-2"></i>Output Tax (Sales & Credit Notes)
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Gross Output Tax:</span>
                            <span class="font-weight-bold">₹{{ number_format($summary['gross_output_tax'], 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1 text-danger">
                            <span>Credit Note Adjustments:</span>
                            <span class="font-weight-bold">- ₹{{ number_format($summary['credit_note_output_tax'], 2) }}</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between font-weight-bold text-primary">
                            <span>Net Output GST:</span>
                            <span>₹{{ number_format($summary['net_output_tax'], 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card border-success h-100 shadow-sm">
                    <div class="card-header bg-success text-white font-weight-bold">
                        <i class="fa fa-arrow-circle-down mr-2"></i>Input Tax Credit (Purchases)
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Purchase Eligible ITC:</span>
                            <span class="font-weight-bold">₹{{ number_format($summary['purchase_eligible_itc'], 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1 text-danger">
                            <span>Purchase Return Reversals:</span>
                            <span class="font-weight-bold">- ₹{{ number_format($summary['purchase_return_reversed_itc'], 2) }}</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between font-weight-bold text-success">
                            <span>Net Purchase ITC:</span>
                            <span>₹{{ number_format($summary['net_purchase_itc'], 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card border-info h-100 shadow-sm">
                    <div class="card-header bg-info text-white font-weight-bold">
                        <i class="fa fa-credit-card mr-2"></i>Expense ITC & Ineligible ITC
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Expense Eligible ITC:</span>
                            <span class="font-weight-bold">₹{{ number_format($summary['expense_eligible_itc'], 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1 text-secondary">
                            <span>Blocked / Ineligible ITC:</span>
                            <span class="font-weight-bold">₹{{ number_format($summary['total_ineligible_itc'], 2) }}</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between font-weight-bold text-info">
                            <span>Total Eligible Expense ITC:</span>
                            <span>₹{{ number_format($summary['expense_eligible_itc'], 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="card border-warning h-100 shadow-sm">
                    <div class="card-header bg-warning text-dark font-weight-bold">
                        <i class="fa fa-exchange mr-2"></i>Reverse Charge Mechanism (RCM)
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-1">
                            <span>RCM Tax Liability (Cash Ledger):</span>
                            <span class="font-weight-bold text-danger">₹{{ number_format($summary['rcm_liability'], 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span>RCM Eligible ITC:</span>
                            <span class="font-weight-bold text-success">₹{{ number_format($summary['rcm_eligible_itc'], 2) }}</span>
                        </div>
                        <small class="text-muted">RCM liability is payable in cash; eligible ITC can be claimed post payment.</small>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="card border-dark h-100 shadow-sm">
                    <div class="card-header bg-dark text-white font-weight-bold">
                        <i class="fa fa-balance-scale mr-2"></i>Indicative Net GST Position
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Net Output Tax + RCM Liability:</span>
                            <span class="font-weight-bold">₹{{ number_format($summary['net_output_tax'] + $summary['rcm_liability'], 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1 text-success">
                            <span>Total Available ITC (Purchases + Expenses + RCM):</span>
                            <span class="font-weight-bold">₹{{ number_format($summary['total_available_itc'], 2) }}</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between font-weight-bold {{ $summary['indicative_net_position'] >= 0 ? 'text-danger' : 'text-success' }}" style="font-size: 1.15rem;">
                            <span>{{ $summary['indicative_net_position'] >= 0 ? 'Net Payable (Indicative):' : 'Net Credit Carryforward (Indicative):' }}</span>
                            <span>₹{{ number_format(abs($summary['indicative_net_position']), 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabbed Registers -->
        <div class="card shadow-sm">
            <div class="card-header p-2 bg-light">
                <ul class="nav nav-tabs card-header-tabs" id="gstTab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active font-weight-bold" id="output-tab" data-toggle="tab" href="#output" role="tab" aria-controls="output" aria-selected="true">
                            <i class="fa fa-arrow-up mr-1 text-primary"></i>Output Tax Register ({{ $sales->count() + $saleReturns->count() }})
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link font-weight-bold" id="input-tab" data-toggle="tab" href="#input" role="tab" aria-controls="input" aria-selected="false">
                            <i class="fa fa-arrow-down mr-1 text-success"></i>Input Tax Register ({{ $purchases->count() + $purchaseReturns->count() }})
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link font-weight-bold" id="expense-tab" data-toggle="tab" href="#expense" role="tab" aria-controls="expense" aria-selected="false">
                            <i class="fa fa-credit-card mr-1 text-info"></i>Expense Tax Register ({{ $expenses->count() }})
                        </a>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content" id="gstTabContent">
                    <!-- Tab 1: Output Tax -->
                    <div class="tab-pane fade show active" id="output" role="tabpanel" aria-labelledby="output-tab">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover dataTable" id="output-table">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Doc Type</th>
                                        <th>Customer</th>
                                        <th>GSTIN</th>
                                        <th>POS State</th>
                                        <th>Taxable Value (₹)</th>
                                        <th>CGST (₹)</th>
                                        <th>SGST/UTGST (₹)</th>
                                        <th>IGST (₹)</th>
                                        <th>Cess (₹)</th>
                                        <th>Total Tax (₹)</th>
                                        <th>Total Value (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($sales as $s)
                                        @php
                                            $sgstUtgst = (float)$s->total_sgst + (float)$s->total_utgst;
                                            $totTax = (float)$s->total_cgst + $sgstUtgst + (float)$s->total_igst + (float)$s->total_cess;
                                        @endphp
                                        <tr>
                                            <td>{{ $s->invoice_date ? date('d-m-Y', strtotime($s->invoice_date)) : '' }}</td>
                                            <td><strong>{{ $s->invoice_reference }}</strong></td>
                                            <td><span class="badge badge-primary">Invoice</span></td>
                                            <td>{{ $s->customer_name }}</td>
                                            <td>{{ $s->customer_gstin ?? 'Unregistered' }}</td>
                                            <td>{{ $s->place_of_supply_state_code }}</td>
                                            <td class="text-right">{{ number_format((float)$s->total_taxable_value, 2) }}</td>
                                            <td class="text-right">{{ number_format((float)$s->total_cgst, 2) }}</td>
                                            <td class="text-right">{{ number_format($sgstUtgst, 2) }}</td>
                                            <td class="text-right">{{ number_format((float)$s->total_igst, 2) }}</td>
                                            <td class="text-right">{{ number_format((float)$s->total_cess, 2) }}</td>
                                            <td class="text-right font-weight-bold">{{ number_format($totTax, 2) }}</td>
                                            <td class="text-right font-weight-bold">{{ number_format((float)$s->grand_total, 2) }}</td>
                                        </tr>
                                    @endforeach

                                    @foreach($saleReturns as $r)
                                        @php
                                            $sgstUtgst = (float)$r->adjusted_sgst + (float)$r->adjusted_utgst;
                                            $totTax = (float)$r->adjusted_cgst + $sgstUtgst + (float)$r->adjusted_igst + (float)$r->adjusted_cess;
                                        @endphp
                                        <tr class="table-warning text-danger">
                                            <td>{{ $r->note_date ? date('d-m-Y', strtotime($r->note_date)) : '' }}</td>
                                            <td><strong>{{ $r->note_reference }}</strong></td>
                                            <td><span class="badge badge-warning text-dark">Credit Note</span></td>
                                            <td>{{ $r->customer_name }}</td>
                                            <td>{{ $r->customer_gstin ?? 'Unregistered' }}</td>
                                            <td>{{ $r->place_of_supply_state_code }}</td>
                                            <td class="text-right">-{{ number_format((float)$r->adjusted_taxable_value, 2) }}</td>
                                            <td class="text-right">-{{ number_format((float)$r->adjusted_cgst, 2) }}</td>
                                            <td class="text-right">-{{ number_format($sgstUtgst, 2) }}</td>
                                            <td class="text-right">-{{ number_format((float)$r->adjusted_igst, 2) }}</td>
                                            <td class="text-right">-{{ number_format((float)$r->adjusted_cess, 2) }}</td>
                                            <td class="text-right font-weight-bold">-{{ number_format($totTax, 2) }}</td>
                                            <td class="text-right font-weight-bold">-{{ number_format((float)$r->adjusted_grand_total, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="font-weight-bold bg-light">
                                    <tr>
                                        <th colspan="6" class="text-right">Net Output Register Totals:</th>
                                        <th class="text-right">₹{{ number_format($summary['net_output_taxable'], 2) }}</th>
                                        <th class="text-right">₹{{ number_format($summary['net_output_cgst'], 2) }}</th>
                                        <th class="text-right">₹{{ number_format($summary['net_output_sgst'] + $summary['net_output_utgst'], 2) }}</th>
                                        <th class="text-right">₹{{ number_format($summary['net_output_igst'], 2) }}</th>
                                        <th class="text-right">₹{{ number_format($summary['net_output_cess'], 2) }}</th>
                                        <th class="text-right font-weight-bold text-primary">₹{{ number_format($summary['net_output_tax'], 2) }}</th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 2: Input Tax -->
                    <div class="tab-pane fade" id="input" role="tabpanel" aria-labelledby="input-tab">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover dataTable" id="input-table">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Doc Type</th>
                                        <th>Supplier</th>
                                        <th>GSTIN</th>
                                        <th>Supplier State</th>
                                        <th>POS State</th>
                                        <th>RCM</th>
                                        <th>ITC Eligibility</th>
                                        <th>Taxable Value (₹)</th>
                                        <th>CGST (₹)</th>
                                        <th>SGST/UTGST (₹)</th>
                                        <th>IGST (₹)</th>
                                        <th>Cess (₹)</th>
                                        <th>Eligible ITC (₹)</th>
                                        <th>Total Value (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($purchases as $p)
                                        @php
                                            $sgstUtgst = (float)$p->total_sgst + (float)$p->total_utgst;
                                        @endphp
                                        <tr>
                                            <td>{{ $p->invoice_date ? date('d-m-Y', strtotime($p->invoice_date)) : '' }}</td>
                                            <td><strong>{{ $p->invoice_reference }}</strong></td>
                                            <td><span class="badge badge-success">Purchase</span></td>
                                            <td>{{ $p->supplier_name }}</td>
                                            <td>{{ $p->supplier_gstin ?? 'Unregistered' }}</td>
                                            <td>{{ $p->supplier_state_code }}</td>
                                            <td>{{ $p->place_of_supply_state_code }}</td>
                                            <td>{{ $p->is_reverse_charge ? 'Yes' : 'No' }}</td>
                                            <td><span class="badge {{ $p->itc_eligibility === 'eligible' ? 'badge-success' : 'badge-danger' }}">{{ ucfirst($p->itc_eligibility) }}</span></td>
                                            <td class="text-right">{{ number_format((float)$p->total_taxable_value, 2) }}</td>
                                            <td class="text-right">{{ number_format((float)$p->total_cgst, 2) }}</td>
                                            <td class="text-right">{{ number_format($sgstUtgst, 2) }}</td>
                                            <td class="text-right">{{ number_format((float)$p->total_igst, 2) }}</td>
                                            <td class="text-right">{{ number_format((float)$p->total_cess, 2) }}</td>
                                            <td class="text-right font-weight-bold text-success">{{ number_format((float)$p->total_eligible_itc, 2) }}</td>
                                            <td class="text-right font-weight-bold">{{ number_format((float)$p->grand_total, 2) }}</td>
                                        </tr>
                                    @endforeach

                                    @foreach($purchaseReturns as $pr)
                                        @php
                                            $sgstUtgst = (float)$pr->adjusted_sgst + (float)$pr->adjusted_utgst;
                                        @endphp
                                        <tr class="table-warning text-danger">
                                            <td>{{ $pr->note_date ? date('d-m-Y', strtotime($pr->note_date)) : '' }}</td>
                                            <td><strong>{{ $pr->note_reference }}</strong></td>
                                            <td><span class="badge badge-warning text-dark">Supplier CN</span></td>
                                            <td>{{ $pr->supplier_name }}</td>
                                            <td>{{ $pr->supplier_gstin ?? 'Unregistered' }}</td>
                                            <td>{{ $pr->supplier_state_code }}</td>
                                            <td>{{ $pr->place_of_supply_state_code }}</td>
                                            <td>No</td>
                                            <td><span class="badge badge-secondary">Reversed ITC</span></td>
                                            <td class="text-right">-{{ number_format((float)$pr->adjusted_taxable_value, 2) }}</td>
                                            <td class="text-right">-{{ number_format((float)$pr->adjusted_cgst, 2) }}</td>
                                            <td class="text-right">-{{ number_format($sgstUtgst, 2) }}</td>
                                            <td class="text-right">-{{ number_format((float)$pr->adjusted_igst, 2) }}</td>
                                            <td class="text-right">-{{ number_format((float)$pr->adjusted_cess, 2) }}</td>
                                            <td class="text-right font-weight-bold text-danger">-{{ number_format((float)$pr->reversed_itc_amount, 2) }}</td>
                                            <td class="text-right font-weight-bold">-{{ number_format((float)$pr->adjusted_grand_total, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="font-weight-bold bg-light">
                                    <tr>
                                        <th colspan="9" class="text-right">Net Purchase ITC Totals:</th>
                                        <th class="text-right">₹{{ number_format($summary['net_purchase_itc'], 2) }}</th>
                                        <th colspan="4"></th>
                                        <th class="text-right font-weight-bold text-success">₹{{ number_format($summary['net_purchase_itc'], 2) }}</th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 3: Expense Tax -->
                    <div class="tab-pane fade" id="expense" role="tabpanel" aria-labelledby="expense-tab">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover dataTable" id="expense-table">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Vendor</th>
                                        <th>Vendor GSTIN</th>
                                        <th>Vendor State</th>
                                        <th>POS State</th>
                                        <th>RCM</th>
                                        <th>ITC Eligible</th>
                                        <th>Taxable Value (₹)</th>
                                        <th>CGST (₹)</th>
                                        <th>SGST/UTGST (₹)</th>
                                        <th>IGST (₹)</th>
                                        <th>Cess (₹)</th>
                                        <th>Eligible ITC (₹)</th>
                                        <th>Total Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($expenses as $e)
                                        @php
                                            $sgstUtgst = (float)$e->sgst_amount + (float)$e->utgst_amount;
                                        @endphp
                                        <tr>
                                            <td>{{ $e->expense_date ? date('d-m-Y', strtotime($e->expense_date)) : '' }}</td>
                                            <td><strong>{{ $e->reference_no }}</strong></td>
                                            <td>{{ $e->vendor_name }}</td>
                                            <td>{{ $e->vendor_gstin ?? 'Unregistered' }}</td>
                                            <td>{{ $e->vendor_state_code }}</td>
                                            <td>{{ $e->place_of_supply_state_code }}</td>
                                            <td>{{ $e->is_reverse_charge ? 'Yes' : 'No' }}</td>
                                            <td><span class="badge {{ $e->is_itc_eligible ? 'badge-success' : 'badge-danger' }}">{{ $e->is_itc_eligible ? 'Eligible' : 'Ineligible' }}</span></td>
                                            <td class="text-right">{{ number_format((float)$e->taxable_amount, 2) }}</td>
                                            <td class="text-right">{{ number_format((float)$e->cgst_amount, 2) }}</td>
                                            <td class="text-right">{{ number_format($sgstUtgst, 2) }}</td>
                                            <td class="text-right">{{ number_format((float)$e->igst_amount, 2) }}</td>
                                            <td class="text-right">{{ number_format((float)$e->cess_amount, 2) }}</td>
                                            <td class="text-right font-weight-bold text-info">{{ number_format((float)$e->eligible_itc, 2) }}</td>
                                            <td class="text-right font-weight-bold">{{ number_format((float)$e->total_amount, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="font-weight-bold bg-light">
                                    <tr>
                                        <th colspan="8" class="text-right">Total Eligible Expense ITC:</th>
                                        <th colspan="5"></th>
                                        <th class="text-right font-weight-bold text-info">₹{{ number_format($summary['expense_eligible_itc'], 2) }}</th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script type="text/javascript">
    $(document).ready(function() {
        $('#output-table').DataTable({
            "order": [],
            'language': {
                'lengthMenu': '_MENU_ records per page',
                "info": '<small>Showing _START_ - _END_ (_TOTAL_)</small>',
                "search": 'Search: ',
                "paginate": {
                    "previous": '<i class="dripicons-chevron-left"></i>',
                    "next": '<i class="dripicons-chevron-right"></i>'
                }
            },
            'columnDefs': [
                {"orderable": false, 'targets': []}
            ],
            'select': { style: 'multi', selector: 'td:first-child'},
            'lengthMenu': [[10, 25, 50, -1], [10, 25, 50, "All"]],
            dom: '<"row"lf>rtip'
        });

        $('#input-table').DataTable({
            "order": [],
            'language': {
                'lengthMenu': '_MENU_ records per page',
                "info": '<small>Showing _START_ - _END_ (_TOTAL_)</small>',
                "search": 'Search: ',
                "paginate": {
                    "previous": '<i class="dripicons-chevron-left"></i>',
                    "next": '<i class="dripicons-chevron-right"></i>'
                }
            },
            'columnDefs': [
                {"orderable": false, 'targets': []}
            ],
            'lengthMenu': [[10, 25, 50, -1], [10, 25, 50, "All"]],
            dom: '<"row"lf>rtip'
        });

        $('#expense-table').DataTable({
            "order": [],
            'language': {
                'lengthMenu': '_MENU_ records per page',
                "info": '<small>Showing _START_ - _END_ (_TOTAL_)</small>',
                "search": 'Search: ',
                "paginate": {
                    "previous": '<i class="dripicons-chevron-left"></i>',
                    "next": '<i class="dripicons-chevron-right"></i>'
                }
            },
            'columnDefs': [
                {"orderable": false, 'targets': []}
            ],
            'lengthMenu': [[10, 25, 50, -1], [10, 25, 50, "All"]],
            dom: '<"row"lf>rtip'
        });
    });
</script>
@endpush
