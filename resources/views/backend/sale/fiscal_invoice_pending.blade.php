<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice not available</title>
<style>body{font-family:Arial,sans-serif;background:#f8f9fc;color:#293145;padding:32px}main{max-width:680px;margin:auto;background:white;border:1px solid #e4e6ee;border-radius:12px;padding:28px}a{color:#7c5cc4} .notice{border-left:4px solid #d69b19;padding:16px;background:#fff8e6}@media print{body{display:none}}</style></head>
<body><main>
<h2>Invoice not available for issue or printing</h2>
<p>Sale reference: <strong>{{ $reference }}</strong></p>
<p class="notice">{{ $reason }}</p>
<p>The sale has not been deleted. Resolve the fiscal document status before issuing it. Do not create the same sale again.</p>
<a href="{{ url('sales') }}">Back to sales</a>
</main></body></html>
