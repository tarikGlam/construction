<?php

namespace App\Services\Read;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class PeopleReadService
{
    public function isAvailable(): bool
    {
        return Schema::hasTable('employees')
            && Schema::hasTable('attendances')
            && Schema::hasTable('payrolls');
    }

    private function resolveScope(AssistantAccessContext|WarehouseScope $context): array
    {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        return [$isRestricted, $allowedWarehouseIds, $ownUserId];
    }

    /**
     * Headcount and department staffing summary.
     */
    public function headcountSummary(AssistantAccessContext|WarehouseScope $context): array
    {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'reason' => 'HR and Payroll module is not active or enabled on this system.',
                'total_employees' => 0,
                'active_employees' => 0,
                'sales_agents' => 0,
                'departments' => [],
            ];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'available' => true,
                'total_employees' => 0,
                'active_employees' => 0,
                'sales_agents' => 0,
                'departments' => [],
            ];
        }

        $query = DB::table('employees');

        if ($isRestricted) {
            $query->where(function ($q) use ($allowedWarehouseIds) {
                $q->whereIn('employees.warehouse_id', $allowedWarehouseIds)
                  ->orWhereNull('employees.warehouse_id');
            });
        }

        if ($ownUserId !== null) {
            $query->where('employees.user_id', $ownUserId);
        }

        $totalEmployees = (clone $query)->count();
        $activeEmployees = (clone $query)->where('employees.is_active', true)->count();
        $salesAgents = (clone $query)->where('employees.is_active', true)->where('employees.is_sale_agent', 1)->count();

        // Department breakdown
        $deptQuery = (clone $query)->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.is_active', true)
            ->groupBy('departments.name')
            ->selectRaw('COALESCE(departments.name, "Unassigned") as department_name, COUNT(employees.id) as count')
            ->get();

        $departments = [];
        foreach ($deptQuery as $d) {
            $departments[] = [
                'department' => (string) $d->department_name,
                'count' => (int) $d->count,
            ];
        }

        return [
            'available' => true,
            'total_employees' => $totalEmployees,
            'active_employees' => $activeEmployees,
            'sales_agents' => $salesAgents,
            'departments' => $departments,
        ];
    }

    /**
     * Daily attendance summary.
     */
    public function attendanceSummary(
        AssistantAccessContext|WarehouseScope $context,
        ?string $date = null
    ): array {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'reason' => 'HR and Payroll module is not active or enabled on this system.',
                'date' => $date ?? Carbon::today()->toDateString(),
                'total_present' => 0,
                'total_late' => 0,
            ];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'available' => true,
                'date' => $date ?? Carbon::today()->toDateString(),
                'total_present' => 0,
                'total_late' => 0,
            ];
        }

        $targetDate = $date ?? Carbon::today()->toDateString();

        $query = DB::table('attendances')
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->whereDate('attendances.date', $targetDate);

        if ($isRestricted) {
            $query->where(function ($q) use ($allowedWarehouseIds) {
                $q->whereIn('employees.warehouse_id', $allowedWarehouseIds)
                  ->orWhereNull('employees.warehouse_id');
            });
        }

        if ($ownUserId !== null) {
            $query->where('employees.user_id', $ownUserId);
        }

        $row = $query->selectRaw('
            COUNT(attendances.id) as total_present,
            COUNT(CASE WHEN attendances.status = 2 THEN 1 END) as total_late
        ')->first();

        return [
            'available' => true,
            'date' => $targetDate,
            'total_present' => (int) ($row->total_present ?? 0),
            'total_late' => (int) ($row->total_late ?? 0),
        ];
    }

    /**
     * Payroll disbursement summary.
     */
    public function payrollSummary(
        AssistantAccessContext|WarehouseScope $context,
        ?string $month = null,
        ?int $year = null
    ): array {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'reason' => 'HR and Payroll module is not active or enabled on this system.',
                'total_payrolls' => 0,
                'total_amount' => 0.0,
            ];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'available' => true,
                'total_payrolls' => 0,
                'total_amount' => 0.0,
            ];
        }

        $query = DB::table('payrolls')
            ->join('employees', 'payrolls.employee_id', '=', 'employees.id');

        if ($isRestricted) {
            $query->where(function ($q) use ($allowedWarehouseIds) {
                $q->whereIn('employees.warehouse_id', $allowedWarehouseIds)
                  ->orWhereNull('employees.warehouse_id');
            });
        }

        if ($ownUserId !== null) {
            $query->where('employees.user_id', $ownUserId);
        }

        if ($month !== null) {
            $query->where('payrolls.month', 'like', '%' . $month . '%');
        }

        if ($year !== null) {
            $query->whereYear('payrolls.created_at', $year);
        }

        $row = $query->selectRaw('
            COUNT(payrolls.id) as total_payrolls,
            COALESCE(SUM(payrolls.amount), 0) as total_amount
        ')->first();

        return [
            'available' => true,
            'total_payrolls' => (int) ($row->total_payrolls ?? 0),
            'total_amount' => round((float) ($row->total_amount ?? 0.0), 2),
        ];
    }

    /**
     * Recent payroll transactions list.
     */
    public function recentPayrolls(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 10
    ): array {
        if (!$this->isAvailable()) {
            return [];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = DB::table('payrolls')
            ->join('employees', 'payrolls.employee_id', '=', 'employees.id')
            ->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->select([
                'payrolls.id',
                'payrolls.reference_no',
                'payrolls.created_at',
                'payrolls.month',
                'payrolls.amount',
                'payrolls.paying_method',
                'employees.name as employee_name',
                'departments.name as department_name',
            ])
            ->orderBy('payrolls.created_at', 'desc')
            ->limit($boundedLimit);

        if ($isRestricted) {
            $query->where(function ($q) use ($allowedWarehouseIds) {
                $q->whereIn('employees.warehouse_id', $allowedWarehouseIds)
                  ->orWhereNull('employees.warehouse_id');
            });
        }

        if ($ownUserId !== null) {
            $query->where('employees.user_id', $ownUserId);
        }

        $results = [];
        foreach ($query->get() as $row) {
            $results[] = [
                'id' => (int) $row->id,
                'reference_no' => (string) $row->reference_no,
                'date' => substr((string) $row->created_at, 0, 10),
                'month' => (string) ($row->month ?? '-'),
                'employee_name' => (string) $row->employee_name,
                'department' => (string) ($row->department_name ?? 'N/A'),
                'amount' => round((float) $row->amount, 2),
                'paying_method' => (string) $row->paying_method,
            ];
        }

        return $results;
    }
}
