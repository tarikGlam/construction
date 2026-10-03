@extends('backend.layout.main')

@push('css')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.9/css/intlTelInput.css"/>
<style>
    .vcard-person-wrap { position: relative; }
    .vcard-person-results { position:absolute; z-index:1050; left:0; right:0; top:100%; background:#fff; border:1px solid #ddd; border-radius:0 0 4px 4px; max-height:260px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,.08); display:none; }
    .vcard-person-result { padding:10px 12px; cursor:pointer; border-bottom:1px solid #f1f1f1; }
    .vcard-person-result:hover { background:#f7f7f7; }
    .vcard-person-result:last-child { border-bottom:0; }
    .vcard-linked-badge { display:inline-flex; align-items:center; gap:6px; margin-top:7px; }
    .vcard-slug-status { min-height:18px; display:block; margin-top:5px; }
    .iti { width:100%; }
</style>
@endpush

@section('content')
<section>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>{{ $profile->exists ? 'Edit vCard Profile' : 'Create vCard Profile' }}</h3>
        <a href="{{ route('vcardnfc.profiles.index') }}" class="btn btn-outline-secondary">Back</a>
    </div>

    @if(session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form id="vcard-profile-form" method="POST" enctype="multipart/form-data" action="{{ $profile->exists ? route('vcardnfc.profiles.update', $profile) : route('vcardnfc.profiles.store') }}">
        @csrf
        @if($profile->exists) @method('PUT') @endif

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="mb-2">Linked SalePro Person</h5>
                <p class="text-muted small mb-3">Search an existing SalePro user or HR employee. Selecting a person fills the profile fields, but you can edit them independently before saving.</p>
                <div class="row">
                    <div class="form-group col-lg-8 mb-0 vcard-person-wrap">
                        <label>Linked Person</label>
                        <input type="hidden" id="linked_person" name="linked_person" value="{{ old('linked_person', $linkedPerson['id'] ?? '') }}">
                        <input type="text" id="linked_person_search" class="form-control" autocomplete="off"
                               placeholder="Type at least 2 characters to search users / employees..."
                               value="{{ old('linked_person_label', $linkedPerson['label'] ?? '') }}">
                        <div id="linked_person_results" class="vcard-person-results"></div>
                        <div id="linked_person_current" class="vcard-linked-badge {{ ($linkedPerson || old('linked_person')) ? '' : 'd-none' }}">
                            <span class="badge badge-info">Linked</span>
                            <button type="button" class="btn btn-link btn-sm p-0" id="clear_linked_person">Clear link</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-3"><div class="card-body">
                    <h5 class="mb-3">Profile</h5>
                    <div class="row">
                        <div class="form-group col-md-6">
                            <label>Name *</label>
                            <input id="vcard_name" class="form-control" name="name" required value="{{ old('name', $profile->name) }}">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Public slug *</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text">/vcard/</span></div>
                                <input id="vcard_slug" class="form-control" name="slug" required placeholder="john-doe" value="{{ old('slug', $profile->slug) }}">
                            </div>
                            <small id="slug_status" class="vcard-slug-status text-muted">Auto-generated from the name. You may edit it.</small>
                        </div>
                        <div class="form-group col-md-6"><label>Designation</label><input id="vcard_designation" class="form-control" name="designation" value="{{ old('designation', $profile->designation) }}"></div>
                        <div class="form-group col-md-6"><label>Company</label><input id="vcard_company" class="form-control" name="company" value="{{ old('company', $profile->company) }}"></div>

                        <div class="form-group col-md-6">
                            <label>Phone</label>
                            <input type="tel" id="phone_display" class="form-control" value="{{ old('phone', $profile->phone) }}">
                            <input type="hidden" id="phone" name="phone" value="{{ old('phone', $profile->phone) }}">
                        </div>
                        <div class="form-group col-md-6">
                            <label>WhatsApp</label>
                            <input type="tel" id="whatsapp_display" class="form-control" value="{{ old('whatsapp', $profile->whatsapp) }}">
                            <input type="hidden" id="whatsapp" name="whatsapp" value="{{ old('whatsapp', $profile->whatsapp) }}">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="whatsapp_same_as_phone">
                                <label class="form-check-label" for="whatsapp_same_as_phone">WhatsApp number is same as phone</label>
                            </div>
                        </div>

                        <div class="form-group col-md-6"><label>Email</label><input id="vcard_email" type="email" class="form-control" name="email" value="{{ old('email', $profile->email) }}"></div>
                        <div class="form-group col-md-6"><label>Website</label><input id="vcard_website" class="form-control" name="website" placeholder="https://example.com" value="{{ old('website', $profile->website) }}"></div>
                        <div class="form-group col-12"><label>Address</label><input id="vcard_address" class="form-control" name="address" value="{{ old('address', $profile->address) }}"></div>
                        <div class="form-group col-12"><label>Short bio</label><textarea class="form-control" rows="4" name="bio">{{ old('bio', $profile->bio) }}</textarea></div>
                    </div>
                </div></div>

                <div class="card mb-3"><div class="card-body">
                    <h5 class="mb-3">Social links</h5>
                    <div class="row">
                        @foreach($platforms as $platform)
                            <div class="form-group col-md-6">
                                <label>{{ $platform === 'x' ? 'X / Twitter' : ucfirst($platform) }}</label>
                                <input class="form-control" name="social_links[{{ $platform }}]" placeholder="https://" value="{{ old('social_links.'.$platform, optional($socialLinks->get($platform))->url) }}">
                            </div>
                        @endforeach
                    </div>
                </div></div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3"><div class="card-body">
                    <h5 class="mb-3">Photo & status</h5>
                    @if($profile->profile_photo)
                        @php
                            $profilePhotoUrl = config('database.connections.saleprosaas_landlord')
                                ? tenant_asset($profile->profile_photo)
                                : asset('storage/' . $profile->profile_photo);
                        @endphp
                        <img src="{{ $profilePhotoUrl }}" alt="" style="width:100px;height:100px;object-fit:cover;border-radius:50%;" class="mb-2">
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" value="1" name="remove_profile_photo" id="remove_profile_photo"><label class="form-check-label" for="remove_profile_photo">Remove current photo</label></div>
                    @endif
                    <div class="form-group"><label>Profile photo</label><input type="file" name="profile_photo" class="form-control-file" accept="image/jpeg,image/png,image/webp"></div>
                    <input type="hidden" name="is_active" value="0">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" {{ old('is_active', $profile->is_active) ? 'checked' : '' }}><label class="form-check-label" for="is_active">Active public profile</label></div>
                </div></div>

                @if($profile->exists)
                <div class="card mb-3"><div class="card-body">
                    <h5>Share</h5>
                    <p class="small mb-2"><a target="_blank" href="{{ route('vcardnfc.public.show', $profile->slug) }}">Open public profile</a></p>
                    <img src="{{ route('vcardnfc.public.qr', $profile->slug) }}" alt="QR" style="max-width:220px;width:100%;height:auto;">
                    <a class="btn btn-outline-primary btn-block mt-2" href="{{ route('vcardnfc.public.qr', ['slug' => $profile->slug, 'download' => 1]) }}">Download QR SVG</a>
                </div></div>
                @endif

                <button class="btn btn-primary btn-block" type="submit">{{ $profile->exists ? 'Save Changes' : 'Create Profile' }}</button>
            </div>
        </div>
    </form>
</div>
</section>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.19/js/intlTelInput.min.js"></script>
<script>
(function () {
    const profileId = {{ $profile->exists ? (int) $profile->id : 0 }};
    const searchUrl = @json(route('vcardnfc.people.search'));
    const detailsUrl = @json(route('vcardnfc.people.details'));
    const slugUrl = @json(route('vcardnfc.profiles.check-slug'));

    const phoneInput = document.querySelector('#phone_display');
    const whatsappInput = document.querySelector('#whatsapp_display');
    const itiOptions = {
        initialCountry: 'auto',
        geoIpLookup: function (callback) {
            fetch('https://ipapi.co/json')
                .then(res => res.json())
                .then(data => callback(data.country_code || 'us'))
                .catch(() => callback('us'));
        },
        utilsScript: 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.19/js/utils.js'
    };
    const phoneIti = window.intlTelInput(phoneInput, itiOptions);
    const whatsappIti = window.intlTelInput(whatsappInput, itiOptions);
    if ($('#phone').val()) phoneIti.setNumber($('#phone').val());
    if ($('#whatsapp').val()) whatsappIti.setNumber($('#whatsapp').val());

    function syncPhoneHidden() {
        const phoneNumber = phoneIti.getNumber() || phoneInput.value.trim();
        $('#phone').val(phoneNumber);
        if ($('#whatsapp_same_as_phone').is(':checked')) {
            whatsappIti.setNumber(phoneNumber);
        }
        $('#whatsapp').val(whatsappIti.getNumber() || whatsappInput.value.trim());
    }

    $('#whatsapp_same_as_phone').on('change', function () {
        if (this.checked) {
            whatsappIti.setNumber(phoneIti.getNumber() || phoneInput.value.trim());
            whatsappInput.setAttribute('readonly', 'readonly');
        } else {
            whatsappInput.removeAttribute('readonly');
        }
        syncPhoneHidden();
    });
    phoneInput.addEventListener('change', syncPhoneHidden);
    phoneInput.addEventListener('keyup', function () {
        if ($('#whatsapp_same_as_phone').is(':checked')) syncPhoneHidden();
    });
    whatsappInput.addEventListener('change', syncPhoneHidden);

    let slugTouched = $('#vcard_slug').val().trim() !== '';
    let slugTimer = null;
    let searchTimer = null;

    function slugify(text) {
        return String(text || '')
            .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .substring(0, 100);
    }

    function checkSlug() {
        const slug = slugify($('#vcard_slug').val());
        $('#vcard_slug').val(slug);
        if (!slug) {
            $('#slug_status').removeClass('text-success text-danger').addClass('text-muted').text('Enter a public slug.');
            return;
        }
        $('#slug_status').removeClass('text-success text-danger').addClass('text-muted').text('Checking availability...');
        $.getJSON(slugUrl, {slug: slug, exclude: profileId})
            .done(function (response) {
                $('#vcard_slug').val(response.slug || slug);
                $('#slug_status')
                    .toggleClass('text-success', !!response.available)
                    .toggleClass('text-danger', !response.available)
                    .removeClass('text-muted')
                    .text(response.available ? '✓ Available' : '✕ ' + response.message);
            })
            .fail(function () {
                $('#slug_status').removeClass('text-success').addClass('text-danger').text('Could not validate the slug. It will still be checked when you save.');
            });
    }

    $('#vcard_name').on('input', function () {
        if (!slugTouched) {
            $('#vcard_slug').val(slugify(this.value));
            clearTimeout(slugTimer);
            slugTimer = setTimeout(checkSlug, 350);
        }
    });
    $('#vcard_slug').on('input', function () {
        slugTouched = true;
        clearTimeout(slugTimer);
        slugTimer = setTimeout(checkSlug, 350);
    });
    if ($('#vcard_slug').val()) checkSlug();

    function hidePersonResults() {
        $('#linked_person_results').hide().empty();
    }

    $('#linked_person_search').on('input', function () {
        const q = this.value.trim();
        clearTimeout(searchTimer);
        if (q.length < 2) {
            hidePersonResults();
            return;
        }
        searchTimer = setTimeout(function () {
            $.getJSON(searchUrl, {q: q}).done(function (response) {
                const box = $('#linked_person_results').empty();
                const results = response.results || [];
                if (!results.length) {
                    box.append('<div class="vcard-person-result text-muted">No matching user or employee</div>').show();
                    return;
                }
                results.forEach(function (item) {
                    $('<div class="vcard-person-result"></div>')
                        .text(item.label)
                        .attr('data-id', item.id)
                        .attr('data-label', item.label)
                        .appendTo(box);
                });
                box.show();
            });
        }, 250);
    });

    $(document).on('click', '.vcard-person-result[data-id]', function () {
        const id = $(this).data('id');
        const label = $(this).data('label');
        $('#linked_person').val(id);
        $('#linked_person_search').val(label);
        $('#linked_person_current').removeClass('d-none');
        hidePersonResults();

        $.getJSON(detailsUrl, {person: id}).done(function (response) {
            const p = response.person || {};
            if (p.name != null) $('#vcard_name').val(p.name).trigger('input');
            if (p.designation != null) $('#vcard_designation').val(p.designation);
            if (p.company != null) $('#vcard_company').val(p.company);
            if (p.email != null) $('#vcard_email').val(p.email);
            if (p.address != null) $('#vcard_address').val(p.address);
            if (p.phone) phoneIti.setNumber(p.phone);
            if (p.whatsapp) whatsappIti.setNumber(p.whatsapp);
            syncPhoneHidden();
        });
    });

    $('#clear_linked_person').on('click', function () {
        $('#linked_person').val('');
        $('#linked_person_search').val('');
        $('#linked_person_current').addClass('d-none');
        hidePersonResults();
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.vcard-person-wrap').length) hidePersonResults();
    });

    $('#vcard-profile-form').on('submit', function () {
        syncPhoneHidden();
    });
})();
</script>
@endpush
