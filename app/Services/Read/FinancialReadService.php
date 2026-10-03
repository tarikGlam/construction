<?php

namespace App\Services\Read;

use App\Services\AccountingModeService;
use App\Services\FinancialReportingService;
use Modules\AIAssistant\DTO\SkillAvailabilityResult;
use Modules\AIAssistant\Security\AssistantAccessContext;

class FinancialReadService
{
    public function __construct(
        private AccountingModeService $accountingModeService,
        private FinancialReportingService $reportingService
    ) {}

    /**
     * Check if double entry accounting is authoritative and available.
     */
    public function checkAuthoritativeAvailability(): SkillAvailabilityResult
    {
        if (!$this->accountingModeService->isDoubleEntryAuthoritative()) {
            return SkillAvailabilityResult::accountingNotAuthoritative();
        }

        return SkillAvailabilityResult::available();
    }

    /**
     * GL Profit and Loss statement, strictly respecting accounting mode and scope.
     *
     * @return array{
     *     is_authoritative: bool,
     *     unavailability_message: ?string,
     *     gross_revenue: float,
     *     net_revenue: float,
     *     total_cogs: float,
     *     gross_profit: float,
     *     total_expenses: float,
     *     net_profit: float,
     *     profit_type: string
     * }
     */
    public function profitAndLoss(
        AssistantAccessContext|\Modules\AIAssistant\DTO\WarehouseScope $context,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        if (!$this->accountingModeService->isDoubleEntryAuthoritative()) {
            return [
                'is_authoritative' => false,
                'unavailability_message' => 'Financial statement unavailable because double-entry accounting is not currently authoritative for this business.',
                'gross_revenue' => 0.0,
                'net_revenue' => 0.0,
                'total_cogs' => 0.0,
                'gross_profit' => 0.0,
                'total_expenses' => 0.0,
                'net_profit' => 0.0,
                'profit_type' => 'gl_financial_profit',
            ];
        }

        $isRestricted = $context instanceof \Modules\AIAssistant\DTO\WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof \Modules\AIAssistant\DTO\WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'is_authoritative' => true,
                'unavailability_message' => 'An active warehouse assignment is required to view financial statements.',
                'gross_revenue' => 0.0,
                'net_revenue' => 0.0,
                'total_cogs' => 0.0,
                'gross_profit' => 0.0,
                'total_expenses' => 0.0,
                'net_profit' => 0.0,
                'profit_type' => 'gl_financial_profit',
            ];
        }

        if ($ownUserId !== null) {
            return [
                'is_authoritative' => true,
                'unavailability_message' => 'User-restricted scopes are not permitted to view financial statements.',
                'gross_revenue' => 0.0,
                'net_revenue' => 0.0,
                'total_cogs' => 0.0,
                'gross_profit' => 0.0,
                'total_expenses' => 0.0,
                'net_profit' => 0.0,
                'profit_type' => 'gl_financial_profit',
            ];
        }

        $warehouseId = ($isRestricted && count($allowedWarehouseIds) === 1) ? $allowedWarehouseIds[0] : null;

        $pnl = $this->reportingService->getProfitAndLoss($startDate, $endDate, $warehouseId);

        return [
            'is_authoritative' => true,
            'unavailability_message' => null,
            'gross_revenue' => round((float) ($pnl['gross_revenue'] ?? 0.0), 2),
            'net_revenue' => round((float) ($pnl['net_revenue'] ?? 0.0), 2),
            'total_cogs' => round((float) ($pnl['total_cogs'] ?? 0.0), 2),
            'gross_profit' => round((float) ($pnl['gross_profit'] ?? 0.0), 2),
            'total_expenses' => round((float) ($pnl['total_expenses'] ?? 0.0), 2),
            'net_profit' => round((float) ($pnl['net_profit'] ?? 0.0), 2),
            'profit_type' => 'gl_financial_profit',
        ];
    }

    /**
     * GL Balance sheet, strictly respecting accounting mode and scope.
     */
    public function balanceSheet(
        AssistantAccessContext|\Modules\AIAssistant\DTO\WarehouseScope $context,
        ?string $asOfDate = null,
        ?string $fiscalYearStart = null
    ): array {
        if (!$this->accountingModeService->isDoubleEntryAuthoritative()) {
            return [
                'is_authoritative' => false,
                'unavailability_message' => 'Financial statement unavailable because double-entry accounting is not currently authoritative for this business.',
                'total_assets' => 0.0,
                'total_liabilities' => 0.0,
                'total_equity' => 0.0,
            ];
        }

        $isRestricted = $context instanceof \Modules\AIAssistant\DTO\WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof \Modules\AIAssistant\DTO\WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'is_authoritative' => true,
                'unavailability_message' => 'An active warehouse assignment is required to view financial statements.',
                'total_assets' => 0.0,
                'total_liabilities' => 0.0,
                'total_equity' => 0.0,
            ];
        }

        if ($ownUserId !== null) {
            return [
                'is_authoritative' => true,
                'unavailability_message' => 'User-restricted scopes are not permitted to view financial statements.',
                'total_assets' => 0.0,
                'total_liabilities' => 0.0,
                'total_equity' => 0.0,
            ];
        }

        $warehouseId = ($isRestricted && count($allowedWarehouseIds) === 1) ? $allowedWarehouseIds[0] : null;

        $targetDate = $asOfDate ?? now()->toDateString();
        $targetFiscalStart = $fiscalYearStart ?? now()->startOfYear()->toDateString();

        $bs = $this->reportingService->getBalanceSheet($targetDate, $targetFiscalStart, $warehouseId);

        return [
            'is_authoritative' => true,
            'unavailability_message' => null,
            'total_assets' => round((float) ($bs['total_assets'] ?? 0.0), 2),
            'total_liabilities' => round((float) ($bs['total_liabilities'] ?? 0.0), 2),
            'total_equity' => round((float) ($bs['total_equity'] ?? 0.0), 2),
            'retained_earnings' => round((float) ($bs['retained_earnings'] ?? 0.0), 2),
            'current_year_earnings' => round((float) ($bs['current_year_earnings'] ?? 0.0), 2),
        ];
    }
}
