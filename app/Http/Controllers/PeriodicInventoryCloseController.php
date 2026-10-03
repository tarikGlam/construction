<?php

namespace App\Http\Controllers;

use App\Models\PeriodicInventoryClose;
use App\Services\PeriodicInventoryCloseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PeriodicInventoryCloseController extends Controller
{
    public function __construct(private PeriodicInventoryCloseService $service) {}

    public function index(Request $request)
    {
        $start = $this->service->effectivePeriodStart()['date'];
        $end = $request->input('period_end', now()->toDateString());
        $preview = $this->service->preview($start, $end);
        $history = PeriodicInventoryClose::with(['journalEntry', 'reversalJournalEntry'])->latest()->limit(20)->get();
        $closers = DB::table('users')->whereIn('id', $history->pluck('created_by')->filter())->pluck('name', 'id');
        return view('backend.accounting.periodic_inventory_close', compact('preview', 'history', 'closers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start', 'confirmation' => 'required|accepted']);
        try {
            $this->service->post($data['period_start'], $data['period_end'], auth()->id());
            return back()->with('message', __('db.inventory_close_posted'));
        } catch (\Throwable $e) {
            return back()->with('not_permitted', __('db.inventory_close_failed'));
        }
    }

    public function reverse(Request $request, PeriodicInventoryClose $close)
    {
        $request->validate(['confirmation' => 'required|accepted']);
        try {
            $this->service->reverse($close);
            return back()->with('message', __('db.inventory_close_reversed'));
        } catch (\Throwable $e) {
            return back()->with('not_permitted', __('db.inventory_close_failed'));
        }
    }

    public function recalculate(Request $request, PeriodicInventoryClose $close)
    {
        $request->validate(['confirmation' => 'required|accepted']);
        try {
            $this->service->recalculate($close, auth()->id());
            return back()->with('message', __('db.inventory_close_recalculated'));
        } catch (\Throwable $e) {
            return back()->with('not_permitted', __('db.inventory_close_failed'));
        }
    }
}
