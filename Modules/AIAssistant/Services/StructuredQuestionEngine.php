<?php

namespace Modules\AIAssistant\Services;

use Modules\AIAssistant\DTO\QuestionDefinition;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Specialists\SpecialistRegistry;
use Modules\AIAssistant\Services\SkillRegistry;

class StructuredQuestionEngine
{
    /**
     * @var array<string, QuestionDefinition>
     */
    private array $questions = [];

    public function __construct(
        private ?SpecialistRegistry $specialistRegistry = null,
        private ?SkillRegistry $skillRegistry = null
    ) {
        $this->specialistRegistry ??= app(SpecialistRegistry::class);
        $this->skillRegistry ??= app(SkillRegistry::class);
        $this->registerDefaults();
    }

    /**
     * Register a new structured question definition.
     */
    public function register(QuestionDefinition $question): void
    {
        $this->questions[$question->id] = $question;
    }

    /**
     * Lookup a single question definition by ID.
     */
    public function get(string $id): ?QuestionDefinition
    {
        return $this->questions[$id] ?? null;
    }

    /**
     * Get all registered questions.
     *
     * @return array<string, QuestionDefinition>
     */
    public function all(): array
    {
        return $this->questions;
    }

    /**
     * Filter questions available to the given AssistantAccessContext.
     * Strictly fail-closed based on permissions, modules, and data boundaries.
     *
     * @return QuestionDefinition[]
     */
    public function getAvailableQuestions(
        AssistantAccessContext $context,
        ?string $specialist = null,
        ?string $category = null
    ): array {
        $available = [];

        foreach ($this->questions as $q) {
            // 0. Executable registry check: fail-closed if skill not registered or unavailable
            if ($this->skillRegistry !== null) {
                $skill = $this->skillRegistry->get($q->targetSkillKey);
                if (!$skill) {
                    continue;
                }
                $availability = $this->skillRegistry->checkAvailability($q->targetSkillKey, $context);
                if (!$availability->isAvailable()) {
                    continue;
                }
            }

            // 1. Specialist filter
            if ($specialist !== null && $q->specialist !== $specialist) {
                continue;
            }

            // 2. Category filter
            if ($category !== null && $q->category !== $category) {
                continue;
            }

            // 3. Permission checks (fail-closed)
            if (!empty($q->requiredPermissions)) {
                if (!$context->hasAllPermissions($q->requiredPermissions)) {
                    continue;
                }
            }

            // 4. Module requirement checks
            if ($q->moduleRequirement !== null) {
                if (!$context->isModuleAvailable($q->moduleRequirement)) {
                    continue;
                }
            }

            // 5. Customer portal isolation
            if ($context->isPortalUser) {
                // Portal identities are strictly limited to their own purchases
                if (!in_array($q->id, ['customer_purchase_history', 'customer_profile'], true)) {
                    continue;
                }
            }

            // 6. Own-user staff restrictions (staff_access = 'own')
            if ($context->ownUserId !== null) {
                // Own-user staff cannot access company-wide P&L or global debtor lists
                if (in_array($q->id, ['profit_and_loss', 'customer_dues', 'supplier_dues', 'cash_bank_balances'], true)) {
                    continue;
                }
            }

            // 7. Empty warehouse assignment restrictions
            if ($context->isRestrictedWarehouseAccess && empty($context->allowedWarehouseIds)) {
                // Staff with zero allowed warehouses cannot query warehouse inventory or table status
                if (in_array($q->id, ['low_stock_alerts', 'batch_expiry_alerts', 'slow_moving_products', 'table_occupancy', 'open_table_orders'], true)) {
                    continue;
                }
            }

            $available[] = $q;
        }

        // Sort deterministically by sort_order ASC, then label ASC
        usort($available, function (QuestionDefinition $a, QuestionDefinition $b) {
            if ($a->sortOrder === $b->sortOrder) {
                return strcmp($a->label, $b->label);
            }
            return $a->sortOrder <=> $b->sortOrder;
        });

        return $available;
    }

    /**
     * Get suggested questions relevant to the current page/route context.
     *
     * @return QuestionDefinition[]
     */
    public function getSuggestionsForPage(
        AssistantAccessContext $context,
        string $pageName,
        int $limit = 6
    ): array {
        $boundedLimit = max(1, min($limit, 20));
        $allAvailable = $this->getAvailableQuestions($context);

        $matched = [];
        $unmatchedPopular = [];

        foreach ($allAvailable as $q) {
            $isPageMatch = false;
            foreach ($q->applicablePages as $pattern) {
                if ($this->matchPagePattern($pattern, $pageName)) {
                    $isPageMatch = true;
                    break;
                }
            }

            if ($isPageMatch) {
                $matched[] = $q;
            } elseif ($q->isPopular) {
                $unmatchedPopular[] = $q;
            }
        }

        // Combine page-matched questions with popular fallbacks up to $limit
        $combined = array_merge($matched, $unmatchedPopular);
        $unique = [];
        foreach ($combined as $item) {
            $unique[$item->id] = $item;
        }

        return array_slice(array_values($unique), 0, $boundedLimit);
    }

    /**
     * Get available questions grouped by specialist with specialist presentation metadata.
     *
     * @return array<string, array{
     *     specialist: array<string, mixed>,
     *     questions: array<array<string, mixed>>
     * }>
     */
    public function getAvailableGrouped(AssistantAccessContext $context): array
    {
        $available = $this->getAvailableQuestions($context);
        $grouped = [];

        foreach ($available as $q) {
            if (!isset($grouped[$q->specialist])) {
                $specialistDef = $this->specialistRegistry?->get($q->specialist)
                    ?? $this->specialistRegistry?->getDefault();

                $grouped[$q->specialist] = [
                    'specialist' => $specialistDef ? $specialistDef->toArray() : ['key' => $q->specialist, 'display_name' => ucfirst($q->specialist)],
                    'questions' => [],
                ];
            }

            $grouped[$q->specialist]['questions'][] = $q->toArray();
        }

        return $grouped;
    }

    private function matchPagePattern(string $pattern, string $pageName): bool
    {
        if ($pattern === '*' || $pattern === $pageName) {
            return true;
        }

        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');
            return str_starts_with($pageName, $prefix);
        }

        return false;
    }

    /**
     * Register default catalog of structured questions covering all specialists and domains.
     */
    private function registerDefaults(): void
    {
        // ---------------------------------------------------------------------
        // Business Manager (Salama)
        // ---------------------------------------------------------------------
        $this->register(new QuestionDefinition(
            id: 'daily_snapshot',
            label: "Daily Business Snapshot",
            prompt: "daily business snapshot",
            specialist: 'business',
            category: 'Executive Overview',
            targetSkillKey: 'daily_snapshot',
            requiredPermissions: [],
            applicablePages: ['dashboard', 'home', '*'],
            isPopular: true,
            sortOrder: 10
        ));

        $this->register(new QuestionDefinition(
            id: 'todays_business_summary',
            label: "Today's Performance Summary",
            prompt: "todays business summary",
            specialist: 'business',
            category: 'Executive Overview',
            targetSkillKey: 'daily_snapshot',
            requiredPermissions: [],
            applicablePages: ['dashboard', 'home'],
            isPopular: true,
            sortOrder: 15
        ));

        // ---------------------------------------------------------------------
        // Sales Specialist (Jason)
        // ---------------------------------------------------------------------
        $this->register(new QuestionDefinition(
            id: 'sales_today',
            label: "Today's Sales Summary",
            prompt: "today sales",
            specialist: 'sales',
            category: 'Sales & POS',
            targetSkillKey: 'sales_summary',
            requiredPermissions: ['sales-index'],
            applicablePages: ['sales.index', 'sales.create', 'sale.pos', 'dashboard'],
            isPopular: true,
            sortOrder: 20
        ));

        $this->register(new QuestionDefinition(
            id: 'top_selling_products',
            label: "Top Selling Products Today",
            prompt: "top selling products",
            specialist: 'sales',
            category: 'Sales & POS',
            targetSkillKey: 'top_products',
            requiredPermissions: ['sales-index'],
            applicablePages: ['sales.index', 'products.index', 'dashboard'],
            isPopular: true,
            sortOrder: 25
        ));

        $this->register(new QuestionDefinition(
            id: 'customer_dues',
            label: "Customer Outstanding Dues",
            prompt: "customer due summary",
            specialist: 'sales',
            category: 'Customers & Receivables',
            targetSkillKey: 'customer_due',
            requiredPermissions: ['customers-index', 'sales-index'],
            applicablePages: ['customers.index', 'sales.index'],
            isPopular: true,
            sortOrder: 30
        ));

        $this->register(new QuestionDefinition(
            id: 'slow_moving_products',
            label: "Slow-Moving Products (30 Days)",
            prompt: "slow moving products",
            specialist: 'sales',
            category: 'Sales Analytics',
            targetSkillKey: 'slow_moving_products',
            requiredPermissions: ['sales-index'],
            applicablePages: ['products.index', 'sales.index'],
            isPopular: false,
            sortOrder: 35
        ));

        // ---------------------------------------------------------------------
        // Supply & Inventory Specialist (Abdul)
        // ---------------------------------------------------------------------
        $this->register(new QuestionDefinition(
            id: 'low_stock_alerts',
            label: "Low Stock Inventory Alerts",
            prompt: "low stock",
            specialist: 'supply',
            category: 'Inventory & Stock',
            targetSkillKey: 'low_stock',
            requiredPermissions: ['products-index'],
            applicablePages: ['products.index', 'dashboard'],
            isPopular: true,
            sortOrder: 50
        ));

        $this->register(new QuestionDefinition(
            id: 'purchase_summary',
            label: "Today's Purchases Summary",
            prompt: "purchase summary",
            specialist: 'supply',
            category: 'Purchases & Receiving',
            targetSkillKey: 'purchase_summary',
            requiredPermissions: ['purchases-index'],
            applicablePages: ['purchases.index', 'purchases.create', 'dashboard'],
            isPopular: true,
            sortOrder: 55
        ));

        $this->register(new QuestionDefinition(
            id: 'supplier_dues',
            label: "Supplier Outstanding Payables",
            prompt: "supplier due summary",
            specialist: 'supply',
            category: 'Suppliers & Payables',
            targetSkillKey: 'supplier_due',
            requiredPermissions: ['suppliers-index', 'purchases-index'],
            applicablePages: ['suppliers.index', 'purchases.index'],
            isPopular: true,
            sortOrder: 60
        ));

        // ---------------------------------------------------------------------
        // Finance Specialist (Nora)
        // ---------------------------------------------------------------------
        $this->register(new QuestionDefinition(
            id: 'cash_bank_balances',
            label: "Cash & Bank Account Balances",
            prompt: "cash and bank summary",
            specialist: 'finance',
            category: 'Cash & Accounts',
            targetSkillKey: 'cash_bank_summary',
            requiredPermissions: ['account-index'],
            applicablePages: ['accounts.index', 'money-transfers.index', 'dashboard'],
            isPopular: true,
            sortOrder: 80
        ));

        $this->register(new QuestionDefinition(
            id: 'todays_expenses',
            label: "Today's Expense Summary",
            prompt: "todays expense summary",
            specialist: 'finance',
            category: 'Expenses',
            targetSkillKey: 'expense_summary',
            requiredPermissions: ['expenses-index'],
            applicablePages: ['expenses.index', 'expenses.create'],
            isPopular: true,
            sortOrder: 85
        ));

        $this->register(new QuestionDefinition(
            id: 'profit_and_loss',
            label: "Authoritative Profit & Loss (P&L)",
            prompt: "show profit and loss",
            specialist: 'finance',
            category: 'Financial Statements',
            targetSkillKey: 'financial_pnl',
            requiredPermissions: ['account-index'],
            applicablePages: ['accounts.index', 'financial-reports*'],
            isPopular: false,
            sortOrder: 90
        ));
    }
}
