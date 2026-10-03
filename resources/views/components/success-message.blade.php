@props(['key'])

@if(session()->has($key))
@php
    $legacyToastTypes = view()->shared('salepro_legacy_toast_types', []);
    $legacyToastTypes[$key] = 'success';
    view()->share('salepro_legacy_toast_types', $legacyToastTypes);
@endphp
@endif
