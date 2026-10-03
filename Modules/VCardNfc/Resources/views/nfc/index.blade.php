@extends('backend.layout.main')

@section('content')
<section><div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h3 class="mb-1">NFC Cards</h3><p class="text-muted mb-0">Write the permanent NFC URL to a card/tag once, then reassign it here anytime.</p></div>
        <a href="{{ route('vcardnfc.profiles.index') }}" class="btn btn-outline-secondary">vCard Profiles</a>
    </div>
    @if(session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="card mb-4"><div class="card-body">
        <h5>Create NFC Card</h5>
        <form method="POST" action="{{ route('vcardnfc.nfc.store') }}" class="row align-items-end">@csrf
            <div class="form-group col-md-4"><label>Label</label><input class="form-control" name="label" placeholder="Tarik - Black Card"></div>
            <div class="form-group col-md-4"><label>Profile</label><select class="form-control" name="vcard_profile_id"><option value="">Unassigned</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}">{{ $profile->name }}</option>@endforeach</select></div>
            <div class="form-group col-md-2"><input type="hidden" name="is_active" value="0"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="new_active" checked><label class="form-check-label" for="new_active">Active</label></div></div>
            <div class="form-group col-md-2"><button class="btn btn-primary btn-block">Create</button></div>
        </form>
    </div></div>

    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Label</th><th>Assigned Profile</th><th>NFC URL</th><th>Status</th><th>Manage</th></tr></thead><tbody>
    @forelse($cards as $card)
        <tr>
            <td>{{ $card->label ?: 'Card #'.$card->id }}</td>
            <td>{{ optional($card->profile)->name ?: 'Unassigned' }}</td>
            <td><div class="input-group input-group-sm" style="min-width:330px"><input readonly class="form-control" value="{{ route('vcardnfc.public.nfc', $card->token) }}"><div class="input-group-append"><button type="button" class="btn btn-outline-secondary copy-nfc" data-url="{{ route('vcardnfc.public.nfc', $card->token) }}">Copy</button></div></div></td>
            <td><span class="badge badge-{{ $card->is_active ? 'success' : 'secondary' }}">{{ $card->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td style="min-width:330px">
                <form method="POST" action="{{ route('vcardnfc.nfc.update', $card) }}" class="d-flex align-items-center">@csrf @method('PUT')
                    <input type="hidden" name="label" value="{{ $card->label }}">
                    <select name="vcard_profile_id" class="form-control form-control-sm mr-2"><option value="">Unassigned</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}" {{ (int)$card->vcard_profile_id === (int)$profile->id ? 'selected' : '' }}>{{ $profile->name }}</option>@endforeach</select>
                    <input type="hidden" name="is_active" value="0"><label class="mb-0 mr-2 text-nowrap"><input type="checkbox" name="is_active" value="1" {{ $card->is_active ? 'checked' : '' }}> Active</label>
                    <button class="btn btn-sm btn-primary">Save</button>
                </form>
            </td>
        </tr>
    @empty<tr><td colspan="5" class="text-center text-muted py-5">No NFC cards yet.</td></tr>@endforelse
    </tbody></table></div></div>
</div></section>
@endsection

@push('scripts')
<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.copy-nfc');
    if (!btn) return;
    navigator.clipboard.writeText(btn.dataset.url).then(function () {
        var old = btn.textContent; btn.textContent = 'Copied'; setTimeout(function(){ btn.textContent = old; }, 1200);
    });
});
</script>
@endpush
