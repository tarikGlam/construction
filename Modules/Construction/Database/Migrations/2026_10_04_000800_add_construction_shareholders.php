<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('construction_shareholders')) {
            Schema::create('construction_shareholders', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('phone', 80)->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->decimal('ownership_percentage', 8, 4)->nullable();
                $table->string('ownership_reference')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('construction_shareholder_transactions')) {
            Schema::create('construction_shareholder_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shareholder_id')->index();
                $table->date('transaction_date')->index();
                $table->string('transaction_type', 40)->index();
                $table->decimal('amount', 20, 4);
                $table->unsignedInteger('account_id')->index();
                $table->unsignedBigInteger('journal_entry_id')->nullable()->unique();
                $table->string('reference')->nullable()->index();
                $table->text('notes')->nullable();
                $table->string('posting_status', 30)->default('posted')->index();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }

        // Provision dedicated semantic ledgers without changing SalePro's accounting engine.
        if (Schema::hasTable('accounting_accounts')) {
            $equityParent = DB::table('accounting_accounts')->where('code', '3000')->value('id');
            $liabilityParent = DB::table('accounting_accounts')->where('code', '2000')->value('id');

            DB::table('accounting_accounts')->updateOrInsert(
                ['code' => '3200'],
                [
                    'name' => 'Shareholder Capital',
                    'account_type' => 'equity',
                    'parent_id' => $equityParent,
                    'is_control_account' => true,
                    'is_system' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            DB::table('accounting_accounts')->updateOrInsert(
                ['code' => '2300'],
                [
                    'name' => 'Shareholder Loans Payable',
                    'account_type' => 'liability',
                    'parent_id' => $liabilityParent,
                    'is_control_account' => true,
                    'is_system' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->updateOrInsert(
                ['name' => 'construction.shareholders.manage', 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()]
            );
            $permissionId = DB::table('permissions')->where('name', 'construction.shareholders.manage')->value('id');
            if ($permissionId && Schema::hasTable('role_has_permissions') && Schema::hasTable('roles')) {
                foreach (DB::table('roles')->whereIn('name', ['Management / Director', 'Accountant'])->pluck('id') as $roleId) {
                    DB::table('role_has_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], []);
                }
                foreach (DB::table('roles')->whereIn('id', [1, 2])->pluck('id') as $roleId) {
                    DB::table('role_has_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], []);
                }
                app('cache')->forget('spatie.permission.cache');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_shareholder_transactions');
        Schema::dropIfExists('construction_shareholders');
        // Deliberately retain accounting ledgers if they have ever been used.
    }
};
