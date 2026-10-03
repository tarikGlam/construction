<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $profile->name }}{{ $profile->company ? ' - '.$profile->company : '' }}</title>
<meta name="description" content="{{ \Illuminate\Support\Str::limit($profile->bio ?: trim(($profile->designation ? $profile->designation.' at ' : '').$profile->company), 150) }}">
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f5f8;color:#202733;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}.wrap{max-width:520px;margin:0 auto;padding:30px 16px 50px}.card{background:#fff;border-radius:22px;box-shadow:0 14px 45px rgba(26,39,61,.12);overflow:hidden}.hero{height:118px;background:linear-gradient(135deg,#4c5fd7,#7b61ff)}.content{padding:0 28px 30px;text-align:center}.avatar{width:116px;height:116px;border-radius:50%;object-fit:cover;border:5px solid #fff;background:#e9edf4;margin-top:-58px;box-shadow:0 5px 18px rgba(0,0,0,.12)}.initial{display:inline-flex;align-items:center;justify-content:center;font-size:42px;font-weight:700;color:#4c5fd7}.name{font-size:28px;margin:14px 0 4px}.sub{color:#687386;margin:0 0 8px}.bio{color:#505a6b;line-height:1.55;margin:18px 0}.actions{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:22px 0}.btn{display:block;text-decoration:none;padding:12px 10px;border-radius:12px;background:#f0f3ff;color:#3446bd;font-weight:600}.btn.primary{background:#4c5fd7;color:#fff;grid-column:1/-1}.info{text-align:left;border-top:1px solid #edf0f4;margin-top:24px;padding-top:18px}.info a,.info div{display:block;color:#3c4656;text-decoration:none;padding:8px 0;word-break:break-word}.social{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:20px}.social a{padding:8px 12px;border-radius:999px;background:#f3f5f8;text-decoration:none;color:#465064;font-size:14px}.footer{text-align:center;color:#9aa2af;font-size:12px;margin-top:18px}@media(max-width:380px){.content{padding-left:18px;padding-right:18px}.actions{grid-template-columns:1fr}.btn.primary{grid-column:auto}}
</style>
</head>
<body><div class="wrap"><div class="card"><div class="hero"></div><div class="content">
@if($profile->profile_photo)
@php
    $profilePhotoUrl = config('database.connections.saleprosaas_landlord')
        ? tenant_asset($profile->profile_photo)
        : asset('storage/' . $profile->profile_photo);
@endphp
<img class="avatar" src="{{ $profilePhotoUrl }}" alt="{{ $profile->name }}">
@else
<div class="avatar initial">{{ mb_strtoupper(mb_substr($profile->name,0,1)) }}</div>
@endif
<h1 class="name">{{ $profile->name }}</h1>
@if($profile->designation || $profile->company)<p class="sub">{{ $profile->designation }}{{ $profile->designation && $profile->company ? ' · ' : '' }}{{ $profile->company }}</p>@endif
@if($profile->bio)<p class="bio">{{ $profile->bio }}</p>@endif
<div class="actions">
<a class="btn primary" href="{{ route('vcardnfc.public.vcf', ['slug'=>$profile->slug, 'source'=>request('source','direct')]) }}">Save Contact</a>
@if($profile->phone)<a class="btn" href="tel:{{ preg_replace('/[^0-9+]/','',$profile->phone) }}">Call</a>@endif
@if($profile->whatsapp)<a class="btn" target="_blank" rel="noopener" href="https://wa.me/{{ preg_replace('/[^0-9]/','',$profile->whatsapp) }}">WhatsApp</a>@endif
@if($profile->email)<a class="btn" href="mailto:{{ $profile->email }}">Email</a>@endif
@if($profile->website)<a class="btn" target="_blank" rel="noopener" href="{{ $profile->website }}">Website</a>@endif
</div>
<div class="info">
@if($profile->phone)<a href="tel:{{ preg_replace('/[^0-9+]/','',$profile->phone) }}">{{ $profile->phone }}</a>@endif
@if($profile->email)<a href="mailto:{{ $profile->email }}">{{ $profile->email }}</a>@endif
@if($profile->address)<div>{{ $profile->address }}</div>@endif
</div>
@if($profile->socialLinks->isNotEmpty())<div class="social">@foreach($profile->socialLinks as $link)<a target="_blank" rel="noopener" href="{{ $link->url }}">{{ $link->platform === 'x' ? 'X' : ucfirst($link->platform) }}</a>@endforeach</div>@endif
</div></div><div class="footer">Digital vCard powered by SalePro</div></div></body></html>
