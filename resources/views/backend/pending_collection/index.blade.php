@extends('backend.layout.main')
@section('content')

<section>
    <div class="container-fluid">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <h4>{{ __('db.Pending Collections') ?: 'Pending Collections' }}</h4>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs" id="collectionTab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="pending-tab" data-toggle="tab" href="#pending" role="tab" aria-controls="pending" aria-selected="true">{{ __('db.Pending') ?: 'Pending' }}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="approved-tab" data-toggle="tab" href="#approved" role="tab" aria-controls="approved" aria-selected="false">{{ __('db.Approved') ?: 'Approved' }}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="rejected-tab" data-toggle="tab" href="#rejected" role="tab" aria-controls="rejected" aria-selected="false">{{ __('db.Rejected') ?: 'Rejected' }} / {{ __('db.Reversed') ?: 'Reversed' }}</a>
                    </li>
                </ul>
                <div class="tab-content" id="collectionTabContent">
                    <!-- Tab 1: Pending -->
                    <div class="tab-pane fade show active" id="pending" role="tabpanel" aria-labelledby="pending-tab">
                        <div class="table-responsive mt-3">
                            <table id="pending-collection-table" class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('db.date') ?: 'Date' }}</th>
                                        <th>{{ __('db.reference') ?: 'Reference' }}</th>
                                        <th>{{ __('db.Sale Reference') ?: 'Sale Ref' }}</th>
                                        <th>{{ __('db.customer') ?: 'Customer' }}</th>
                                        <th>{{ __('db.Collected By') ?: 'Collected By' }}</th>
                                        <th>{{ __('db.Handed Over To') ?: 'Handed Over To' }}</th>
                                        <th>{{ __('db.Amount') ?: 'Amount' }}</th>
                                        <th>{{ __('db.Payment Method') ?: 'Method' }}</th>
                                        <th>{{ __('db.action') ?: 'Action' }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pendingCollections as $col)
                                    <tr>
                                        <td>{{ $col->created_at->format('Y-m-d H:i') }}</td>
                                        <td>{{ $col->reference_no }}</td>
                                        <td>{{ $col->sale ? $col->sale->reference_no : 'N/A' }}</td>
                                        <td>{{ $col->customer ? $col->customer->name : 'N/A' }}</td>
                                        <td>{{ $col->collected_by_name }}</td>
                                        <td>{{ $col->handed_over_to_name ?? 'N/A' }}</td>
                                        <td>{{ number_format($col->amount, 4) }}</td>
                                        <td>{{ $col->paying_method }}</td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                    {{ __('db.action') ?: 'Action' }} <span class="caret"></span>
                                                    <span class="sr-only">Toggle Dropdown</span>
                                                </button>
                                                <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
                                                    @can('pending_collections-approve')
                                                    <li>
                                                        <form action="{{ route('pending-collections.approve', $col->id) }}" method="POST">
                                                            @csrf
                                                            <button type="submit" class="btn btn-link"><i class="fa fa-check text-success"></i> {{ __('db.Approve') ?: 'Approve' }}</button>
                                                        </form>
                                                    </li>
                                                    @endcan
                                                    @can('pending_collections-reject')
                                                    <li>
                                                        <button type="button" class="btn btn-link reject-btn" data-id="{{$col->id}}" data-toggle="modal" data-target="#rejectModal"><i class="fa fa-times text-danger"></i> {{ __('db.Reject') ?: 'Reject' }}</button>
                                                    </li>
                                                    @endcan
                                                    @can('pending_collections-handover')
                                                    <li>
                                                        <button type="button" class="btn btn-link handover-btn" data-id="{{$col->id}}" data-toggle="modal" data-target="#handoverModal"><i class="fa fa-exchange-alt text-info"></i> {{ __('db.Handover') ?: 'Handover' }}</button>
                                                    </li>
                                                    @endcan
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 2: Approved -->
                    <div class="tab-pane fade" id="approved" role="tabpanel" aria-labelledby="approved-tab">
                        <div class="table-responsive mt-3">
                            <table id="approved-collection-table" class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('db.date') ?: 'Date' }}</th>
                                        <th>{{ __('db.reference') ?: 'Reference' }}</th>
                                        <th>{{ __('db.Sale Reference') ?: 'Sale Ref' }}</th>
                                        <th>{{ __('db.customer') ?: 'Customer' }}</th>
                                        <th>{{ __('db.Approved By') ?: 'Approved By' }}</th>
                                        <th>{{ __('db.Approved At') ?: 'Approved At' }}</th>
                                        <th>{{ __('db.Payment Reference') ?: 'Payment Ref' }}</th>
                                        <th>{{ __('db.Amount') ?: 'Amount' }}</th>
                                        <th>{{ __('db.action') ?: 'Action' }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($approvedCollections as $col)
                                    <tr>
                                        <td>{{ $col->created_at->format('Y-m-d H:i') }}</td>
                                        <td>{{ $col->reference_no }}</td>
                                        <td>{{ $col->sale ? $col->sale->reference_no : 'N/A' }}</td>
                                        <td>{{ $col->customer ? $col->customer->name : 'N/A' }}</td>
                                        <td>{{ $col->approved_by_name ?? 'N/A' }}</td>
                                        <td>{{ $col->approved_at ? $col->approved_at->format('Y-m-d H:i') : 'N/A' }}</td>
                                        <td>
                                            @if($col->payment)
                                                <a href="{{ route('sale.payment-receipt', $col->payment->id) }}" class="btn btn-link" target="_blank">{{ $col->payment->payment_reference }}</a>
                                            @else
                                                N/A
                                            @endif
                                        </td>
                                        <td>{{ number_format($col->amount, 4) }}</td>
                                        <td>
                                            @can('pending_collections-reverse')
                                            <button type="button" class="btn btn-danger btn-sm reverse-btn" data-id="{{$col->id}}" data-toggle="modal" data-target="#reverseModal">
                                                <i class="fa fa-undo"></i> {{ __('db.Reverse') ?: 'Reverse' }}
                                            </button>
                                            @endcan
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 3: Rejected / Reversed -->
                    <div class="tab-pane fade" id="rejected" role="tabpanel" aria-labelledby="rejected-tab">
                        <div class="table-responsive mt-3">
                            <table id="rejected-collection-table" class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('db.date') ?: 'Date' }}</th>
                                        <th>{{ __('db.reference') ?: 'Reference' }}</th>
                                        <th>{{ __('db.Sale Reference') ?: 'Sale Ref' }}</th>
                                        <th>{{ __('db.customer') ?: 'Customer' }}</th>
                                        <th>{{ __('db.status') ?: 'Status' }}</th>
                                        <th>{{ __('db.Actor') ?: 'Rejected/Reversed By' }}</th>
                                        <th>{{ __('db.Reason') ?: 'Reason' }}</th>
                                        <th>{{ __('db.Amount') ?: 'Amount' }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rejectedCollections as $col)
                                    <tr>
                                        <td>{{ $col->created_at->format('Y-m-d H:i') }}</td>
                                        <td>{{ $col->reference_no }}</td>
                                        <td>{{ $col->sale ? $col->sale->reference_no : 'N/A' }}</td>
                                        <td>{{ $col->customer ? $col->customer->name : 'N/A' }}</td>
                                        <td>
                                            <span class="badge {{ $col->status === 'reversed' ? 'badge-warning' : 'badge-danger' }}">
                                                {{ ucfirst($col->status) }}
                                            </span>
                                        </td>
                                        <td>{{ $col->status === 'reversed' ? $col->reversed_by_name : $col->rejected_by_name }}</td>
                                        <td>{{ $col->status === 'reversed' ? $col->reversal_reason : $col->rejection_reason }}</td>
                                        <td>{{ number_format($col->amount, 4) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Reject Modal -->
<div id="rejectModal" tabindex="-1" role="dialog" aria-labelledby="rejectModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <form id="reject-form" action="" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 id="rejectModalLabel" class="modal-title">{{ __('db.Reject') ?: 'Reject' }} Collection</h5>
                    <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true">×</span></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject this pending collection? This has no financial effect.</p>
                    <div class="form-group">
                        <label>{{ __('db.Reason') ?: 'Reason' }} *</label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">{{ __('db.Reject') ?: 'Reject' }}</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('db.Close') ?: 'Close' }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reverse Modal -->
<div id="reverseModal" tabindex="-1" role="dialog" aria-labelledby="reverseModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <form id="reverse-form" action="" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 id="reverseModalLabel" class="modal-title">{{ __('db.Reverse') ?: 'Reverse' }} Collection</h5>
                    <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true">×</span></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reverse this approved collection? This will reverse the GL journal and restore the sale due balance.</p>
                    <div class="form-group">
                        <label>{{ __('db.Reason') ?: 'Reason' }} *</label>
                        <textarea name="reversal_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">{{ __('db.Reverse') ?: 'Reverse' }}</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('db.Close') ?: 'Close' }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Handover Modal -->
<div id="handoverModal" tabindex="-1" role="dialog" aria-labelledby="handoverModalLabel" aria-hidden="true" class="modal fade text-left">
    <div role="document" class="modal-dialog">
        <div class="modal-content">
            <form id="handover-form" action="" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 id="handoverModalLabel" class="modal-title">{{ __('db.Handover') ?: 'Handover' }} Collection</h5>
                    <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true">×</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>{{ __('db.Hand Over To') ?: 'Hand Over To' }} *</label>
                        <select name="handed_over_to" class="form-control selectpicker" data-live-search="true" required>
                            @foreach($users as $u)
                                <option value="{{$u->id}}">{{$u->name}}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>{{ __('db.Handover Notes') ?: 'Handover Notes' }}</label>
                        <textarea name="handover_notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">{{ __('db.submit') ?: 'Submit' }}</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('db.Close') ?: 'Close' }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script type="text/javascript">
    $('.reject-btn').on('click', function() {
        var id = $(this).data('id');
        $('#reject-form').attr('action', '{{ url("pending-collections") }}/' + id + '/reject');
    });

    $('.reverse-btn').on('click', function() {
        var id = $(this).data('id');
        $('#reverse-form').attr('action', '{{ url("pending-collections") }}/' + id + '/reverse');
    });

    $('.handover-btn').on('click', function() {
        var id = $(this).data('id');
        $('#handover-form').attr('action', '{{ url("pending-collections") }}/' + id + '/handover');
    });

    $('#pending-collection-table, #approved-collection-table, #rejected-collection-table').DataTable({
        "order": [],
        'columnDefs': [
            {
                "orderable": false,
                'targets': [0]
            }
        ]
    });
</script>
@endpush
