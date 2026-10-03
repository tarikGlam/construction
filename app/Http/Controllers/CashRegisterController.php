<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Domain\CashRegisterDomainService;
use App\Services\RegisterReconciliationService;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CashRegisterController extends Controller
{
    public function index(Request $request)
	{
        if (Auth::user()->role_id > 2) {
			return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
	}

        $query = CashRegister::with(['user', 'warehouse', 'closedBy', 'reconciliation']);
        if ($request->filled('starting_date')) {
            $query->whereDate('created_at', '>=', $request->starting_date);
        }
        if ($request->filled('ending_date')) {
            $query->whereDate('created_at', '<=', $request->ending_date);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->variance_status === 'with_variance') {
            $query->whereHas('reconciliation', fn ($q) => $q->where('variance_total', '!=', 0));
        } elseif ($request->variance_status === 'balanced') {
            $query->whereHas('reconciliation', fn ($q) => $q->where('variance_total', 0));
        }

        $lims_cash_register_all = $query->orderByDesc('created_at')->get();
        $lims_warehouse_list = Warehouse::where('is_active', true)->orderBy('name')->get();
        $lims_user_list = User::where('is_active', true)->orderBy('name')->get();

        return view('backend.cash_register.index', compact(
            'lims_cash_register_all', 'lims_warehouse_list', 'lims_user_list'
        ));
    }

	public function store(Request $request)
	{
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'cash_in_hand' => ['required', 'numeric', 'min:0'],
        ]);
        app(CashRegisterDomainService::class)->open($data, Auth::id());

		return redirect()->back()->with('message', __('db.Cash register created successfully'));
	}

    public function getDetails(int $id, RegisterReconciliationService $service)
	{
        $register = CashRegister::with(['reconciliation.tenders', 'user', 'warehouse', 'closedBy'])->findOrFail($id);
        $this->ensureAccessible($register);

        $legacyClosed = !$register->status && !$register->reconciliation;
        $tenders = $register->reconciliation
            ? $register->reconciliation->tenders->map(fn ($tender) => [
                'method_key' => $tender->method_key,
                'method_label' => $tender->method_label,
                'expected_amount' => $tender->expected_amount,
                'counted_amount' => $tender->counted_amount,
                'variance_amount' => $tender->variance_amount,
                'variance_reason' => $tender->variance_reason,
            ])->values()
            : ($legacyClosed ? collect() : $service->expectedTenders($register)->map(fn ($tender) => $tender + [
                'counted_amount' => null,
                'variance_amount' => null,
                'variance_reason' => null,
            ]));

        return response()->json([
            'id' => $register->id,
            'status' => $register->status,
            'warehouse' => $register->warehouse?->name,
            'opened_by' => $register->user?->name,
            'closed_by' => $register->closedBy?->name,
            'opened_at' => optional($register->created_at)->toDateTimeString(),
            'closed_at' => optional($register->reconciliation?->closed_at ?? $register->closed_at)->toDateTimeString(),
            'closing_note' => $register->reconciliation?->closing_note ?? $register->closing_note,
            'legacy_closed' => $legacyClosed,
            'tenders' => $tenders,
            'cash_in_hand' => $register->cash_in_hand,
            'total_cash' => optional($tenders->firstWhere('method_key', 'cash'))['expected_amount'] ?? $register->closing_balance ?? 0,
            'expected_total' => $register->reconciliation?->expected_total ?? ($legacyClosed ? $register->closing_balance : null),
            'counted_total' => $register->reconciliation?->counted_total ?? ($legacyClosed ? $register->actual_cash : null),
            'variance_total' => $register->reconciliation?->variance_total ?? ($legacyClosed && $register->closing_balance !== null && $register->actual_cash !== null
                ? round((float) $register->actual_cash - (float) $register->closing_balance, 4)
                : null),
		]);
    }

    public function close(Request $request, RegisterReconciliationService $service)
	{
        $data = $request->validate([
            'cash_register_id' => ['required', 'integer'],
            'closing_note' => ['nullable', 'string', 'max:2000'],
            'tenders' => ['required', 'array', 'min:1'],
            'tenders.*.method_key' => ['required', 'string', 'max:191'],
            'tenders.*.counted_amount' => ['required', 'numeric'],
            'tenders.*.variance_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $register = CashRegister::findOrFail($data['cash_register_id']);
        $this->ensureAccessible($register, true);

        try {
            $service->close($register, $data['tenders'], Auth::id(), $data['closing_note'] ?? null);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return redirect()->back()->withErrors(['cash_register' => __($exception->getMessage())]);
	}

        return redirect()->back()->with('message', __('db.Cash register closed and reconciled successfully'));
    }

    public function checkAvailability(int $warehouse_id)
    {
        $openRegister = CashRegister::select('id')->where([
            ['user_id', Auth::id()],
            ['warehouse_id', $warehouse_id],
            ['status', true],
        ])->first();

        return response()->json($openRegister?->id ?: false);
    }

    private function ensureAccessible(CashRegister $register, bool $closing = false): void
    {
        $user = Auth::user();
        if ($user->role_id <= 2) {
            return;
        }

        if ((int) $register->user_id !== (int) $user->id) {
            abort(403, __('db.You do not have permission to perform this action.'));
        }

        if ($closing && !$register->status) {
            abort(409, __('db.Cash register is already closed.'));
        }
    }
}
