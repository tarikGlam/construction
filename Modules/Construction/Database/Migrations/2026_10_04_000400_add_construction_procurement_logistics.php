<?php

use App\Services\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('transfers')) {
            Schema::table('transfers', function (Blueprint $table) {
                if (!Schema::hasColumn('transfers', 'project_id')) $table->unsignedBigInteger('project_id')->nullable()->index();
                if (!Schema::hasColumn('transfers', 'site_id')) $table->unsignedBigInteger('site_id')->nullable()->index();
                if (!Schema::hasColumn('transfers', 'approved_by')) $table->unsignedInteger('approved_by')->nullable()->index();
                if (!Schema::hasColumn('transfers', 'dispatch_date')) $table->date('dispatch_date')->nullable()->index();
                if (!Schema::hasColumn('transfers', 'receipt_date')) $table->date('receipt_date')->nullable()->index();
            });
        }

        if (!Schema::hasTable('supplier_product_references')) {
            Schema::create('supplier_product_references', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('supplier_id')->index();
                $table->unsignedInteger('product_id')->index();
                $table->string('supplier_reference')->nullable();
                $table->decimal('last_purchase_price', 20, 4)->nullable();
                $table->boolean('is_preferred')->default(false)->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['supplier_id', 'product_id']);
            });
        }

        if (!Schema::hasTable('construction_transport_records')) {
            Schema::create('construction_transport_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedInteger('transfer_id')->nullable()->index();
                $table->unsignedInteger('purchase_id')->nullable()->index();
                $table->string('source');
                $table->string('destination');
                $table->string('vehicle')->nullable();
                $table->string('driver')->nullable();
                $table->date('transport_date')->index();
                $table->decimal('transport_cost', 20, 4)->default(0);
                $table->string('delivery_status', 30)->default('pending')->index();
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('permissions')) {
            foreach (['construction.procurement.view','construction.transport.manage','construction.supplier-items.manage'] as $name) {
                DB::table('permissions')->updateOrInsert(['name'=>$name,'guard_name'=>'web'], ['created_at'=>now(),'updated_at'=>now()]);
            }
            if (Schema::hasTable('role_has_permissions')) {
                $permissionIds = DB::table('permissions')->whereIn('name', [
                    'construction.procurement.view','construction.transport.manage','construction.supplier-items.manage'
                ])->pluck('id');
                foreach (DB::table('roles')->whereIn('id',[1,2])->pluck('id') as $roleId) {
                    foreach ($permissionIds as $permissionId) {
                        DB::table('role_has_permissions')->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$permissionId], []);
                    }
                }
                $rolePermissions = [
                    'Management / Director' => ['construction.procurement.view'],
                    'Project Manager' => ['construction.procurement.view','construction.transport.manage'],
                    'Storekeeper' => ['construction.procurement.view','construction.transport.manage'],
                    'Procurement Officer' => ['construction.procurement.view','construction.transport.manage','construction.supplier-items.manage'],
                    'Accountant' => ['construction.procurement.view'],
                ];
                foreach ($rolePermissions as $roleName => $names) {
                    $roleId = DB::table('roles')->where('name',$roleName)->value('id');
                    if (!$roleId) continue;
                    foreach (DB::table('permissions')->whereIn('name',$names)->pluck('id') as $permissionId) {
                        DB::table('role_has_permissions')->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$permissionId], []);
                    }
                }
                app('cache')->forget('spatie.permission.cache');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_transport_records');
        Schema::dropIfExists('supplier_product_references');
        if (Schema::hasTable('transfers')) {
            Schema::table('transfers', function (Blueprint $table) {
                $columns=[];
                foreach (['receipt_date','dispatch_date','approved_by','site_id','project_id'] as $column) {
                    if (Schema::hasColumn('transfers',$column)) $columns[]=$column;
                }
                if ($columns) $table->dropColumn($columns);
            });
        }
    }
};
