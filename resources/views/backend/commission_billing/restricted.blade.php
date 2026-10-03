@if(config('database.connections.saleprosaas_landlord'))
<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('Subscription invoice overdue') }}</title></head>
<body style="font:18px/1.6 system-ui;padding:40px;max-width:720px;margin:auto"><h1>{{ __('Subscription invoice overdue') }}</h1><p>{{ __('New transactions are temporarily restricted. Your records remain available. Ask your account owner to settle the outstanding subscription invoice.') }}</p><a href="{{ route('subscription.billing') }}">{{ __('Open Subscription Billing') }}</a></body></html>
@endif
