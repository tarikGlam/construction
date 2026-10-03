<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\User;
use App\Models\GiftCard;
use App\Models\GiftCardRecharge;
use Illuminate\Support\Facades\Auth;
use Keygen;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

class GiftCardController extends Controller
{
    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('unit')) {
            $lims_customer_list = Customer::where('is_active', true)->get();
            $lims_user_list = User::where('is_active', true)->get();
            $lims_gift_card_all = GiftCard::where('is_active', true)->orderBy('id', 'desc')->get();

            return view('backend.gift_card.index', compact('lims_customer_list', 'lims_user_list', 'lims_gift_card_all'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function generateCode()
    {
        $id = Keygen::numeric(16)->generate();
        return $id;
    }

    public function store(Request $request)
    {
        try {
        \DB::beginTransaction();

        $this->validate($request, [
            'card_no' => [
                'max:255',
                    Rule::unique('gift_cards')->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ]
        ]);

        $data = $request->all();

        if($request->input('user'))
            $data['customer_id'] = null;
        else
            $data['user_id'] = null;

        $data['is_active'] = true;
        $data['created_by'] = Auth::id();
        $data['expense'] = 0;
        $giftCard = GiftCard::create($data);

        // === ACCOUNTING ENGINE PHASE 2E: GIFT CARD SALE ===
        $accountingService = app(\App\Services\AccountingService::class);
        $result = $accountingService->recordGiftCardSale($giftCard, 'gift_card_created');
        if (!$result->success) {
            \Log::error('Accounting failed for GiftCard creation', ['gift_card_id' => $giftCard->id, 'error' => $result->error]);
            throw new \RuntimeException($result->error ?: 'Gift card accounting posting failed.');
        }
        // ===========================================

        \DB::commit();

        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('GiftCard creation failed: ' . $e->getMessage());
            return redirect()->back()->with('not_permitted', 'GiftCard creation failed: ' . $e->getMessage());
        }

        $message = 'GiftCard created successfully';
        if($data['user_id']){
            $lims_user_data = User::find($data['user_id']);
            $data['email'] = $lims_user_data->email;
            $data['name'] = $lims_user_data->name;
            try{
                Mail::send( 'mail.gift_card_create', $data, function( $message ) use ($data)
                {
                    $message->to( $data['email'] )->subject( 'GiftCard' );
                });
            }
            catch(\Exception $e){
                $message = 'GiftCard created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        else{
            $lims_customer_data = Customer::find($data['customer_id']);
            if($lims_customer_data->email){
                $data['email'] = $lims_customer_data->email;
                $data['name'] = $lims_customer_data->name;
                try{
                    Mail::send( 'mail.gift_card_create', $data, function( $message ) use ($data)
                    {
                        $message->to( $data['email'] )->subject( 'GiftCard' );
                    });
                }
                catch(\Exception $e){
                    $message = 'GiftCard created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
                }
            }
        }
        return redirect('gift_cards')->with('message', $message);
    }

    public function edit($id)
    {
        $lims_gift_card_data = GiftCard::find($id);
        return $lims_gift_card_data;
    }

    public function update(Request $request, $id)
    {
        try {
        \DB::beginTransaction();

        $request['card_no'] = $request['card_no_edit'];
        $this->validate($request, [
            'card_no' => [
                'max:255',
                Rule::unique('gift_cards')->ignore($request['gift_card_id'])->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
            'amount_edit' => 'required|numeric|min:0.0001',
        ]);

        $data = $request->all();
        $lims_gift_card_data = GiftCard::whereKey($data['gift_card_id'])->lockForUpdate()->firstOrFail();
        $amountChanged = bccomp((string) $lims_gift_card_data->amount, (string) $data['amount_edit'], 4) !== 0;

        if ($amountChanged) {
            $hasRechargeActivity = GiftCardRecharge::where('gift_card_id', $lims_gift_card_data->id)->exists();
            $hasRedemptionActivity = bccomp((string) ($lims_gift_card_data->expense ?? 0), '0.0000', 4) > 0
                || \DB::table('payment_with_gift_card')->where('gift_card_id', $lims_gift_card_data->id)->exists();

            if ($hasRechargeActivity || $hasRedemptionActivity) {
                throw new \RuntimeException(__('db.gift_card_amount_edit_after_activity_not_allowed'));
            }

            $reversal = app(\App\Services\AccountingService::class)->reverseTransaction(
                get_class($lims_gift_card_data),
                $lims_gift_card_data->id,
                '_updated_reversal'
            );
            if (!$reversal->isSuccess() && $reversal->getMessage() !== 'No entries to reverse') {
                throw new \RuntimeException($reversal->getMessage() ?: 'Gift card accounting reversal failed.');
            }
        }
        $lims_gift_card_data->card_no = $data['card_no_edit'];
        $lims_gift_card_data->amount = $data['amount_edit'];
        if($request->input('user_edit')){
            $lims_gift_card_data->user_id = $data['user_id_edit'];
            $lims_gift_card_data->customer_id = null;
        }
        else{
            $lims_gift_card_data->user_id = null;
            $lims_gift_card_data->customer_id = $data['customer_id_edit'];
        }
        $lims_gift_card_data->expired_date = $data['expired_date_edit'];
        $lims_gift_card_data->save();

        if ($amountChanged) {
            $result = app(\App\Services\AccountingService::class)->recordGiftCardSale($lims_gift_card_data, 'gift_card_updated');
            if (!$result->isSuccess()) {
                throw new \RuntimeException($result->getMessage() ?: 'Gift card accounting posting failed.');
            }
            $lims_gift_card_data->accounting_status = $result->sourceStatus();
            $lims_gift_card_data->save();
        }

        \DB::commit();
        return redirect('gift_cards')->with('message', __('db.GiftCard updated successfully'));

        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('GiftCard update failed: ' . $e->getMessage());
            return redirect()->back()->with('not_permitted', 'GiftCard update failed: ' . $e->getMessage());
        }
    }

    public function recharge(Request $request, $id)
    {
        try {
            \DB::beginTransaction();

            $data = $request->validate([
                'gift_card_id' => 'required|integer|exists:gift_cards,id',
                'amount' => 'required|numeric|min:0.0001',
            ]);
            $data['user_id'] = Auth::id();

            $lims_gift_card_data = GiftCard::whereKey($data['gift_card_id'])->lockForUpdate()->firstOrFail();
            $lims_customer_data = null;
            if (!$lims_gift_card_data->is_active) {
                throw new \RuntimeException(__('db.gift_card_inactive_recharge'));
            }
            if ($lims_gift_card_data->expired_date && $lims_gift_card_data->expired_date < now()->toDateString()) {
                throw new \RuntimeException(__('db.gift_card_expired_recharge'));
            }

            if ($lims_gift_card_data->customer_id) {
                $lims_customer_data = Customer::find($lims_gift_card_data->customer_id);
            } else {
                $lims_customer_data = User::find($lims_gift_card_data->user_id);
            }

            $recharge = GiftCardRecharge::create($data);
            $lims_gift_card_data->amount = bcadd(
                (string) $lims_gift_card_data->amount,
                (string) $data['amount'],
                4
            );
            $lims_gift_card_data->save();

            // Post only the recharge delta. Reposting the card's full balance would
            // overstate both cash and the gift-card liability on every recharge.
            $accountingService = app(\App\Services\AccountingService::class);
            $result = $accountingService->recordGiftCardRecharge($recharge, $lims_gift_card_data);
            if (!$result->isSuccess()) {
                \Log::error('Accounting failed for GiftCard recharge', [
                    'gift_card_id' => $lims_gift_card_data->id,
                    'gift_card_recharge_id' => $recharge->id,
                    'error' => $result->getMessage(),
                ]);
                throw new \RuntimeException($result->getMessage() ?: 'Gift card recharge accounting posting failed.');
            }

            \DB::commit();

        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('GiftCard recharge failed: ' . $e->getMessage());
            return redirect()->back()->with('not_permitted', __('db.gift_card_recharge_failed', ['error' => $e->getMessage()]));
        }

        $message = __('db.gift_card_recharged_successfully');
        if ($lims_customer_data && $lims_customer_data->email) {
            $data['email'] = $lims_customer_data->email;
            $data['name'] = $lims_customer_data->name;
            $data['card_no'] = $lims_gift_card_data->card_no;
            $data['balance'] = $lims_gift_card_data->amount - $lims_gift_card_data->expense;
            try {
                Mail::send('mail.gift_card_recharge', $data, function ($message) use ($data) {
                    $message->to($data['email'])->subject('GiftCard Recharge Info');
                });
            } catch (\Exception $e) {
                $message = __('db.gift_card_recharged_mail_not_configured');
            }
        }

        return redirect('gift_cards')->with('message', $message);
    }

    public function deleteBySelection(Request $request)
    {
        try {
            \DB::beginTransaction();
            $giftCardIds = $request['gift_cardIdArray'] ?? [];

            foreach ($giftCardIds as $id) {
                $giftCard = GiftCard::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->assertGiftCardCanDeactivate($giftCard);
                $giftCard->is_active = false;
                $giftCard->save();
            }

            \DB::commit();
            return __('db.gift_card_deactivated_successfully');
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('GiftCard bulk deactivation failed: ' . $e->getMessage());
            return __('db.gift_card_deactivation_failed', ['error' => $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        try {
            \DB::beginTransaction();

            $giftCard = GiftCard::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertGiftCardCanDeactivate($giftCard);
            $giftCard->is_active = false;
            $giftCard->save();

            \DB::commit();
            return redirect('gift_cards')->with('message', __('db.gift_card_deactivated_successfully'));
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('GiftCard deactivation failed: ' . $e->getMessage());
            return redirect()->back()->with('not_permitted', __('db.gift_card_deactivation_failed', ['error' => $e->getMessage()]));
        }
    }

    private function assertGiftCardCanDeactivate(GiftCard $giftCard): void
    {
        $remaining = bcsub(
            (string) ($giftCard->amount ?? 0),
            (string) ($giftCard->expense ?? 0),
            4
        );

        // Deactivation is an operational action, not an accounting refund/cancellation.
        // Keeping historical issue/recharge/redemption journals intact preserves the
        // audit trail. Cards with unused value must first be settled through a
        // dedicated business workflow instead of silently reversing cash/liability.
        if (bccomp($remaining, '0.0001', 4) > 0) {
            throw new \RuntimeException(__('db.gift_card_remaining_balance_deactivate'));
        }
    }

}
