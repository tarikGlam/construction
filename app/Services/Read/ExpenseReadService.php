<?php

namespace App\Services\Read;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class ExpenseReadService
{
    /**
     * Resolve authorization scope.
     *
     * @return array{0: bool, 1: array<int>, 2: int|null}
     */
    private function resolveScope(AssistantAccessContext|WarehouseScope $context): array
    {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        return [$isRestricted, $allowedWarehouseIds, $ownUserId];
    }

    /**
     * Apply warehouse and own-user authorization boundaries to any expense query.
     */
    public function applyScope(
        $query,
        AssistantAccessContext|WarehouseScope $context,
        string $warehouseColumn = 'expenses.warehouse_id',
        string $userColumn = 'expenses.user_id'
    ) {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted) {
            if (empty($allowedWarehouseIds)) {
                return $query->whereRaw('1 = 0');
            }
            $query->whereIn($warehouseColumn, $allowedWarehouseIds);
        }

        if ($ownUserId !== null) {
            $query->where($userColumn, $ownUserId);
        }

        return $query;
    }

    /**
     * Base query for expenses.
     */
    public function baseQuery(AssistantAccessContext|WarehouseScope $context): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('expenses')
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id');

        return $this->applyScope($query, $context);
    }

    /**
     * Aggregate expense summary for a given period and context.
     *
     * @return array{
     *     total_expenses: float,
     *     expense_count: int
     * }
     */
    public function summary(
        AssistantAccessContext|WarehouseScope $context,
        ?string $startDate = null,
        ?string $endDate = null,
        array $filters = []
    ): array {
        [$isRestricted, $allowedWarehouseIds] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'total_expenses' => 0.0,
                'expense_count' => 0,
            ];
        }

        $query = $this->baseQuery($context);

        if ($startDate !== null) {
            $query->whereDate('expenses.created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->whereDate('expenses.created_at', '<=', $endDate);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('expenses.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['expense_category_id'])) {
            $query->where('expenses.expense_category_id', (int) $filters['expense_category_id']);
        }

        $row = $query->selectRaw('
            COALESCE(SUM(expenses.amount), 0) as total_expenses,
            COUNT(expenses.id) as expense_count
        ')->first();

        return [
            'total_expenses' => (float) ($row->total_expenses ?? 0),
            'expense_count' => (int) ($row->expense_count ?? 0),
        ];
    }

    /**
     * Aggregate today's expense summary.
     */
    public function todaySummary(AssistantAccessContext|WarehouseScope $context, array $filters = []): array
    {
        $today = Carbon::today()->toDateString();
        return $this->summary($context, $today, $today, $filters);
    }

    /**
     * Breakdown by expense category for a given period and context.
     *
     * @return array<array{category: string, total: float}>
     */
    public function categoryBreakdown(
        AssistantAccessContext|WarehouseScope $context,
        ?string $startDate = null,
        ?string $endDate = null,
        int $limit = 10
    ): array {
        [$isRestricted, $allowedWarehouseIds] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $query = $this->baseQuery($context);

        if ($startDate !== null) {
            $query->whereDate('expenses.created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->whereDate('expenses.created_at', '<=', $endDate);
        }

        $rows = $query->select('expense_categories.name as category', DB::raw('SUM(expenses.amount) as total'))
            ->groupBy('expense_categories.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return $rows->map(fn($row) => [
            'category' => (string) $row->category,
            'total' => (float) $row->total,
        ])->all();
    }
}
