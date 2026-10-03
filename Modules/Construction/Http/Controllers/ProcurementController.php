<?php

namespace Modules\Construction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Construction\Entities\ConstructionSite;
use Modules\Construction\Entities\SupplierProductReference;
use Modules\Construction\Entities\TransportRecord;
use Modules\Project\Entities\Project;

class ProcurementController extends Controller
{
    public function index(Request $request)
    {
        $projectId = $request->integer('project_id') ?: null;
        $projects = Project::orderBy('title')->get();
        $sites = ConstructionSite::with('project')->orderBy('name')->get();

        $purchases = Purchase::withTrashed()->with(['supplier','warehouse','project'])
            ->when($projectId, fn($q)=>$q->where('project_id',$projectId))
            ->whereNotNull('project_id')->latest()->take(50)->get();

        $transfers = Transfer::with(['fromWarehouse','toWarehouse','project'])
            ->when($projectId, fn($q)=>$q->where('project_id',$projectId))
            ->whereNotNull('project_id')->latest()->take(50)->get();

        $pendingPurchaseLines = DB::table('product_purchases')
            ->join('purchases','purchases.id','=','product_purchases.purchase_id')
            ->join('products','products.id','=','product_purchases.product_id')
            ->leftJoin('suppliers','suppliers.id','=','purchases.supplier_id')
            ->leftJoin('projects','projects.id','=','purchases.project_id')
            ->whereNotNull('purchases.project_id')
            ->whereColumn('product_purchases.recieved','<','product_purchases.qty')
            ->when($projectId, fn($q)=>$q->where('purchases.project_id',$projectId))
            ->select('purchases.id as purchase_id','purchases.reference_no','purchases.created_at','projects.title as project_name','suppliers.name as supplier_name','products.name as product_name','product_purchases.qty','product_purchases.recieved')
            ->orderByDesc('purchases.id')->take(100)->get();

        $pendingTransfers = Transfer::with(['fromWarehouse','toWarehouse'])
            ->whereNotNull('project_id')->where('status','!=',1)
            ->when($projectId, fn($q)=>$q->where('project_id',$projectId))
            ->latest()->take(50)->get();

        $transport = TransportRecord::with(['project','site'])->latest()->take(50)->get();
        $supplierItems = SupplierProductReference::with(['supplier','product'])->latest()->take(100)->get();
        $suppliers = Supplier::where('is_active',true)->orderBy('name')->get();
        $products = Product::where('is_active',true)->orderBy('name')->get(['id','name','code']);

        return view('construction::procurement', compact('projects','sites','purchases','transfers','pendingPurchaseLines','pendingTransfers','transport','supplierItems','suppliers','products','projectId'));
    }

    public function storeTransport(Request $request)
    {
        $data=$request->validate([
            'project_id'=>'nullable|exists:projects,id','site_id'=>'nullable|exists:construction_sites,id',
            'transfer_id'=>'nullable|exists:transfers,id','purchase_id'=>'nullable|exists:purchases,id',
            'source'=>'required|string|max:191','destination'=>'required|string|max:191','vehicle'=>'nullable|string|max:191','driver'=>'nullable|string|max:191',
            'transport_date'=>'required|date','transport_cost'=>'nullable|numeric|min:0','delivery_status'=>'required|in:pending,in_transit,delivered,cancelled',
            'reference'=>'nullable|string|max:191','notes'=>'nullable|string',
        ]);
        $data['created_by']=Auth::id();
        TransportRecord::create($data);
        return back()->with('message','Transport record added.');
    }

    public function storeSupplierItem(Request $request)
    {
        $data=$request->validate([
            'supplier_id'=>'required|exists:suppliers,id','product_id'=>'required|exists:products,id',
            'supplier_reference'=>'nullable|string|max:191','last_purchase_price'=>'nullable|numeric|min:0','is_preferred'=>'nullable|boolean','notes'=>'nullable|string',
        ]);
        $data['is_preferred']=$request->boolean('is_preferred');
        if ($data['is_preferred']) SupplierProductReference::where('product_id',$data['product_id'])->update(['is_preferred'=>false]);
        SupplierProductReference::updateOrCreate(['supplier_id'=>$data['supplier_id'],'product_id'=>$data['product_id']],$data);
        return back()->with('message','Supplier-item relationship saved.');
    }
}
