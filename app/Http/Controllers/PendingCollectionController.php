<?php

namespace App\Http\Controllers;

use App\Models\PendingCollection;
use App\Models\User;
use App\Services\CollectionApprovalService;
use App\Services\WarehouseAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PendingCollectionController extends Controller
{
    public function __construct(
        protected CollectionApprovalService $approvalService,
        protected WarehouseAccessService $warehouseAccess
    ) {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = Auth::user();
        if (!$user || !$user->hasPermissionTo('pending_collections-index')) {
            abort(403, 'Unauthorized action.');
        }

        $query = PendingCollection::with(['customer', 'sale', 'collectedBy', 'handedOverTo', 'account', 'warehouse']);
        $this->warehouseAccess->scope($query, 'warehouse_id');

        $pendingCollections = (clone $query)->where('status', PendingCollection::STATUS_PENDING)->orderBy('created_at', 'desc')->get();
        $approvedCollections = (clone $query)->with('payment')->where('status', PendingCollection::STATUS_APPROVED)->orderBy('created_at', 'desc')->get();
        $rejectedCollections = (clone $query)->whereIn('status', [PendingCollection::STATUS_REJECTED, PendingCollection::STATUS_REVERSED])->orderBy('created_at', 'desc')->get();

        $usersQuery = User::where('is_active', true)->where('is_deleted', false);
        $this->warehouseAccess->scope($usersQuery, 'warehouse_id');
        $users = $usersQuery->get();

        return view('backend.pending_collection.index', compact('pendingCollections', 'approvedCollections', 'rejectedCollections', 'users'));
    }

    public function approve(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || !$user->hasPermissionTo('pending_collections-approve')) {
            return redirect()->back()->with('not_permitted', 'Unauthorized action.');
        }

        try {
            $result = $this->approvalService->approve((int) $id, $user->id, $user->name);
            return redirect()->back()->with('message', $result['message']);
        } catch (\Throwable $e) {
            return redirect()->back()->with('not_permitted', $e->getMessage());
        }
    }

    public function reject(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || !$user->hasPermissionTo('pending_collections-reject')) {
            return redirect()->back()->with('not_permitted', 'Unauthorized action.');
        }

        $request->validate(['rejection_reason' => 'required|string']);

        try {
            $result = $this->approvalService->reject((int) $id, $user->id, $user->name, $request->rejection_reason);
            return redirect()->back()->with('message', $result['message']);
        } catch (\Throwable $e) {
            return redirect()->back()->with('not_permitted', $e->getMessage());
        }
    }

    public function reverse(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || !$user->hasPermissionTo('pending_collections-reverse')) {
            return redirect()->back()->with('not_permitted', 'Unauthorized action.');
        }

        $request->validate(['reversal_reason' => 'required|string']);

        try {
            $result = $this->approvalService->reverse((int) $id, $user->id, $user->name, $request->reversal_reason);
            return redirect()->back()->with('message', $result['message']);
        } catch (\Throwable $e) {
            return redirect()->back()->with('not_permitted', $e->getMessage());
        }
    }

    public function handover(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || !$user->hasPermissionTo('pending_collections-handover')) {
            return redirect()->back()->with('not_permitted', 'Unauthorized action.');
        }

        $request->validate([
            'handed_over_to' => 'required|exists:users,id',
            'handover_notes' => 'nullable|string'
        ]);

        $recipient = User::findOrFail($request->handed_over_to);

        try {
            $result = $this->approvalService->handover((int) $id, $recipient->id, $recipient->name, $request->handover_notes);
            return redirect()->back()->with('message', $result['message']);
        } catch (\Throwable $e) {
            return redirect()->back()->with('not_permitted', $e->getMessage());
        }
    }
}
