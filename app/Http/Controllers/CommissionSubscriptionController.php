<?php

namespace App\Http\Controllers;

use App\Models\landlord\TenantBillingProfile;
use App\Models\landlord\TenantCommissionInvoice;
use Illuminate\Http\Request;

class CommissionSubscriptionController extends Controller
{
    private function authorizeOwner(): string
    {
        abort_unless(config('database.connections.saleprosaas_landlord'), 404);
        abort_unless(tenant() && (int) auth()->user()?->role_id === 1 && auth()->user()->is_active, 403);
        return (string) tenant('id');
    }

    public function index()
    {
        $tenantId = $this->authorizeOwner();
        $profile = TenantBillingProfile::where('tenant_id', $tenantId)->firstOrFail();
        $invoices = TenantCommissionInvoice::where('tenant_id', $tenantId)->orderByDesc('id')->paginate(24);
        $outstanding = TenantCommissionInvoice::where('tenant_id', $tenantId)->where('status', 'due');
        $outstandingAmount = (clone $outstanding)->sum('amount');
        $outstandingCount = $outstanding->count();
        $central = \Illuminate\Support\Facades\DB::connection(config('tenancy.database.central_connection'));
        $payment = $central->table('external_services')->where('name', 'manual_payment')->where('type', 'payment')->where('active', true)->first();
        $manualDetails = [];
        if ($payment) {
            $parts = explode(';', $payment->details, 2);
            $keys = explode(',', $parts[0]);
            $values = explode(',', $parts[1] ?? '');
            foreach ($keys as $i => $key) {
                if (in_array($key, ['Payment Method', 'Account Details', 'Instructions'], true)) $manualDetails[$key] = $values[$i] ?? '';
            }
        }
        return view('backend.commission_billing.index', compact('profile', 'invoices', 'manualDetails', 'outstandingAmount', 'outstandingCount'));
    }

    public function claim(Request $request, int $id)
    {
        $tenantId = $this->authorizeOwner();
        $data = $request->validate(['payment_claim' => 'required|string|max:2000']);
        $invoice = TenantCommissionInvoice::where('tenant_id', $tenantId)->findOrFail($id);
        $invoice->getConnection()->transaction(function () use ($invoice, $data) {
            $invoice = TenantCommissionInvoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->status === 'due', 422, 'Only due invoices accept a payment notification.');
            $invoice->update(['payment_claim' => $data['payment_claim'], 'payment_claimed_at' => now()]);
        });
        return back()->with('message', 'Payment details sent. The administrator will verify receipt.');
    }
}
