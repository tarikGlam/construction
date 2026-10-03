<?php

namespace App\Http\Controllers;

use Mail;
use Stripe\Stripe;
use App\Models\Sale;
use App\Models\User;
use App\Models\Point;
use App\Models\Account;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Returns;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Supplier;
use App\Models\PosSetting;
use App\Models\CustomField;
use App\Models\MailSetting;
use App\Models\RewardPoint;
use App\Mail\CustomerCreate;
use App\Mail\SupplierCreate;
use App\Models\CashRegister;
use App\Models\DiscountPlan;
use Illuminate\Http\Request;
use App\Mail\CustomerDeposit;
use App\Models\CustomerGroup;
use Illuminate\Support\Carbon;
use App\Enums\CustomerTypeEnum;
use App\Models\WhatsappSetting;
use Illuminate\Validation\Rule;
use App\Models\PaymentWithCheque;
use App\Enums\RewardPointTypeEnum;
use App\Models\RewardPointSetting;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use App\Enums\DiscountPlanTypeEnum;
use App\Models\PaymentWithGiftCard;
use App\Models\DiscountPlanCustomer;
use App\Models\GeneralSetting;
use App\Models\InvoiceSetting;
use Illuminate\Support\Facades\Auth;
use App\Models\PaymentWithCreditCard;
use App\Models\Warehouse;
use Spatie\Permission\Models\Permission;

class CustomerController extends Controller
{
    use \App\Traits\CacheForget;
    use \App\Traits\MailInfo;

    public function __construct(
        public \App\Services\AccountingService $accountingService,
        public \App\Services\CustomerDepositService $customerDeposits,
        public \App\Services\PaymentAccountService $paymentAccounts,
        public \App\Services\WarehouseAccessService $warehouseAccess,
    )
    {
    }

    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('customers-index')){
            $permissions = Role::findByName($role->name)->permissions;
            foreach ($permissions as $permission)
                $all_permission[] = $permission->name;
            if(empty($all_permission))
                $all_permission[] = 'dummy text';
            $custom_fields = CustomField::where([
                                ['belongs_to', 'customer'],
                                ['is_table', true]
                            ])->pluck('name');
            $field_name = [];
            foreach($custom_fields as $fieldName) {
                $field_name[] = str_replace(" ", "_", strtolower($fieldName));
            }

            $lims_account_list = app(\App\Services\PaymentAccountService::class)->validOperationalAccounts();
            $lims_gift_card_list = GiftCard::where("is_active", true)->get();
            $lims_reward_point_setting_data = RewardPointSetting::latest()->first();
            $lims_pos_setting_data = PosSetting::latest()->first();
            $lims_warehouse_list = Warehouse::where('is_active', true)->orderBy('name')->get();
            $deposit_default_warehouse_id = $this->warehouseAccess->warehouseId();
            if($lims_pos_setting_data)
                $options = explode(',', $lims_pos_setting_data->payment_options);
            else
                $options = [];

            return view('backend.customer.index', compact('all_permission', 'custom_fields', 'field_name','options', 'lims_reward_point_setting_data', 'lims_gift_card_list', 'lims_account_list', 'lims_pos_setting_data', 'lims_warehouse_list', 'deposit_default_warehouse_id'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function customerData(Request $request)
    {
        $lims_reward_point_setting_data = RewardPointSetting::latest()->first();
        $q = Customer::where('is_active', true);
        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'created_at';
        $dir = $request->input('order.0.dir');
        //fetching custom fields data
        $custom_fields = CustomField::where([
                        ['belongs_to', 'customer'],
                        ['is_table', true]
                    ])->pluck('name');
        $field_names = [];
        foreach($custom_fields as $fieldName) {
            $field_names[] = str_replace(" ", "_", strtolower($fieldName));
        }

        $q = $q->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $customers = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->with('discountPlans', 'customerGroup')
                ->where(function ($query) use ($search, $field_names) {

                    $query->where('customers.name', 'LIKE', "%{$search}%")
                        ->orWhere('customers.company_name', 'LIKE', "%{$search}%")
                        ->orWhere('customers.phone_number', 'LIKE', "%{$search}%");

                    foreach ($field_names as $field_name) {
                        $query->orWhere('customers.' . $field_name, 'LIKE', "%{$search}%");
                    }
                });

            $customers = $q->get();
            $totalFiltered = $q->count();
        }
        $data = array();
        if(!empty($customers))
        {
            $customerIds = $customers->pluck('id')->all();
            $customerDues = app(\App\Services\ReceivableReconciliationService::class)->operationalBalances($customerIds);

            foreach ($customers as $key=>$customer)
            {
                $nestedData['id'] = $customer->id;
                $nestedData['key'] = $key;
                $nestedData['customer_group'] = $customer->customerGroup->name;
                $nestedData['customer_details'] = $customer->name;
                if($customer->company_name)
                    $nestedData['customer_details'] .= '<br>'.$customer->company_name;
                if($customer->email)
                    $nestedData['customer_details'] .= '<br>'.$customer->email;
                $nestedData['customer_details'] .= '<br>'.$customer->phone_number.'<br>'.$customer->address.'<br>'.$customer->city;
                if($customer->country)
                    $nestedData['customer_details'] .= '<br>'.$customer->country;

                $nestedData['discount_plan'] = '';
                foreach($customer->discountPlans as $index => $discount_plan) {
                    if($index)
                        $nestedData['discount_plan'] .= ', '.$discount_plan->name;
                    else
                        $nestedData['discount_plan'] .= $discount_plan->name;
                }

                $nestedData['reward_point'] = $customer->points;
                $nestedData['deposited_balance'] = number_format($customer->deposit - $customer->expense, 2);

                $total_due = $customerDues[$customer->id] ?? 0.0;
                $nestedData['total_due'] = number_format($total_due, 2);

                //fetching custom fields data
                foreach($field_names as $field_name) {
                    $nestedData[$field_name] = $customer->$field_name;
                }

                $nestedData['options'] = '<div class="btn-group">
                            <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'.__("db.action").'
                            <span class="caret"></span>
                            <span class="sr-only">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">';

                if($customer->type != 'walkin'){
                    if(in_array("customers-index", $request['all_permission'])){
                        $nestedData['options'] .= '<li>
                            <a href="'.route('customer.show', $customer->id).'" class="btn btn-link"><i class="ti ti-eye"></i> '.__('db.Customer Details').'</a>
                            </li>';
                    }
                }

                if(in_array("customers-edit", $request['all_permission'])){
                    $nestedData['options'] .= '<li>
                        <a href="'.route('customer.edit', $customer->id).'" class="btn btn-link"><i class="ti ti-edit"></i> '.__('db.edit').'</a>
                        </li>';
                }
                if($customer->type != 'walkin'){
                    if(in_array("due-report", $request['all_permission'])) {
                        $nestedData['options'] .= '<li><form action="'.route('report.customerDueByDate').'" method = "post" id = "due-report-form">
                                '.csrf_field().'
                                <input type="hidden" name="start_date" value="'.date('Y-m-d', strtotime('-30 year')).'" />
                                <input type="hidden" name="end_date" value="'.date('Y-m-d').'" />
                                <input type="hidden" name="customer_id" value="'.$customer->id.'" />
                                <button type="submit" class="btn btn-link"><i class="ti ti-receipt-2"></i>'.__('db.Due Report').'</button></form>
                        </li>';
                    }

                    if ($total_due > 0) {
                        $nestedData['options'] .=
                            '<li>
                                <button type="button" data-id="'.$customer->id.'" class="clear-due btn btn-link" data-toggle="modal" data-target="#clearDueModal" ><i class="ti ti-brush"></i>'.__('db.Clear Due').'</button>
                            </li>';
                    }

                    $nestedData['options'] .=
                        '<li>
                            <button type="button" data-id="'.$customer->id.'" class="deposit btn btn-link" data-toggle="modal" data-target="#depositModal" ><i class="ti ti-plus"></i>'.__('db.Add Deposit').'</button>
                        </li>';

                    $nestedData['options'] .=
                        '<li>
                            <button type="button" data-id="'.$customer->id.'" class="getDeposit btn btn-link" ><i class="ti ti-cash-banknote"></i>'.__('db.View Deposit').'</button>
                        </li>';

                    $settings = WhatsappSetting::first();
                    if (!$settings || empty($settings->phone_number_id) || empty($settings->permanent_access_token)) {
                        $phone = preg_replace('/\D/', '', $customer->wa_number ?? '');
                        $href = "https://web.whatsapp.com/send/?phone={$phone}";
                    } else {
                        $href = route('whatsapp.send.page', [
                            'group' => 'Customers',
                            'phone' => preg_replace('/\D/', '', $customer->wa_number ?? '')
                        ]);
                    }
                    if(isset($customer->wa_number) && !empty($customer->wa_number)){
                        $nestedData['options'] .=
                            '<li>
                                <a href="'.$href.'" class="btn btn-link">
                                    <i class="ti ti-brand-whatsapp"></i> '.__('db.Whatsapp Notification').'
                                </a>
                            </li>';
                    }


                    if(isset($lims_reward_point_setting_data) && $lims_reward_point_setting_data->is_active == 1){
                        $nestedData['options'] .=
                            '<li>
                                <button type="button" data-id="'.$customer->id.'" class="point btn btn-link" data-toggle="modal" data-target="#pointModal" ><i class="ti ti-plus"></i>'.__('db.Add Point').'</button>
                            </li>';

                        $nestedData['options'] .=
                            '<li>
                                <button type="button" data-id="'.$customer->id.'" class="getPoints btn btn-link" ><i class="ti ti-cash-banknote"></i>'.__('db.View Points').'</button>
                            </li>';
                    }

                }

                if(in_array("customers-delete", $request['all_permission']))
                    $nestedData['options'] .= '<form action="'.route("customer.destroy", $customer->id).'" method="POST">'.csrf_field().'' . method_field("DELETE") . '
                            <li>
                              <button type="submit" class="btn btn-link" data-confirm-message="' . __('db.Are you sure want to delete?') . '"><i class="ti ti-trash"></i> '.__("db.delete").'</button>
                            </li></form>
                        </ul>
                    </div>';

                $data[] = $nestedData;
            }
        }

        $json_data = array(
            "draw"            => intval($request->input('draw')),
            "recordsTotal"    => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data"            => $data
        );
        echo json_encode($json_data);
    }

    public function clearDue(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if (!$role || !$role->hasPermissionTo('customers-edit')) {
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
        }

        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'amount' => 'required|numeric|min:0.0001',
        ]);

        $data = $request->all();
        $scale = 4;
        $submittedAmount = bcadd((string)$request->amount, '0', $scale);
        if (in_array(strtolower((string) ($data['paid_by_id'] ?? '')), ['7', 'points'], true)) {
            app(\App\Services\RewardPointService::class)->sweepExpiredPoints((int) $request->customer_id);
        }

        return DB::transaction(function () use ($request, $data, $submittedAmount, $scale) {
            $receivables = app(\App\Services\ReceivableReconciliationService::class);
            $query = $receivables->receivableSalesQuery((int) $request->customer_id)
                ->select('sales.id')
                ->whereRaw($receivables->dueExpression() . ' > 0.0001')
                ->orderBy('sales.id', 'asc');

            app(\App\Services\WarehouseAccessService::class)->scope($query, 'sales.warehouse_id');
            $dueSaleIds = $query->pluck('sales.id');

            if ($dueSaleIds->isEmpty()) {
                return redirect()->back()->with('not_permitted', __('db.No due found for this customer'));
            }

            // 1. Global Lock Order: Lock Sale rows FIRST in deterministic ID order
            $dueSales = Sale::whereIn('id', $dueSaleIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // 2. Global Lock Order: Lock Customer row SECOND
            $lims_customer_data = Customer::whereKey($request->customer_id)->lockForUpdate()->firstOrFail();
            if (strtolower((string) $lims_customer_data->type) === 'walkin') {
                throw new \App\Exceptions\SaleValidationException(__('db.customer_deposit_walkin_unavailable'));
            }

            // 3. Recalculate authoritative due under lock for each sale using centralized return/refund logic
            $saleDues = [];
            $totalEligibleDue = '0.0000';
            foreach ($dueSales as $sale) {
                $returned = (string) DB::table('returns')->where('sale_id', $sale->id)->sum('grand_total');
                $refunded = (string) DB::table('payments')
                    ->join('returns', 'returns.id', '=', 'payments.return_id')
                    ->where('returns.sale_id', $sale->id)
                    ->sum('payments.amount');

                $due = bcadd(bcsub(bcsub((string)$sale->grand_total, (string)$sale->paid_amount, $scale), $returned, $scale), $refunded, $scale);
                if (bccomp($due, '0.0001', $scale) === 1) {
                    $saleDues[$sale->id] = $due;
                    $totalEligibleDue = bcadd($totalEligibleDue, $due, $scale);
                }
            }

            if (bccomp($totalEligibleDue, '0.0001', $scale) <= 0) {
                return redirect()->back()->with('not_permitted', __('db.No due found for this customer'));
            }

            // 4. Calculate authoritative allocatable amount = min(submitted, totalDue)
            $allocatableAmount = bccomp($submittedAmount, $totalEligibleDue, $scale) === 1
                ? $totalEligibleDue
                : $submittedAmount;

            // 5. Authoritative payment method resolution
            $methodStr = strtolower((string) ($data['paid_by_id'] ?? '1'));
            $payingMethod = match ($methodStr) {
                '1', 'cash' => 'Cash',
                '2', 'gift_card', 'gift card' => 'Gift Card',
                '3', 'credit_card', 'credit card', 'card' => 'Credit Card',
                '4', 'cheque' => 'Cheque',
                '5', 'paypal' => 'Paypal',
                '6', 'deposit' => 'Deposit',
                '7', 'points' => 'Points',
                default => 'Cash',
            };

            // 6. Validate available deposit against allocatable amount, NOT raw submitted amount
            if ($payingMethod === 'Deposit') {
                $availableDeposit = $this->customerDeposits->available($lims_customer_data);
                if (bccomp($allocatableAmount, $availableDeposit, $scale) === 1) {
                    throw new \App\Exceptions\SaleValidationException(__('db.Amount exceeds customer deposit!'));
                }
            }

            // 7. Pure BCMath scale 4 per-sale allocation and consumption
            $remainingToAllocate = $allocatableAmount;
            $totalDepositConsumed = '0.0000';
            $defaultAccount = Account::where('is_default', 1)->first() ?? Account::first();
            $lims_payment_data = null;
            $sale_data = null;

            foreach ($dueSales as $saleItem) {
                if (bccomp($remainingToAllocate, '0.0001', $scale) <= 0) {
                    break;
                }

                $saleDue = $saleDues[$saleItem->id] ?? '0.0000';
                if (bccomp($saleDue, '0.0001', $scale) <= 0) {
                    continue;
                }

                $allocatedAmount = bccomp($remainingToAllocate, $saleDue, $scale) === 1
                    ? $saleDue
                    : $remainingToAllocate;

                $newPaid = bcadd((string)$saleItem->paid_amount, $allocatedAmount, $scale);
                $saleItem->paid_amount = $newPaid;
                $remainingDueForSale = bcsub($saleDue, $allocatedAmount, $scale);
                $saleItem->payment_status = (bccomp($remainingDueForSale, '0.0001', $scale) <= 0) ? 4 : 2;
                $saleItem->save();

                $lims_cash_register_data = CashRegister::select('id')
                    ->where([
                        ['user_id', Auth::id()],
                        ['warehouse_id', $saleItem->warehouse_id],
                        ['status', 1]
                    ])->first();

                $lims_payment_data = new Payment();
                $lims_payment_data->user_id = Auth::id() ?? 1;
                $lims_payment_data->sale_id = $saleItem->id;
                $lims_payment_data->cash_register_id = $lims_cash_register_data->id ?? null;
                $lims_payment_data->account_id = $defaultAccount?->id ?? 1;
                $lims_payment_data->payment_reference = 'spr-' . date("Ymd") . '-' . date("his") . '-' . $saleItem->id;
                $lims_payment_data->amount = $allocatedAmount;
                $lims_payment_data->change = 0;
                $lims_payment_data->currency_id = $saleItem->currency_id;
                $lims_payment_data->exchange_rate = $saleItem->exchange_rate;
                $lims_payment_data->paying_method = $payingMethod;
                $lims_payment_data->payment_note = $data['payment_note'] ?? null;
                $lims_payment_data->payment_receiver = $data['payment_receiver'] ?? null;
                $lims_payment_data->save();

                if ($payingMethod === 'Deposit') {
                    $this->customerDeposits->consume((int)$lims_customer_data->id, $allocatedAmount);
                    $totalDepositConsumed = bcadd($totalDepositConsumed, $allocatedAmount, $scale);
                } elseif ($payingMethod === 'Points') {
                    app(\App\Services\RewardPointService::class)->redeemPayment($lims_payment_data, $saleItem);
                }

                $result = $this->accountingService->recordPayment($lims_payment_data);
                if (!$result->success) {
                    \Log::error('Accounting failed for Due Clearance Payment', ['payment_id' => $lims_payment_data->id, 'error' => $result->error]);
                    throw new \App\Exceptions\SaleValidationException($result->error ?: __('db.customer_deposit_payment_failed'));
                }

                // Payment::save() refreshes against the gross invoice. Restore
                // the return-aware status calculated under the same sale lock.
                $returnAwareStatus = (bccomp($remainingDueForSale, '0.0001', $scale) <= 0) ? 4 : 2;
                DB::table('sales')->where('id', $saleItem->id)->update(['payment_status' => $returnAwareStatus]);
                $saleItem->payment_status = $returnAwareStatus;

                $remainingToAllocate = bcsub($remainingToAllocate, $allocatedAmount, $scale);
                $sale_data = $saleItem;
            }

            if ($payingMethod === 'Deposit' && bccomp($totalDepositConsumed, $allocatableAmount, $scale) !== 0) {
                throw new \App\Exceptions\SaleValidationException('Deposit consumed amount does not match total allocated payment.');
            }

            $message = __('db.Payment created successfully');

            if (isset($data['print_receipt']) && $data['print_receipt'] == 1 && $sale_data) {
                $general_setting = cache()->get('general_setting');
                $invoice_settings = InvoiceSetting::latest()->first();
                $lims_warehouse_data = Warehouse::query()->findOrFail($sale_data->warehouse_id);
                $cheque_no = ($payingMethod === 'Cheque') ? ($data['cheque_no'] ?? null) : null;

                return view('backend.sale.payment_receipt', compact(
                    'lims_payment_data',
                    'lims_customer_data',
                    'general_setting',
                    'invoice_settings',
                    'lims_warehouse_data',
                    'cheque_no',
                    'message'
                ));
            }

            return redirect()->back()->with('message', __('db.Due cleared successfully'));
        });
    }

    public function create()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('customers-add')){
            $lims_customer_group_all = CustomerGroup::where('is_active',true)->get();
            $custom_fields = CustomField::where('belongs_to', 'customer')->get();
            return view('backend.customer.create', compact('lims_customer_group_all', 'custom_fields'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'phone_number' => [
                'max:255',
                Rule::unique('customers')->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'zatca_registration_scheme' => ['nullable', Rule::in(['CRN', '700', 'NAT', 'IQA', 'TIN', 'PAS', 'GCC', 'OTH'])],
            'zatca_registration_number' => ['nullable', 'alpha_num', 'max:20'],
            'zatca_building_number' => ['nullable', 'digits:4'],
            'zatca_additional_number' => ['nullable', 'digits:4'],
            'zatca_district' => ['nullable', 'string', 'max:255'],
        ]);
        //validation for supplier if create both user and supplier
        if(isset($request->both)) {
            $this->validate($request, [
                'company_name' => [
                    'max:255',
                    Rule::unique('suppliers')->where(function ($query) {
                        return $query->where('is_active', 1);
                    }),
                ],
                'email' => [
                    'max:255',
                    Rule::unique('suppliers')->where(function ($query) {
                        return $query->where('is_active', 1);
                    }),
                ],
            ]);
        }
        //validation for user if given user access
        if(isset($request->user)) {
            $this->validate($request, [
                'name' => [
                    'max:255',
                    Rule::unique('users')->where(function ($query) {
                        return $query->where('is_deleted', false);
                    }),
                ],
                'email' => [
                    'email',
                    'max:255',
                    Rule::unique('users')->where(function ($query) {
                        return $query->where('is_deleted', false);
                    }),
                ],
            ]);
        }
        $customer_data = $request->all();

        try {
        DB::beginTransaction();

        $customer_data['is_active'] = true;
        $prefixMessage = 'Customer';
        if(isset($request->user)) {
            $customer_data['phone'] = $customer_data['phone_number'];
            $customer_data['role_id'] = 5;
            $customer_data['is_deleted'] = false;
            $customer_data['password'] = bcrypt($customer_data['password']);
            $user = User::create($customer_data);
            $customer_data['user_id'] = $user->id;
            $prefixMessage .= ', User';
        }
        $customer_data['name'] = $customer_data['customer_name'] ?? $customer_data['name'] ?? null;
        $type = $customer_data['type'] ?? 'regular';
        if ($type === 'walkin' || $type === \App\Enums\CustomerTypeEnum::WALKIN->value) {
            $customer_data['type'] = 'walkin';
            $customer_data['credit_limit'] = 0;
            $customer_data['pay_term_no'] = null;
            $customer_data['pay_term_period'] = 'days';
        } else {
            if (array_key_exists('credit_limit', $customer_data)) {
                $val = $customer_data['credit_limit'];
                if ($val === null || $val === '') {
                    $customer_data['credit_limit'] = null;
                } else {
                    $customer_data['credit_limit'] = (float)$val;
                }
            } else {
                $customer_data['credit_limit'] = null;
            }

            if (empty($customer_data['pay_term_no'])) {
                $customer_data['pay_term_no'] = null;
                $customer_data['pay_term_period'] = 'days';
            }
        }
        if(isset($request->both)) {
            Supplier::create($customer_data);
            $prefixMessage .= ' and Supplier';
        }

        $lims_customer_data = Customer::create($customer_data);

        // create dummy sale if customer has opening balance (due)
        if(isset($customer_data['opening_balance']) && $customer_data['opening_balance'] > 0) {
            $lims_sale_data = new Sale();
            $lims_sale_data->reference_no = 'cob-' . date("Ymd") . '-'. date("his"); //customer opening balance
            $lims_sale_data->customer_id = $lims_customer_data->id;
            $lims_sale_data->user_id = Auth::id();
            $lims_sale_data->biller_id = 0;
            $lims_sale_data->warehouse_id = 1;
            $lims_sale_data->item = 0;
            $lims_sale_data->total_qty = 0;
            $lims_sale_data->total_discount = 0;
            $lims_sale_data->total_tax = 0;
            $lims_sale_data->total_price = $customer_data['opening_balance'];
            $lims_sale_data->grand_total = $customer_data['opening_balance'];
            $lims_sale_data->sale_status = 1; // completed
            $lims_sale_data->payment_status = 1; // pending
            $lims_sale_data->sale_type = 'Opening balance';
            $lims_sale_data->save();

            $this->accountingService->recordCustomerOpeningBalance($lims_customer_data);
        } else {
            $customer_data['opening_balance'] = 0;
        }

        //inserting data for custom fields
        $custom_field_data = [];
        $custom_fields = CustomField::where('belongs_to', 'customer')->select('name', 'type')->get();
        foreach ($custom_fields as $type => $custom_field) {
            $field_name = str_replace(' ', '_', strtolower($custom_field->name));
            if(isset($customer_data[$field_name])) {
                if($custom_field->type == 'checkbox' || $custom_field->type == 'multi_select')
                    $custom_field_data[$field_name] = implode(",", $customer_data[$field_name]);
                else
                    $custom_field_data[$field_name] = $customer_data[$field_name];
            }
        }
        if(count($custom_field_data))
            DB::table('customers')->where('id', $lims_customer_data->id)->update($custom_field_data);
        $this->cacheForget('customer_list');
        $customerInfo['id'] = $lims_customer_data->id;
        $customerInfo['name'] = $lims_customer_data->name;
        $customerInfo['phone_number'] = $lims_customer_data->phone_number;
        $customerInfo['type'] = $lims_customer_data->type;
        $customerInfo['credit_limit'] = $lims_customer_data->credit_limit;

        $lims_discount_plan_data = DiscountPlan::where([
            'is_active' => true,
            'type' => DiscountPlanTypeEnum::GENERIC->value
        ])->get();
        foreach ($lims_discount_plan_data as $dp) {
            DiscountPlanCustomer::create([
                'discount_plan_id' => $dp->id,
                'customer_id' => $lims_customer_data->id
            ]);
        }

        if ($lims_customer_data->deposit > 0) {
            $deposit = Deposit::create([
                'user_id' => Auth::id() ?? 1,
                'customer_id' => $lims_customer_data->id,
                'amount' => $lims_customer_data->deposit,
                'deposit_type' => 'opening',
                'account_id' => null,
                'note' => 'Initial Deposit',
            ]);

            $result = $this->accountingService->recordCustomerOpeningDeposit($deposit);
            if (!$result->success) {
                throw new \App\Exceptions\SaleValidationException($result->error ?: 'Failed to record opening deposit journal');
            }
        }

        DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Customer creation failed: ' . $e->getMessage());
            if(isset($customer_data['pos']) && $customer_data['pos'])
                return response()->json(['error' => $e->getMessage()], 500);
            else
                return redirect()->back()->with('not_permitted', 'Customer creation failed: ' . $e->getMessage());
        }

        $fullMessage = $prefixMessage.' created successfully!';
        $mail_setting = MailSetting::latest()->first();
        $message = $this->mailAction($customer_data, $mail_setting, $request, $fullMessage);

        if(isset($customer_data['pos']) && $customer_data['pos'])
            return $customerInfo;
        else
            return redirect('customer')->with('create_message', $message);
    }

    public function show($id)
    {
        $customer = Customer::findOrFail($id);
        $reconciliationService = app(\App\Services\ReceivableReconciliationService::class);

        $opening_balance = (float) ($customer->opening_balance ?? 0);

        $salesQuery = $reconciliationService->salesQuery($id);
        $total_sales = (float) (clone $salesQuery)->sum('sales.grand_total');

        $total_paid = (float) Payment::join('sales', 'sales.id', '=', 'payments.sale_id')
            ->where('sales.customer_id', $id)
            ->whereNull('payments.return_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', \App\Services\ReceivableReconciliationService::DRAFT_STATUS)
            ->whereNull('sales.voided_at')
            ->where(function ($q) {
                $q->whereNull('sales.accounting_status')
                    ->orWhereNotIn('sales.accounting_status', ['reversed', 'voided']);
            })
            ->where(function ($q) {
                $q->whereNull('payments.accounting_status')
                    ->orWhereNotIn('payments.accounting_status', ['reversed', 'voided']);
            })
            ->sum('payments.amount');

        $returnsSum = (float) DB::table('returns')
            ->where('customer_id', $id)
            ->where(function ($q) {
                $q->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
            })
            ->sum('grand_total');

        $refundsSum = (float) DB::table('payments')
            ->join('returns', 'returns.id', '=', 'payments.return_id')
            ->where('returns.customer_id', $id)
            ->where(function ($q) {
                $q->whereNull('payments.accounting_status')
                    ->orWhereNotIn('payments.accounting_status', ['reversed', 'voided']);
            })
            ->sum('payments.amount');

        $total_returns = max(0, $returnsSum - $refundsSum);
        $net_sales = $total_sales - $total_returns;
        $balance_due = $reconciliationService->operationalBalance($id);

        return view('backend.customer.view', [
            'lims_customer_data' => $customer,
            'opening_balance'   => $opening_balance,
            'total_sales'       => $total_sales,
            'net_sales'         => $net_sales,
            'total_paid'        => $total_paid,
            'total_returns'     => $total_returns,
            'balance_due'       => $balance_due,
        ]);
    }

    public function ledger($id)
    {
        $customer = Customer::findOrFail($id);

        $opening = collect();
        $openingSale = Sale::where('customer_id', $id)
            ->whereNull('deleted_at')
            ->whereNull('voided_at')
            ->whereRaw("LOWER(COALESCE(sale_type, '')) = ?", ['opening balance'])
            ->orderBy('id')
            ->first();

        if ($openingSale) {
            $opening->push([
                'id' => $openingSale->id,
                'date' => $openingSale->created_at,
                'type' => 'Opening balance',
                'reference' => $openingSale->reference_no ?: 'Opening Balance',
                'debit' => floatval($openingSale->grand_total),
                'credit' => 0,
            ]);
        } elseif ((float) ($customer->opening_balance ?? 0) != 0) {
            // Legacy fallback for customers created before synthetic opening-balance
            // sales were introduced.
            $opening->push([
                'id' => 0,
                'date' => $customer->created_at,
                'type' => 'Opening balance',
                'reference' => 'Opening Balance',
                'debit' => floatval($customer->opening_balance),
                'credit' => 0,
            ]);
        }

        $sales = Sale::where('customer_id', $id)
            ->whereNull('deleted_at')
            ->where('sale_status', '!=', \App\Services\ReceivableReconciliationService::DRAFT_STATUS)
            ->whereNull('voided_at')
            ->where(function ($q) {
                $q->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
            })
            ->where(function ($q) {
                $q->whereNull('sale_type')
                    ->orWhereRaw('LOWER(sale_type) <> ?', ['opening balance']);
            })
            ->get()->map(function ($s) {
                return [
                    'id' => $s->id,
                    'date' => $s->created_at,
                    'type' => $s->sale_type ?? 'Sale',
                    'reference' => $s->reference_no,
                    'debit' => floatval($s->grand_total),
                    'credit' => 0,
                ];
            });

        $paymentSaleIds = $sales->pluck('id');
        if ($openingSale) {
            $paymentSaleIds->push($openingSale->id);
        }

        $payments = Payment::whereIn('sale_id', $paymentSaleIds->unique()->values())
                    ->whereNull('return_id')
                    ->where(function ($q) {
                        $q->whereNull('accounting_status')
                          ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
                    })
                    ->get()
                    ->map(function ($p) {
                        return [
                            'id'        => $p->id,
                            'date'      => $p->date ?? $p->created_at,
                            'type'      => 'Payment',
                            'reference' => $p->payment_reference ?? '-',
                            'debit'     => 0,
                            'credit'    => floatval($p->amount),
                        ];
                    });

        $returns = Returns::with('refundPayments')->where('customer_id', $id)
            ->where(function ($q) {
                $q->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
            })
            ->get()->map(function($r) {
                return [
                    'id' => $r->id,
                    'date' => $r->created_at,
                    'type' => 'Sale Return',
                    'reference' => $r->reference_no,
                    'debit' => 0,
                    'credit' => floatval($r->display_grand_total),
                ];
            });

        $ledger = $opening
                    ->merge($sales)
                    ->merge($payments)
                    ->merge($returns)
                    ->sortBy(function($item) {
                        $priority = [
                            'Opening balance' => 1,
                            'Sale' => 2,
                            'Sale Return' => 3,
                            'Payment' => 4,
                            'Refund' => 5,
                        ];
                        $p = $priority[$item['type']] ?? 2;
                        return \Carbon\Carbon::parse($item['date'])->getTimestamp() . '-' . $p;
                    })
                    ->values();

        $balance = 0;

        $ledger = $ledger->map(function ($row) use (&$balance) {
            $balance += ($row['debit'] - $row['credit']);
            $row['balance'] = round($balance, 2);
            return $row;
        });

        $ledger = $ledger->reverse()->values();

        return response()->json(['data' => $ledger]);
    }

    public function installments($id)
    {
        $installments = DB::table('installments as i')
            ->join('installment_plans as p', 'i.installment_plan_id', '=', 'p.id')

            ->leftJoin('sales as s', 'p.reference_id', '=', 's.id')
            ->leftJoin('purchases as pur', 'p.reference_id', '=', 'pur.id')

            ->where(function($q) use ($id){
                $q->where('s.customer_id', $id)
                ->orWhere('pur.supplier_id', $id);
            })

            ->select(
                'i.created_at as date',
                's.reference_no as sale_reference',
                'pur.reference_no as purchase_reference',
                'i.id as installment_no',
                'i.amount',
                'i.status',
                'i.payment_date'
            )
            ->get()
            ->map(function($row){
                return [
                    'date' => $row->date,
                    'sale_reference' => $row->sale_reference ?? 'N/A',
                    'purchase_reference' => $row->purchase_reference ?? 'N/A',
                    'installment_no' => $row->installment_no,
                    'amount' => $row->amount,
                    'status' => $row->status,
                    'payment_date' => $row->payment_date
                ];
            });

        return response()->json(['data' => $installments]);
    }

    public function edit($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('customers-edit')){
            $lims_customer_data = Customer::find($id);
            $lims_customer_group_all = CustomerGroup::where('is_active',true)->get();
            $custom_fields = CustomField::where('belongs_to', 'customer')->get();
            return view('backend.customer.edit', compact('lims_customer_data','lims_customer_group_all', 'custom_fields'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'phone_number' => [
                'max:255',
                    Rule::unique('customers')->ignore($id)->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'zatca_registration_scheme' => ['nullable', Rule::in(['CRN', '700', 'NAT', 'IQA', 'TIN', 'PAS', 'GCC', 'OTH'])],
            'zatca_registration_number' => ['nullable', 'alpha_num', 'max:20'],
            'zatca_building_number' => ['nullable', 'digits:4'],
            'zatca_additional_number' => ['nullable', 'digits:4'],
            'zatca_district' => ['nullable', 'string', 'max:255'],
        ]);

        $input = $request->all();
        $lims_customer_data = Customer::find($id);

        try {
        DB::beginTransaction();

        if(isset($input['user'])) {
            $this->validate($request, [
                'name' => [
                    'max:255',
                        Rule::unique('users')->where(function ($query) {
                        return $query->where('is_deleted', false);
                    }),
                ],
                'email' => [
                    'email',
                    'max:255',
                        Rule::unique('users')->where(function ($query) {
                        return $query->where('is_deleted', false);
                    }),
                ],
            ]);

            $input['phone'] = $input['phone_number'];
            $input['role_id'] = 5;
            $input['is_active'] = true;
            $input['is_deleted'] = false;
            $input['password'] = bcrypt($input['password']);
            $user = User::create($input);
            $input['user_id'] = $user->id;
            $message = __('db.customer_updated_and_user_created');
        }
        else {
            $message = __('db.customer_updated_successfully');
        }

        $input['name'] = $input['customer_name'];
        $type = $input['type'] ?? $lims_customer_data->type ?? 'regular';
        if ($type === 'walkin' || $type === \App\Enums\CustomerTypeEnum::WALKIN->value) {
            $input['type'] = 'walkin';
            $input['credit_limit'] = 0;
            $input['pay_term_no'] = null;
            $input['pay_term_period'] = 'days';
        } else {
            if (array_key_exists('credit_limit', $input)) {
                $val = $input['credit_limit'];
                if ($val === null || $val === '') {
                    $input['credit_limit'] = null;
                } else {
                    $input['credit_limit'] = (float)$val;
                }
            }

            if (empty($input['pay_term_no'])) {
                $input['pay_term_no'] = null;
                $input['pay_term_period'] = 'days';
            }
        }

        $old_opening_balance = $lims_customer_data->opening_balance;

        $lims_customer_data->update($input);

        if ($old_opening_balance != $lims_customer_data->opening_balance) {
            $this->accountingService->reverseTransaction(get_class($lims_customer_data), $lims_customer_data->id);
            if ($lims_customer_data->opening_balance > 0) {
                $this->accountingService->recordCustomerOpeningBalance($lims_customer_data, 'customer_opening_balance_updated');
            }
        }

        //update custom field data
        $custom_field_data = [];
        $custom_fields = CustomField::where('belongs_to', 'customer')->select('name', 'type')->get();
        foreach ($custom_fields as $type => $custom_field) {
            $field_name = str_replace(' ', '_', strtolower($custom_field->name));
            if(isset($input[$field_name])) {
                if($custom_field->type == 'checkbox' || $custom_field->type == 'multi_select')
                    $custom_field_data[$field_name] = implode(",", $input[$field_name]);
                else
                    $custom_field_data[$field_name] = $input[$field_name];
            }
        }
        if(count($custom_field_data))
            DB::table('customers')->where('id', $lims_customer_data->id)->update($custom_field_data);
        $this->cacheForget('customer_list');

        DB::commit();
        return redirect('customer')->with('edit_message', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Customer update failed: ' . $e->getMessage());
            return redirect()->back()->with('not_permitted', 'Customer update failed: ' . $e->getMessage());
        }
    }

    public function importCustomer(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role && $role->hasPermissionTo('customers-add')){
            $upload=$request->file('file');
            $ext = pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION);
            if($ext != 'csv')
                return redirect()->back()->with('not_permitted', __('db.Please upload a CSV file'));
            $filename =  $upload->getClientOriginalName();
            $filePath = $upload->getRealPath();

            $content = file_get_contents($filePath);
            if (str_starts_with($content, "\xEF\xBB\xBF")) {
                $content = substr($content, 3);
            }
            if (!mb_check_encoding($content, 'UTF-8')) {
                $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $content);
                if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                    $content = $converted;
                } else {
                    $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252, ISO-8859-1, ASCII');
                }
            }
            $content = mb_scrub($content, 'UTF-8');

            //open and read
            $file = fopen('php://memory', 'r+');
            fwrite($file, $content);
            rewind($file);

            $header = fgetcsv($file);
            $escapedHeader=[];
            //validate
            foreach ($header as $key => $value) {
                $lheader=strtolower($value);
                $escapedItem=preg_replace('/[^a-z]/', '', $lheader);
                array_push($escapedHeader, $escapedItem);
            }

            $mail_setting = MailSetting::latest()->first();

            $lims_discount_plan_data = DiscountPlan::where([
                'is_active' => true,
                'type' => DiscountPlanTypeEnum::GENERIC->value
            ])->get();

            $message = __('db.Customer Imported Successfully');

            try {
                DB::beginTransaction();

                //looping through other columns
                while($columns=fgetcsv($file))
                {
                    if(empty($columns) || empty($columns[0]))
                        continue;
                    if(count($escapedHeader) !== count($columns))
                        continue;

                    $data= array_combine($escapedHeader, $columns);
                    $customerGroupName = trim($data['customergroup'] ?? '');
                    $lims_customer_group_data = CustomerGroup::where('name', $customerGroupName)->first() ?? CustomerGroup::first();

                    $customer = Customer::firstOrNew(['name'=>$data['name']]);
                    $customer->customer_group_id = $lims_customer_group_data ? $lims_customer_group_data->id : null;
                    $customer->name = $data['name'];
                    $customer->company_name = $data['companyname'] ?? null;
                    $customer->email = $data['email'] ?? null;
                    $customer->phone_number = $data['phonenumber'] ?? null;
                    $customer->address = $data['address'] ?? null;
                    $customer->city = $data['city'] ?? null;
                    $customer->state = $data['state'] ?? null;
                    $customer->postal_code = $data['postalcode'] ?? null;
                    $customer->country = $data['country'] ?? null;

                    $depositAmount = 0;
                    if (isset($data['deposit'])) {
                        $dVal = trim((string)$data['deposit']);
                        if ($dVal === '' || strtolower($dVal) === 'null') {
                            if (!$customer->exists) {
                                $customer->deposit = null;
                            }
                        } else {
                            $depositAmount = (float)$dVal;
                            $customer->deposit = $depositAmount;
                        }
                    }

                    if (isset($data['creditlimit'])) {
                        $cVal = trim((string)$data['creditlimit']);
                        if ($cVal === '' || strtolower($cVal) === 'null') {
                            $customer->credit_limit = null;
                        } else {
                            $customer->credit_limit = (float)$cVal;
                        }
                    }
                    $customer->is_active = true;
                    $customer->save();

                    foreach ($lims_discount_plan_data as $dp) {
                        DiscountPlanCustomer::firstOrCreate([
                            'discount_plan_id' => $dp->id,
                            'customer_id' => $customer->id
                        ]);
                    }

                    if ($depositAmount > 0) {
                        $deposit = Deposit::create([
                            'user_id' => Auth::id() ?? 1,
                            'customer_id' => $customer->id,
                            'amount' => $depositAmount,
                            'deposit_type' => 'opening',
                            'account_id' => null,
                            'note' => 'Initial Deposit',
                        ]);

                        $result = $this->accountingService->recordCustomerOpeningDeposit($deposit);
                        if (!$result->success) {
                            throw new \App\Exceptions\SaleValidationException($result->error ?: 'Failed to record opening deposit journal');
                        }
                    }

                    $message = $this->mailAction($data, $mail_setting, $request, 'Customer Imported Successfully');
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                if (is_resource($file)) {
                    fclose($file);
                }
                \Log::error('Customer import failed: ' . $e->getMessage());
                $cleanMsg = mb_scrub($e->getMessage(), 'UTF-8');
                return redirect()->back()->with('not_permitted', 'Customer import failed: ' . $cleanMsg);
            }

            if (is_resource($file)) {
                fclose($file);
            }

            $this->cacheForget('customer_list');
            return redirect('customer')->with('import_message', $message);
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function getDeposit($id)
    {
        $lims_deposit_list = Deposit::where('customer_id', $id)->get();
        $deposit_id = [];
        $deposits = [];
        foreach ($lims_deposit_list as $deposit) {
            $deposit_id[] = $deposit->id;
            $date[] = $deposit->created_at->toDateString() . ' '. $deposit->created_at->toTimeString();
            $amount[] = $deposit->amount;
            $note[] = $deposit->note;
            $warehouse_id[] = $deposit->warehouse_id;
            $lims_user_data = User::find($deposit->user_id);
            $name[] = $lims_user_data->name;
            $email[] = $lims_user_data->email;
        }
        if(!empty($deposit_id)){
            $deposits[] = $deposit_id;
            $deposits[] = $date;
            $deposits[] = $amount;
            $deposits[] = $note;
            $deposits[] = $name;
            $deposits[] = $email;
            $deposits[] = $warehouse_id;
        }
        return $deposits;
    }

    public function addDeposit(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if (!$role || !$role->hasPermissionTo('customers-edit')) {
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
        }

        $originalRequestedWarehouse = $request->attributes->get('requested_warehouse_id');
        if ((int) $originalRequestedWarehouse > 0) {
            $this->warehouseAccess->authorizeWarehouse((int) $originalRequestedWarehouse);
        }

        $request->merge([
            'warehouse_id' => $request->input('warehouse_id') ?: Auth::user()?->warehouse_id,
            'request_token' => $request->input('request_token') ?: (string) \Illuminate\Support\Str::uuid(),
        ]);

        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'amount' => 'required|numeric|min:0.0001',
            'account_id' => 'required|integer|exists:accounts,id',
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'request_token' => 'required|uuid',
            'note' => 'nullable|string',
        ]);

        $this->warehouseAccess->authorizeWarehouse((int) $validated['warehouse_id']);

        $this->paymentAccounts->assertValidDepositReceivingAccountId((int) $validated['account_id']);

        try {
            return DB::transaction(function () use ($validated, $request) {
                $existing = Deposit::where('request_token', $validated['request_token'])->first();
                if ($existing) {
                    abort_unless((int) $existing->customer_id === (int) $validated['customer_id'], 409, 'Deposit request token conflict.');
                    return redirect('customer')->with('create_message', __('db.Data inserted successfully'));
                }

                $customer = $this->customerDeposits->addGross((int) $validated['customer_id'], $validated['amount']);

                $deposit = Deposit::create([
                    'user_id' => Auth::id() ?? 1,
                    'customer_id' => $customer->id,
                    'amount' => $validated['amount'],
                    'warehouse_id' => (int) $validated['warehouse_id'],
                    'request_token' => $validated['request_token'],
                    'deposit_type' => 'live',
                    'account_id' => $validated['account_id'],
                    'note' => $validated['note'] ?? null,
                ]);

                $result = $this->accountingService->recordDeposit($deposit, 'deposit_created');
                if (!$result->success) {
                    \Log::error('Accounting failed for Deposit', ['deposit_id' => $deposit->id, 'error' => $result->error]);
                    throw new \App\Exceptions\SaleValidationException($result->error ?: __('db.customer_deposit_payment_failed'));
                }

                $message = __('db.Data inserted successfully');
                $mail_setting = MailSetting::latest()->first();

                if ($customer->email && $mail_setting) {
                    $data = $validated;
                    $data['name'] = $customer->name;
                    $data['email'] = $customer->email;
                    $data['balance'] = bcsub((string) $customer->deposit, (string) $customer->expense, 4);
                    $data['currency'] = config('currency');
                    $message = $this->mailAction($data, $mail_setting, $request);
                }

                return redirect('customer')->with('create_message', $message);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            $duplicate = Deposit::where('request_token', $validated['request_token'])
                ->where('customer_id', $validated['customer_id'])->exists();
            if ($duplicate) {
                return redirect('customer')->with('create_message', __('db.Data inserted successfully'));
            }

            throw $exception;
        }
    }

    public function updateDeposit(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if (!$role || !$role->hasPermissionTo('customers-edit')) {
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
        }

        $originalRequestedWarehouse = $request->attributes->get('requested_warehouse_id');
        if ((int) $originalRequestedWarehouse > 0) {
            $this->warehouseAccess->authorizeWarehouse((int) $originalRequestedWarehouse);
        }

        $validated = $request->validate([
            'deposit_id' => 'required|integer|exists:deposits,id',
            'amount' => 'required|numeric|min:0.0001',
            'account_id' => 'nullable|integer',
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'note' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated) {
            $deposit = Deposit::findOrFail($validated['deposit_id']);
            if (class_exists(\Modules\Construction\Entities\ProjectReceipt::class)
                && \Modules\Construction\Entities\ProjectReceipt::where('deposit_id', $deposit->id)->exists()) {
                throw new \App\Exceptions\SaleValidationException('This deposit is linked to a Construction Project Receipt and must be managed from Project Receipts.');
            }
            $isDoubleEntry = app(\App\Services\AccountingModeService::class)->isDoubleEntryAuthoritative();

            if ($isDoubleEntry && $deposit->deposit_type === null) {
                throw new \App\Exceptions\SaleValidationException(__('db.historical_unclassified_deposit_cannot_be_edited_without_remediation'));
            }

            $oldAmount = $deposit->amount;
            $newAmount = $validated['amount'];
            $customerId = (int) $deposit->customer_id;

            // Enforce gross-versus-consumed invariant under lock
            $this->customerDeposits->updateGross($customerId, $oldAmount, $newAmount);

            $deposit->amount = $newAmount;
            $deposit->note = $validated['note'] ?? null;

            if ($deposit->deposit_type === 'live') {
                if (empty($validated['account_id'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'account_id' => __('db.Account is required!'),
                    ]);
                }
                $this->paymentAccounts->assertValidDepositReceivingAccountId((int) $validated['account_id']);
                $warehouseId = (int) ($validated['warehouse_id'] ?? $deposit->warehouse_id);
                $this->warehouseAccess->authorizeWarehouse($warehouseId);
                if (!$warehouseId) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'warehouse_id' => __('db.Warehouse is required!'),
                    ]);
                }
                $deposit->account_id = (int) $validated['account_id'];
                $deposit->warehouse_id = $warehouseId;
                $deposit->save();

                $revResult = $this->accountingService->reverseTransaction(get_class($deposit), $deposit->id, '_reversed');
                if (!$revResult->success) {
                    throw new \App\Exceptions\SaleValidationException($revResult->error ?: __('db.customer_deposit_payment_failed'));
                }

                $postResult = $this->accountingService->recordDeposit($deposit, 'deposit_updated');
                if (!$postResult->success) {
                    throw new \App\Exceptions\SaleValidationException($postResult->error ?: __('db.customer_deposit_payment_failed'));
                }
            } elseif ($deposit->deposit_type === 'opening') {
                $deposit->account_id = null;
                $deposit->save();

                $revResult = $this->accountingService->reverseTransaction(get_class($deposit), $deposit->id, '_reversed');
                if (!$revResult->success) {
                    throw new \App\Exceptions\SaleValidationException($revResult->error ?: __('db.customer_deposit_payment_failed'));
                }

                $postResult = $this->accountingService->recordCustomerOpeningDeposit($deposit, 'customer_opening_deposit_updated');
                if (!$postResult->success) {
                    throw new \App\Exceptions\SaleValidationException($postResult->error ?: __('db.customer_deposit_payment_failed'));
                }
            } else {
                // Legacy mode with unclassified deposit
                $deposit->save();
            }

            return redirect('customer')->with('create_message', __('db.Data updated successfully'));
        });
    }

    public function deleteDeposit(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if (!$role || !$role->hasPermissionTo('customers-delete')) {
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
        }

        $validated = $request->validate([
            'id' => 'required|integer|exists:deposits,id',
        ]);

        return DB::transaction(function () use ($validated) {
            $deposit = Deposit::findOrFail($validated['id']);
            if (class_exists(\Modules\Construction\Entities\ProjectReceipt::class)
                && \Modules\Construction\Entities\ProjectReceipt::where('deposit_id', $deposit->id)->exists()) {
                throw new \App\Exceptions\SaleValidationException('This deposit is linked to a Construction Project Receipt and must be managed from Project Receipts.');
            }
            $isDoubleEntry = app(\App\Services\AccountingModeService::class)->isDoubleEntryAuthoritative();

            if ($isDoubleEntry && $deposit->deposit_type === null) {
                throw new \App\Exceptions\SaleValidationException(__('db.historical_unclassified_deposit_cannot_be_deleted_without_remediation'));
            }

            // Enforce gross-versus-consumed invariant under lock
            $this->customerDeposits->removeGross((int) $deposit->customer_id, $deposit->amount);

            if ($deposit->deposit_type === 'live' || $deposit->deposit_type === 'opening') {
                $revResult = $this->accountingService->reverseTransaction(get_class($deposit), $deposit->id, '_deleted');
                if (!$revResult->success) {
                    throw new \App\Exceptions\SaleValidationException($revResult->error ?: __('db.customer_deposit_payment_failed'));
                }
            }

            $deposit->delete();

            return redirect('customer')->with('not_permitted', __('db.Data deleted successfully'));
        });
    }

    public function addPoint(Request $request)
    {
        $request->validate([
            'customer_id' => 'required',
            'points' => 'required',
        ]);
        try{
            DB::beginTransaction();
                $data = $request->all();
                $data['reward_point_type'] = RewardPointTypeEnum::MANUAL->value;
                $point =RewardPoint::query()->create($data);
                $lims_customer_data = Customer::query()->findOrFail($request->customer_id);
                $lims_customer_data->update(['points' => $point->points + ($lims_customer_data->points ?? 0)]);
            DB::commit();
            $message = __('db.Data inserted successfully');
            return redirect('customer')->with('create_message', $message);
        }catch(\Throwable $e){
            DB::rollBack();
            return redirect()->back()->with('error','Somthing wrong please try again');
        }
    }

     public function getPoints($id)
    {
        $lims_point_list = RewardPoint::where('customer_id', $id)->get();
        $point_id = [];
        $points = [];
        foreach ($lims_point_list as $point) {
            $point_id[] = $point->id;
            $date[] = $point->created_at->toDateString() . ' '. $point->created_at->toTimeString();
            $amount[] = $point->points;
            $note[] = $point->note;
            $lims_user_data = User::find($point->created_by);
            $name[] = $lims_user_data->name;
            $email[] = $lims_user_data->email;
            $reward_point_type[] = $point->reward_point_type;
            $deducted_points[] = $point->deducted_points;
        }
        if(!empty($point_id)){
            $points[] = $point_id;
            $points[] = $date;
            $points[] = $amount;
            $points[] = $note;
            $points[] = $name;
            $points[] = $email;
            $points[] = $reward_point_type;
            $points[] = $deducted_points;
        }
        return $points;
    }

    public function updatePoint(Request $request)
    {
         $request->validate([
            'point_id' => 'required',
        ]);
        try{
            DB::beginTransaction();
                $data = $request->all();
                $point = RewardPoint::find($request->point_id);
                $lims_customer_data = Customer::find($point->customer_id);
                $lims_customer_data->points -= $point->points;
                $lims_customer_data->points += $request->points;
                $lims_customer_data->save();
                $point->points = $data['points'];
                if ($data['note']) {
                    $point->note = $data['note'];
                }
                $point->save();
            DB::commit();
            $message = __('db.Data inserted successfully');
            return redirect('customer')->with('create_message', $message);
        }catch(\Throwable $e){
            DB::rollBack();
            return redirect()->back()->with('error','Somthing wrong please try again');
        }
    }

    public function deletePoints(Request $request)
    {
        try{
            DB::beginTransaction();
                $data = $request->all();
                $point = RewardPoint::find($request->id);
                $lims_customer_data = Customer::find($point->customer_id);
                $lims_customer_data->points -= $point->points;
                $lims_customer_data->save();
                $point->delete();
            DB::commit();
            $message = __('db.Data deleted successfully');
            return redirect('customer')->with('not_permitted', $message);
        }catch(\Throwable $e){
            DB::rollBack();
            return redirect()->back()->with('error','Error Deleting Points');
        }
    }

    public function deleteBySelection(Request $request)
    {
        $customer_id = $request['customerIdArray'];
        foreach ($customer_id as $id) {
            $lims_customer_data = Customer::find($id);

            $lims_discount_plan_data = DiscountPlan::where([
                'is_active' => true,
                'type' => DiscountPlanTypeEnum::GENERIC->value
            ])->get();
            foreach ($lims_discount_plan_data as $dp) {
                DiscountPlanCustomer::where([
                    'discount_plan_id' => $dp->id,
                    'customer_id' => $lims_customer_data->id
                ])->first()->delete();
            }

            $lims_customer_data->is_active = false;
            $lims_customer_data->save();
        }
        $this->cacheForget('customer_list');
        return 'Customer deleted successfully!';
    }

    public function destroy($id)
    {
        try {
        DB::beginTransaction();

        $lims_customer_data = Customer::find($id);

        $lims_discount_plan_data = DiscountPlan::where([
            'is_active' => true,
            'type' => DiscountPlanTypeEnum::GENERIC->value
        ])->get();
        foreach ($lims_discount_plan_data as $dp) {
            DiscountPlanCustomer::where([
                'discount_plan_id' => $dp->id,
                'customer_id' => $lims_customer_data->id
            ])->first()->delete();
        }

        $lims_customer_data->is_active = false;
        $lims_customer_data->save();
        $this->cacheForget('customer_list');

        DB::commit();
        return redirect('customer')->with('not_permitted', __('db.Data deleted successfully'));

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Customer deletion failed: ' . $e->getMessage());
            return redirect()->back()->with('not_permitted', 'Customer deletion failed: ' . $e->getMessage());
        }
    }

    protected function mailAction($data, $mailSetting, $request, $customMessage=null)
    {
        $message = $customMessage ?? __('db.Data inserted successfully');
        if(!$mailSetting) {
            $message = __('db.Data inserted successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.');
        }
        else if($data['email'] && $mailSetting) {
            try{
                $this->setMailInfo($mailSetting);
                Mail::to($data['email'])->send(new CustomerCreate($data));
                if(isset($request->both))
                    Mail::to($data['email'])->send(new SupplierCreate($data));
            }
            catch(\Exception $e){
                $message = $e->getMessage();
            }
        }
        return $message;
    }

    public function customersAll()
    {
        $lims_customer_list = DB::table('customers')->where('is_active', true)->get();

        $html = '';
        foreach($lims_customer_list as $customer){
            $html .='<option value="'.$customer->id.'">'.$customer->name . ' (' . $customer->phone_number. ')'.'</option>';
        }

        return response()->json($html);
    }

    public function customerPayments($customer_id)
    {

        $payments = DB::table('payments')
            ->join('sales', 'payments.sale_id', '=', 'sales.id')
            ->where('sales.customer_id', $customer_id)
            ->whereNull('return_id')
            ->whereNull('sales.deleted_at')
            ->where(function ($q) {
                $q->whereNull('payments.accounting_status')
                  ->orWhereNotIn('payments.accounting_status', ['reversed', 'voided']);
            })
            ->select(
                'payments.id',
                'payments.created_at',
                'payments.payment_reference',
                'payments.amount',
                'payments.paying_method',
                'payments.payment_at'
            )
            ->latest('payments.created_at');
        app(\App\Services\WarehouseAccessService::class)->scope($payments, 'sales.warehouse_id');
        $payments = $payments->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'created_at' => $payment->created_at ? date('Y-m-d', strtotime($payment->created_at)) : '-',
                    'payment_reference' => $payment->payment_reference ?? '-',
                    'amount' => number_format($payment->amount, 2),
                    'paying_method' => ucfirst($payment->paying_method ?? '-'),
                    'payment_at' => $payment->payment_at
                        ? date('Y-m-d H:i', strtotime($payment->payment_at))
                        : date('Y-m-d H:i', strtotime($payment->created_at)),
                ];
            });

        return response()->json(['data' => $payments]);
    }

    public function customerReturns($id)
    {
        $returns = Returns::leftJoin('sales', 'returns.sale_id', '=', 'sales.id')
            ->leftJoin('warehouses', 'returns.warehouse_id', '=', 'warehouses.id')
            ->leftJoin(DB::raw('(select return_id, sum(amount) as refunded_amount from payments where return_id is not null and sale_id is not null group by return_id) as sale_return_refunds'), 'sale_return_refunds.return_id', '=', 'returns.id')
            ->where('returns.customer_id', $id)
            ->select(
                'returns.id',
                'returns.created_at',
                'returns.reference_no',
                'returns.item',
                'returns.total_qty',
                DB::raw('COALESCE(sale_return_refunds.refunded_amount, returns.grand_total) as grand_total'),
                'returns.return_note',
                'returns.staff_note',
                'sales.reference_no as sale_reference',
                'warehouses.name as warehouse_name'
            )
            ->latest('returns.created_at')
            ->get()
            ->map(function ($return) {
                return [
                    'id' => $return->id,
                    'date' => $return->created_at ? date(config('date_format'), strtotime($return->created_at)) : '-',
                    'reference' => $return->reference_no ?? '-',
                    'sale_reference' => $return->sale_reference ?? '-',
                    'warehouse' => $return->warehouse_name ?? '-',
                    'item' => number_format($return->item ?? 0, 0),
                    'total_qty' => number_format($return->total_qty ?? 0, cache()->get('general_setting')->decimal),
                    'grand_total' => number_format($return->grand_total ?? 0, cache()->get('general_setting')->decimal),
                    'return_note' => $return->return_note ?: '-',
                    'staff_note' => $return->staff_note ?: '-',
                ];
            });

        return response()->json(['data' => $returns]);
    }

    public function getCustomerDue($id)
    {
        $creditService = app(\App\Services\CustomerCreditService::class);
        $due = $creditService->calculateCustomerDue($id);

        return response()->json($due);
    }
}
