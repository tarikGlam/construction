<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payroll;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeAdvanceService
{
    public function totalsForEmployee(int $employeeId, ?int $excludePayrollId = null): array
    {
        $issued = (float) Expense::where('employee_id', $employeeId)
            ->whereIn('type', ['advance', 'construction_employee_advance'])
            ->where('accounting_status', 'posted')
            ->sum('amount');

        $payrolls = Payroll::where('employee_id', $employeeId)
            ->where('accounting_status', 'posted')
            ->when($excludePayrollId, fn ($query) => $query->whereKeyNot($excludePayrollId))
            ->get(['amount_array']);

        $payrollRecovered = (float) $payrolls->sum(function ($payroll) {
            $amounts = is_array($payroll->amount_array)
                ? $payroll->amount_array
                : (json_decode((string) $payroll->amount_array, true) ?: []);

            return (float) ($amounts['advance_recovery'] ?? 0);
        });

        $constructionSettled = 0.0;
        if (Schema::hasTable('construction_employee_advance_settlements')
            && Schema::hasTable('construction_employee_advances')) {
            $constructionSettled = (float) DB::table('construction_employee_advance_settlements as settlements')
                ->join('construction_employee_advances as advances', 'advances.id', '=', 'settlements.employee_advance_id')
                ->where('advances.employee_id', $employeeId)
                ->sum('settlements.amount');
        }

        $recovered = $payrollRecovered + $constructionSettled;

        return [
            'issued' => $issued,
            'recovered' => $recovered,
            'outstanding' => max(0, $issued - $recovered),
        ];
    }

    public function outstandingForEmployee(int $employeeId, ?int $excludePayrollId = null): float
    {
        return $this->totalsForEmployee($employeeId, $excludePayrollId)['outstanding'];
    }

    public function totalOutstanding(): float
    {
        return (float) Expense::query()
            ->whereIn('type', ['advance', 'construction_employee_advance'])
            ->where('accounting_status', 'posted')
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->sum(fn ($employeeId) => $this->outstandingForEmployee((int) $employeeId));
    }

    public function constructionOutstanding(?int $projectId = null): float
    {
        if (!Schema::hasTable('construction_employee_advances')) {
            return 0.0;
        }

        $construction = DB::table('construction_employee_advances as advances')
            ->leftJoin('construction_employee_advance_settlements as settlements', 'settlements.employee_advance_id', '=', 'advances.id')
            ->selectRaw('advances.expense_id, advances.project_id, COALESCE(SUM(settlements.amount), 0) as settled')
            ->groupBy('advances.expense_id', 'advances.project_id')
            ->get()
            ->keyBy('expense_id');

        $issued = Expense::query()
            ->whereIn('type', ['advance', 'construction_employee_advance'])
            ->where('accounting_status', 'posted')
            ->whereNotNull('employee_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'amount'])
            ->groupBy('employee_id');

        $total = 0.0;
        foreach ($issued as $employeeId => $expenses) {
            $payrollRecovered = $this->payrollRecoveredForEmployee((int) $employeeId);
            foreach ($expenses as $expense) {
                $constructionAdvance = $construction->get($expense->id);
                $remaining = max(0, (float) $expense->amount - (float) ($constructionAdvance->settled ?? 0));
                $payrollApplied = min($remaining, $payrollRecovered);
                $remaining -= $payrollApplied;
                $payrollRecovered -= $payrollApplied;

                if ($constructionAdvance
                    && ($projectId === null || (int) $constructionAdvance->project_id === $projectId)) {
                    $total += $remaining;
                }
            }
        }

        return $total;
    }

    private function payrollRecoveredForEmployee(int $employeeId, ?int $excludePayrollId = null): float
    {
        return (float) Payroll::where('employee_id', $employeeId)
            ->where('accounting_status', 'posted')
            ->when($excludePayrollId, fn ($query) => $query->whereKeyNot($excludePayrollId))
            ->get(['amount_array'])
            ->sum(function ($payroll) {
                $amounts = is_array($payroll->amount_array)
                    ? $payroll->amount_array
                    : (json_decode((string) $payroll->amount_array, true) ?: []);

                return (float) ($amounts['advance_recovery'] ?? 0);
            });
    }
}
