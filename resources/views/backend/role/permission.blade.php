@extends('backend.layout.main')
@section('content')
@php
    $groups = [
        'Construction' => [
            'construction.dashboard.view' => 'View Construction Dashboard', 'project_project_list' => 'View Projects',
            'project_project_add' => 'Create Projects', 'project_project_edit' => 'Edit Projects', 'project_project_show' => 'View Project Details',
            'construction.project-costs.manage' => 'Manage Project Costs', 'construction.material-issues.manage' => 'Create / View Material Issues',
            'construction.material-returns.manage' => 'Create Material Returns', 'construction.subcontractors.manage' => 'Manage Subcontractors & Contracts',
            'construction.project-wages.manage' => 'Manage Project Wages', 'construction.equipment.manage' => 'Manage Equipment & Assignments',
            'construction.project-receipts.manage' => 'Manage Project Receipts', 'construction.shareholders.manage' => 'Manage Shareholders & Transactions', 'construction.procurement.view' => 'View Project Procurement & Logistics',
            'construction.transport.manage' => 'Manage Transport Records', 'construction.supplier-items.manage' => 'Manage Supplier-Item Relationships', 'construction.reports.view' => 'View Project Reports',
        ],
        'Procurement & Stores' => [
            'purchases-index'=>'View Purchases','purchases-add'=>'Create Purchases','purchases-edit'=>'Edit Purchases','purchase-return-index'=>'View Purchase Returns',
            'purchase-return-add'=>'Create Purchase Returns','suppliers-index'=>'View Suppliers','suppliers-add'=>'Create Suppliers','purchase-payment-index'=>'View Supplier Payments',
            'purchase-payment-add'=>'Create Supplier Payments','products-index'=>'View Materials','products-add'=>'Create Materials','products-edit'=>'Edit Materials',
            'transfers-index'=>'View Material Transfers','transfers-add'=>'Create Material Transfers','adjustment'=>'Stock Adjustment','stock_count'=>'Stock Count','stock-report'=>'Stock Ledger',
        ],
        'Workforce, Clients & Finance' => [
            'employees-index'=>'View Employees','employees-add'=>'Create Employees','attendance'=>'Attendance','payroll'=>'Payroll',
            'customers-index'=>'View Clients','customers-add'=>'Create Clients','customers-edit'=>'Edit Clients','expenses-index'=>'View Expenses','expenses-add'=>'Create Expenses',
            'incomes-index'=>'View Other Revenue','incomes-add'=>'Create Other Revenue','account-index'=>'Cash / Bank Accounts','money-transfer'=>'Account Transfers',
            'chart-of-accounts-manage'=>'Manage Chart of Accounts','balance-sheet'=>'Financial Statements','cash_flow'=>'Cash Flow',
        ],
    ];
    $shown = collect($groups)->flatMap(fn($items) => array_keys($items))->all();
@endphp
<section class="forms"><div class="container-fluid"><div class="card mt-3"><div class="card-header"><h4>Construction Role Permissions: {{$lims_role_data->name}}</h4><small class="text-muted">Only Construction ERP and retained engine permissions are shown. Disabled retail permissions remain hidden and routes stay blocked.</small></div>
<form action="{{route('role.setPermission')}}" method="post">@csrf<input type="hidden" name="role_id" value="{{$lims_role_data->id}}"><input type="hidden" name="modules[]" value="construction"><input type="hidden" name="modules[]" value="project">
@foreach(array_diff($all_permission,$shown,\App\Services\ModuleRegistry::accessPermissions()) as $permission)<input type="hidden" name="permissions[]" value="{{$permission}}">@endforeach
<div class="card-body"><div class="row">@foreach($groups as $group=>$permissions)<div class="col-lg-4"><div class="border rounded p-3 mb-3"><h5>{{$group}}</h5>@foreach($permissions as $name=>$label)<div class="custom-control custom-checkbox mb-2"><input class="custom-control-input" type="checkbox" name="{{$name}}" value="1" id="perm-{{md5($name)}}" @checked(in_array($name,$all_permission))><label class="custom-control-label" for="perm-{{md5($name)}}">{{$label}}</label></div>@endforeach</div></div>@endforeach</div></div><div class="card-footer text-right"><button class="btn btn-primary">Save Construction Permissions</button></div></form></div></div></section>
@endsection
