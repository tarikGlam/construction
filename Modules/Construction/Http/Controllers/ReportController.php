<?php
namespace Modules\Construction\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Construction\Services\ProjectFinancialService;
use Modules\Project\Entities\Project;
class ReportController extends Controller {
 public function profitability(ProjectFinancialService $service){$projects=Project::with('customer')->orderBy('title')->get();$rows=$projects->map(fn($p)=>['project'=>$p,'summary'=>$service->summary($p)]);return view('construction::reports.profitability',compact('rows'));}
 public function statement(Request $request,ProjectFinancialService $service){$projects=Project::with(['customer','projectManager'])->orderBy('title')->get();$project=$request->filled('project_id')?$projects->firstWhere('id',(int)$request->project_id):$projects->first();$summary=$project?$service->summary($project):null;return view('construction::reports.statement',compact('projects','project','summary'));}
}
