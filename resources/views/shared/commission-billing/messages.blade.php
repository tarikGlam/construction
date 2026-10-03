@if(config('database.connections.saleprosaas_landlord'))
@if(session('message'))<div class="alert alert-success" role="status">{{ session('message') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
@endif
