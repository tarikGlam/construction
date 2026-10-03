<?php

namespace Modules\Construction\Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Construction\Entities\CostCategory;
use Modules\Construction\Entities\Equipment;
use Modules\Construction\Entities\EquipmentAssignment;
use Modules\Construction\Entities\ProjectCost;
use Modules\Construction\Entities\ProjectReceipt;
use Modules\Construction\Entities\ProjectWage;
use Modules\Construction\Entities\Subcontractor;
use Modules\Construction\Entities\SubcontractorContract;
use Modules\Construction\Services\InventoryMovementService;
use Modules\Project\Entities\Project;

class ConstructionDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $group = CustomerGroup::first();
            if (!$group) throw new \RuntimeException('Seed the SalePro foundation first so a Client Group exists.');
            $client = Customer::firstOrCreate(['email'=>'demo.client@construction.local'],['customer_group_id'=>$group->id,'name'=>'Al Noor Developments','company_name'=>'Al Noor Developments','phone_number'=>'0000000000','address'=>'Demo Business District','city'=>'Demo City','is_active'=>true]);
            $unit = Unit::firstOrCreate(['unit_code'=>'PCS'],['unit_name'=>'Piece','operator'=>'*','operation_value'=>1,'is_active'=>true]);
            $category = Category::firstOrCreate(['name'=>'Construction Materials'],['is_active'=>true]);
            $warehouse = Warehouse::firstOrCreate(['name'=>'Central Construction Store'],['address'=>'Main Yard','is_active'=>true,'store_type'=>'Central Store']);
            $materialNames=['Portland Cement'=>9.50,'Reinforcement Steel 12mm'=>620.00,'Reinforcement Steel 16mm'=>645.00,'Sand'=>28.00,'Aggregate'=>34.00,'Concrete Block'=>0.85,'Electrical Cable'=>1.75,'PVC Pipe'=>4.20];
            $materials=[];
            foreach($materialNames as $name=>$cost){$code='MAT-'.str_pad((string)(count($materials)+1),3,'0',STR_PAD_LEFT);$product=Product::firstOrCreate(['code'=>$code],['name'=>$name,'type'=>'standard','barcode_symbology'=>'C128','category_id'=>$category->id,'unit_id'=>$unit->id,'purchase_unit_id'=>$unit->id,'sale_unit_id'=>$unit->id,'cost'=>$cost,'price'=>$cost,'qty'=>1000,'alert_quantity'=>100,'promotion'=>0,'featured'=>0,'is_active'=>true]);$stock=Product_Warehouse::firstOrCreate(['product_id'=>$product->id,'warehouse_id'=>$warehouse->id,'variant_id'=>null,'product_batch_id'=>null],['qty'=>1000,'price'=>$cost]);$materials[]=$stock;}
            $projectData=[['PRJ-001','Al Noor Residential Tower',2500000,2100000],['PRJ-002','Marina Villa Compound',1450000,1200000],['PRJ-003','City Centre Renovation',680000,540000]];
            $projects=[]; foreach($projectData as [$code,$title,$contract,$budget]){$projects[]=Project::firstOrCreate(['project_code'=>$code],['title'=>$title,'customer_id'=>$client->id,'start_date'=>now()->subMonths(3)->format('Y-m-d'),'end_date'=>now()->addMonths(9)->format('Y-m-d'),'expected_end_date'=>now()->addMonths(9),'project_priority'=>'high','description'=>'Construction ERP demonstration project','project_status'=>'in_progress','project_progress'=>'35','location'=>'Demo City','contract_value'=>$contract,'budget'=>$budget]);}
            $categories=CostCategory::pluck('id','name');
            foreach($projects as $i=>$project) ProjectCost::firstOrCreate(['project_id'=>$project->id,'reference'=>'DEMO-COST-'.($i+1)],['cost_category_id'=>$categories['Other Project Cost'],'date'=>now()->subDays(12-$i),'amount'=>18000+($i*4500),'description'=>'Site mobilization and preliminary works','created_by'=>1]);
            $subcontractors=[]; foreach(['Gulf Electrical Works','Prime Plumbing Services','Modern Aluminium Works'] as $name)$subcontractors[]=Subcontractor::firstOrCreate(['name'=>$name],['company_name'=>$name,'status'=>'active']);
            foreach($projects as $i=>$project) SubcontractorContract::firstOrCreate(['contract_no'=>'SC-DEMO-'.($i+1)],['project_id'=>$project->id,'subcontractor_id'=>$subcontractors[$i]->id,'scope'=>'Specialist construction package','contract_value'=>120000+($i*30000),'paid_amount'=>30000+($i*5000),'status'=>'active']);
            foreach([['EX-01','Excavator'],['CM-02','Concrete Mixer'],['GN-03','Generator']] as [$code,$name]) Equipment::firstOrCreate(['code'=>$code],['name'=>$name,'category'=>'Plant & Equipment','status'=>'available']);
            foreach(Equipment::whereIn('code',['EX-01','CM-02','GN-03'])->get()->values() as $i=>$equipment) EquipmentAssignment::firstOrCreate(['equipment_id'=>$equipment->id,'project_id'=>$projects[$i]->id,'assigned_from'=>now()->subDays(20)->format('Y-m-d')],['rate_type'=>'day','rate'=>250,'usage_quantity'=>20,'cost'=>5000,'status'=>'assigned']);
            foreach($projects as $i=>$project) ProjectReceipt::firstOrCreate(['reference_no'=>'PR-DEMO-'.($i+1)],['project_id'=>$project->id,'customer_id'=>$client->id,'receipt_date'=>now()->subDays(30-$i),'amount'=>250000+($i*50000),'payment_method'=>'Bank Transfer','external_reference'=>'DEMO-TRANSFER-'.($i+1),'posting_status'=>'operational','created_by'=>1]);
            $employee=DB::table('employees')->where('is_active',true)->first(); if($employee) foreach($projects as $i=>$project) ProjectWage::firstOrCreate(['project_id'=>$project->id,'employee_id'=>$employee->id,'period'=>now()->startOfMonth()->format('Y-m-d')],['days_worked'=>22,'basic_amount'=>2500,'overtime_amount'=>250,'bonus_amount'=>0,'deduction_amount'=>0,'total_amount'=>2750,'cost_category_id'=>$categories['Labour'],'created_by'=>1]);
            if(!DB::table('material_issues')->where('reference_no','MI-DEMO-001')->exists()) app(InventoryMovementService::class)->issue(['reference_no'=>'MI-DEMO-001','project_id'=>$projects[0]->id,'warehouse_id'=>$warehouse->id,'issue_date'=>now()->subDays(7)->format('Y-m-d'),'status'=>'issued','notes'=>'Demo foundation material issue','created_by'=>1],[['stock_row_id'=>$materials[0]->id,'quantity'=>120,'cost_category_id'=>$categories['Materials']],['stock_row_id'=>$materials[1]->id,'quantity'=>8,'cost_category_id'=>$categories['Materials']]]);
        });
    }
}
