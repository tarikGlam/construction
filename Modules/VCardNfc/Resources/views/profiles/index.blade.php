@extends('backend.layout.main')

@section('content')
<section>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-1">vCard Profiles</h3>
                <p class="text-muted mb-0">Create digital contact cards for QR and NFC sharing.</p>
            </div>
            <div>
                <a href="{{ route('vcardnfc.nfc.index') }}" class="btn btn-outline-primary mr-2">NFC Cards</a>
                <a href="{{ route('vcardnfc.profiles.create') }}" class="btn btn-primary">Add Profile</a>
            </div>
        </div>

        @if(session('message'))
            <div class="alert alert-success">{{ session('message') }}</div>
        @endif

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Profile</th><th>Public URL</th><th>Status</th><th>Views</th><th>NFC</th><th>QR</th><th>Saves</th><th class="text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($profiles as $profile)
                        <tr>
                            <td><strong>{{ $profile->name }}</strong><br><small class="text-muted">{{ $profile->designation }}{{ $profile->company ? ' · '.$profile->company : '' }}</small></td>
                            <td><a href="{{ route('vcardnfc.public.show', $profile->slug) }}" target="_blank">{{ route('vcardnfc.public.show', $profile->slug) }}</a></td>
                            <td><span class="badge badge-{{ $profile->is_active ? 'success' : 'secondary' }}">{{ $profile->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td>{{ $profile->profile_views_count }}</td>
                            <td>{{ $profile->nfc_taps_count }}</td>
                            <td>{{ $profile->qr_scans_count }}</td>
                            <td>{{ $profile->contact_saves_count }}</td>
                            <td class="text-right text-nowrap">
                                <a href="{{ route('vcardnfc.public.qr', $profile->slug) }}" target="_blank" class="btn btn-sm btn-outline-secondary">QR</a>
                                <a href="{{ route('vcardnfc.profiles.edit', $profile) }}" class="btn btn-sm btn-primary">Edit</a>
                                <form action="{{ route('vcardnfc.profiles.destroy', $profile) }}" method="POST" class="d-inline" data-confirm-message="Delete this vCard profile?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-5">No vCard profiles yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
@endsection
