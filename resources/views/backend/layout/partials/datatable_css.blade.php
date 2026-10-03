@php
    $asset_prefix = !config('database.connections.saleprosaas_landlord') ? '' : '../../';
@endphp
<!-- table sorter stylesheet-->
<link rel="preload" href="{{ asset($asset_prefix . 'vendor/datatable/dataTables.bootstrap4.min.css') }}" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript>
    <link href="{{ asset($asset_prefix . 'vendor/datatable/dataTables.bootstrap4.min.css') }}" rel="stylesheet">
</noscript>
<link rel="preload" href="https://cdn.datatables.net/fixedheader/3.1.6/css/fixedHeader.bootstrap.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript>
    <link href="https://cdn.datatables.net/fixedheader/3.1.6/css/fixedHeader.bootstrap.min.css" rel="stylesheet">
</noscript>
<link rel="preload" href="https://cdn.datatables.net/responsive/2.2.3/css/responsive.bootstrap.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript>
    <link href="https://cdn.datatables.net/responsive/2.2.3/css/responsive.bootstrap.min.css" rel="stylesheet">
</noscript>

<style type="text/css">
    .dataTables_processing{position:fixed!important;top:50%!important;left:50%!important;z-index:1060;width:auto!important;min-width:180px;height:auto!important;margin:0!important;padding:14px 22px!important;transform:translateX(-50%);border:0!important;border-radius:8px;color:#fff!important;background:#7c5cc4!important;box-shadow:0 8px 24px rgba(45,35,75,.28);font-weight:600}
    .dataTables_processing::before{display:inline-block;width:16px;height:16px;margin-right:10px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;content:'';vertical-align:-3px;animation:.7s linear infinite sale-table-spin}
    @keyframes sale-table-spin{to{transform:rotate(360deg)}}
</style>
