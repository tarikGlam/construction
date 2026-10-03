@extends('backend.layout.main')

@section('content')
<section>
    <div class="container-fluid">
        <div class="card mt-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h3 class="mb-1">{{ __('db.Chart of Accounts') }}</h3>
                        <p class="mb-0 text-muted">{{ __('db.Safely edit account codes and names without changing semantic role mappings.') }}</p>
                    </div>
                    <div>
                        @if($includeInactive)
                            <a href="{{ route('accounting.chart-of-accounts.index', ['include_inactive' => 0]) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="ti ti-eye-off"></i> {{ __('db.Hide Inactive Accounts') }}
                            </a>
                        @else
                            <a href="{{ route('accounting.chart-of-accounts.index', ['include_inactive' => 1]) }}" class="btn btn-sm btn-outline-primary">
                                <i class="ti ti-eye"></i> {{ __('db.Show Inactive Accounts') }}
                            </a>
                        @endif
                    </div>
                </div>

                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-hover" id="chart-of-accounts-table">
                        <thead>
                            <tr>
                                <th>{{ __('db.Code') }}</th>
                                <th>{{ __('db.name') }}</th>
                                <th>{{ __('db.Type') }}</th>
                                <th>{{ __('db.parent') }}</th>
                                <th>{{ __('db.Status') }}</th>
                                <th>{{ __('db.Protection') }}</th>
                                <th class="not-exported">{{ __('db.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($accounts as $account)
                                @php
                                    $isPaymentMapped = in_array($account->id, $paymentMappedAccountIds);
                                    $isMapped = in_array($account->id, $mappedAccountIds);
                                    $isProtected = $isMapped
                                        || $account->journal_lines_count > 0
                                        || $account->children_count > 0
                                        || $account->is_cash_account
                                        || $account->is_system
                                        || $account->is_control_account;
                                @endphp
                                <tr>
                                    <td>{{ $account->code }}</td>
                                    <td>{{ $account->name }}</td>
                                    <td>{{ ucwords(str_replace('_', ' ', $account->account_type)) }}</td>
                                    <td>{{ optional($account->parent)->code ? optional($account->parent)->code . ' - ' . optional($account->parent)->name : '—' }}</td>
                                    <td>
                                        @if($account->is_active)
                                            <span class="badge badge-success">{{ __('db.Active') }}</span>
                                        @elseif($isPaymentMapped)
                                            <span class="badge badge-secondary">{{ __('db.Inactive (Payment Account)') }}</span>
                                        @else
                                            <span class="badge badge-secondary">{{ __('db.Inactive') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isPaymentMapped)
                                            <span class="badge badge-info">{{ __('db.Payment Account') }}</span>
                                        @elseif($isProtected)
                                            <span class="badge badge-warning">{{ __('db.Protected') }}</span>
                                        @else
                                            <span class="badge badge-light">{{ __('db.Editable') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('accounting.chart-of-accounts.edit', $account) }}" class="btn btn-sm btn-primary">
                                            <i class="ti ti-pencil"></i> {{ __('db.edit') }}
                                        </a>
                                        @if($isPaymentMapped)
                                            <a href="{{ route('accounts.index') }}" class="btn btn-sm btn-outline-info" title="{{ __('db.Managed under Payment Accounts') }}">
                                                <i class="ti ti-link"></i> {{ __('db.Managed under Payment Accounts') }}
                                            </a>
                                        @else
                                            <form action="{{ route('accounting.chart-of-accounts.destroy', $account) }}" method="POST" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger" data-confirm-message="{{ __('db.Delete this accounting account? Protected accounts will be blocked automatically.') }}">
                                                    <i class="ti ti-trash"></i> {{ __('db.delete') }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
    $("ul#account").addClass("show");
    $("#chart-of-accounts-menu").addClass("active");
</script>
@endpush
