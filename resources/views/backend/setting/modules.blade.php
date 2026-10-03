@extends('backend.layout.main')
@section('content')
<x-success-message key="message" />
<x-error-message key="not_permitted" />
<section class="forms">
    <div class="container-fluid">
        <div class="card mt-3">
            <div class="card-header">
                <h4 class="mb-1">Modules / Features</h4>
                <small class="text-muted">Business-level module controls</small>
            </div>
            <form method="POST" action="{{ route('setting.modules.update') }}">
                @csrf
                <div class="card-body">
                    <div class="alert alert-light border mb-4">
                        Turn off modules this business does not use. Sidebar items, module routes and registered module-specific fields are hidden/blocked. Existing role permissions are preserved and become active again if the module is re-enabled.
                    </div>

                    @foreach($modules->groupBy(fn ($module) => $module['group'] ?? 'Other', true) as $group => $groupModules)
                        <h5 class="mt-4 mb-2">{{ $group }}</h5>
                        <div class="border rounded mb-3 px-3">
                            @foreach($groupModules as $slug => $module)
                                <div class="d-flex align-items-start justify-content-between {{ !$loop->last ? 'border-bottom' : '' }} py-3">
                                    <div class="pr-4">
                                        <strong>{{ $module['label'] }}</strong>
                                        <div class="text-muted small">{{ $module['description'] }}</div>
                                    </div>
                                    <div class="custom-control custom-switch flex-shrink-0">
                                        <input class="custom-control-input" type="checkbox" name="modules[]" value="{{ $slug }}" id="module-{{ $slug }}" {{ in_array($slug, $enabled, true) ? 'checked' : '' }}>
                                        <label class="custom-control-label" for="module-{{ $slug }}">Enabled</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <div class="card-footer text-right">
                    <button class="btn btn-primary" type="submit">Save module settings</button>
                </div>
            </form>
        </div>
    </div>
</section>
@endsection
