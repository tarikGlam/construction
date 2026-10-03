        <div id="income-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true"
            class="modal fade text-left">
            <div role="document" class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 id="exampleModalLabel" class="modal-title">{{ __('Add Income') }}</h5>
                        <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span
                                aria-hidden="true"><i class="ti ti-x"></i></span></button>
                    </div>
                    <div class="modal-body">
                        <p class="italic">
                            <small>{{ __('The field labels marked with are required input fields') }}.</small></p>
                        <form action="{{ route('incomes.store') }}" method="post">
                            @csrf
                        <?php
                        $lims_income_category_list = DB::table('income_categories')->where('is_active', true)->get();
                        if (optional(Auth::user())->role_id > 2) {
                            $lims_warehouse_list = DB::table('warehouses')
                                ->where([['is_active', true], ['id', optional(Auth::user())->warehouse_id]])
                                ->get();
                        } else {
                            $lims_warehouse_list = DB::table('warehouses')->where('is_active', true)->get();
                        }
                        $lims_account_list = app(\App\Services\PaymentAccountService::class)->validOperationalAccounts();
                        ?>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>{{ __('Date') }}</label>
                                <input type="text" name="created_at" class="form-control date"
                                    placeholder="{{ __('db.Choose date') }}"
                                    value="{{ date(gen_setting()->date_format, strtotime('now')) }}" />
                            </div>
                            <div class="col-md-6 form-group">
                                <label>{{ __('Income Category') }} *</label>
                                <select name="income_category_id" class="selectpicker form-control" required
                                    data-live-search="true" data-live-search-style="begins"
                                    title="Select Income Category...">
                                    @foreach ($lims_income_category_list as $income_category)
                                        <option value="{{ $income_category->id }}">
                                            {{ $income_category->name . ' (' . $income_category->code . ')' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>{{ __('Warehouse') }} *</label>
                                <select name="warehouse_id" class="selectpicker form-control" required
                                    data-live-search="true" data-live-search-style="begins"
                                    title="Select Warehouse...">
                                    @foreach ($lims_warehouse_list as $warehouse)
                                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>{{ __('Amount') }} *</label>
                                <input type="number" name="amount" step="any" required class="form-control">
                            </div>

                            @if(\Schema::hasColumn('incomes','project_id'))
                            <div class="col-md-6 form-group"><label>Construction Project</label><select name="project_id" class="selectpicker form-control" data-live-search="true"><option value="">None / General</option>@foreach($construction_projects ?? collect() as $project)<option value="{{$project->id}}">{{$project->title}}</option>@endforeach</select></div>
                            <div class="col-md-6 form-group"><label>Site</label><select name="site_id" class="selectpicker form-control" data-live-search="true"><option value="">None</option>@foreach($construction_sites ?? collect() as $site)<option value="{{$site->id}}">{{$site->name}}</option>@endforeach</select></div>
                            <div class="col-md-6 form-group"><label>Project Cost / Revenue Category</label><select name="cost_category_id" class="selectpicker form-control" data-live-search="true"><option value="">None</option>@foreach($construction_cost_categories ?? collect() as $category)<option value="{{$category->id}}">{{$category->name}}</option>@endforeach</select></div>
                            @endif
                            <div class="col-md-6 form-group">
                                <label> {{ __('Account') }}</label>
                                <select class="form-control selectpicker" name="account_id">
                                    @foreach ($lims_account_list as $account)
                                        @if ($account->is_default)
                                            <option selected value="{{ $account->id }}">{{ $account->name }}
                                                [{{ $account->account_no }}]</option>
                                        @else
                                            <option value="{{ $account->id }}">{{ $account->name }}
                                                [{{ $account->account_no }}]</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>{{ __('Note') }}</label>
                            <textarea name="note" rows="3" class="form-control"></textarea>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">{{ __('submit') }}</button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
