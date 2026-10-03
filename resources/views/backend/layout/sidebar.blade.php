<ul id="side-main-menu" class="side-menu list-unstyled">
    <li><a class="{{ request()->routeIs('construction.dashboard') ? 'active' : '' }}" href="{{ route('construction.dashboard') }}"><i class="ti ti-dashboard"></i><span>Dashboard</span></a></li>

    <li><a href="#construction-projects" data-toggle="collapse"><i class="ti ti-building"></i><span>Projects</span></a>
        <ul id="construction-projects" class="collapse list-unstyled">
            <li><a href="{{ route('projects.index') }}">Projects</a></li>
            <li><a href="{{ route('construction.costs') }}">Project Costs</a></li>
            <li><a href="{{ route('construction.workforce') }}">Project Workers & Wages</a></li>
            <li><a href="{{ route('construction.materials') }}">Material Usage</a></li>
            <li><a href="{{ route('construction.equipment') }}">Project Equipment</a></li>
            <li><a href="{{ route('construction.receipts') }}">Project Receipts</a></li>
            <li><a href="{{ route('construction.reports.statement') }}">Project Statements</a></li>
        </ul>
    </li>

    <li><a href="#construction-procurement" data-toggle="collapse"><i class="ti ti-shopping-cart-plus"></i><span>Procurement</span></a>
        <ul id="construction-procurement" class="collapse list-unstyled">
            <li><a href="{{ route('purchases.index') }}">Purchases</a></li><li><a href="{{ route('return-purchase.index') }}">Purchase Returns</a></li>
            <li><a href="{{ route('supplier.index') }}">Suppliers & Payments</a></li><li><a href="{{ route('import-batches.index') }}">Landed Cost</a></li>
        </ul>
    </li>

    <li><a href="#construction-materials" data-toggle="collapse"><i class="ti ti-packages"></i><span>Materials & Stores</span></a>
        <ul id="construction-materials" class="collapse list-unstyled">
            <li><a href="{{ route('construction.catalog') }}">Materials / Items</a></li><li><a href="{{ route('category.index') }}">Categories</a></li>
            <li><a href="{{ route('unit.index') }}">Units</a></li><li><a href="{{ route('brand.index') }}">Brands</a></li><li><a href="{{ route('warehouse.index') }}">Stores / Site Stores</a></li>
            <li><a href="{{ route('construction.materials') }}">Material Issues & Returns</a></li><li><a href="{{ route('transfers.index') }}">Material Transfers</a></li>
            <li><a href="{{ route('qty_adjustment.index') }}">Stock Adjustment</a></li><li><a href="{{ route('stock-count.index') }}">Stock Count</a></li><li><a href="{{ route('report.stock-ledger') }}">Stock Ledger</a></li>
        </ul>
    </li>

    <li><a href="{{ route('construction.subcontractors') }}"><i class="ti ti-users-group"></i><span>Subcontractors</span></a></li>
    <li><a href="#construction-workforce" data-toggle="collapse"><i class="ti ti-user-cog"></i><span>Workforce</span></a>
        <ul id="construction-workforce" class="collapse list-unstyled"><li><a href="{{ route('employees.index') }}">Employees</a></li><li><a href="{{ route('departments.index') }}">Departments</a></li><li><a href="{{ route('designations.index') }}">Designations</a></li><li><a href="{{ route('attendance.index') }}">Attendance</a></li><li><a href="{{ route('construction.workforce') }}">Project Wages</a></li><li><a href="{{ route('payroll.index') }}">Payroll</a></li></ul>
    </li>
    <li><a href="{{ route('construction.equipment') }}"><i class="ti ti-truck"></i><span>Equipment & Assets</span></a></li>
    <li><a href="#construction-clients" data-toggle="collapse"><i class="ti ti-address-book"></i><span>Clients</span></a><ul id="construction-clients" class="collapse list-unstyled"><li><a href="{{ route('construction.clients') }}">Clients</a></li><li><a href="{{ route('construction.receipts') }}">Project Receipts & Receivables</a></li><li><a href="{{ route('construction.reports.statement') }}">Client / Project Statements</a></li></ul></li>

    <li><a href="#construction-finance" data-toggle="collapse"><i class="ti ti-cash-banknote"></i><span>Finance</span></a>
        <ul id="construction-finance" class="collapse list-unstyled"><li><a href="{{ route('accounts.index') }}">Cash / Bank Accounts</a></li><li><a href="{{ route('money-transfers.index') }}">Account Transfers</a></li><li><a href="{{ route('expenses.index') }}">Expenses</a></li><li><a href="{{ route('incomes.index') }}">Other Revenue</a></li><li><a href="{{ route('accounting.chart-of-accounts.index') }}">Chart of Accounts</a></li><li><a href="{{ route('accounting.generalLedger') }}">General Ledger</a></li><li><a href="{{ route('accounting.trialBalance') }}">Trial Balance</a></li></ul>
    </li>
    <li><a href="#construction-reports" data-toggle="collapse"><i class="ti ti-report"></i><span>Reports</span></a><ul id="construction-reports" class="collapse list-unstyled"><li><a href="{{ route('construction.reports.profitability') }}">Project Profitability</a></li><li><a href="{{ route('construction.reports.statement') }}">Project Statement</a></li><li><a href="{{ route('report.stock-ledger') }}">Stock Movement</a></li><li><a href="{{ route('accounting.cashFlowStatement') }}">Accounts & Cash Movement</a></li></ul></li>
    <li><a href="{{ route('construction.settings') }}"><i class="ti ti-settings"></i><span>Settings</span></a></li>
</ul>
