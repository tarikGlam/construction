<?php

namespace Modules\AIAssistant\DTO;

use Closure;
use Modules\AIAssistant\Contracts\AssistantSkill;

final readonly class SkillDefinition
{
    /**
     * @param string $skillId Unique dot-notation identifier (e.g., 'sales.summary')
     * @param string $specialty Specialist assistant domain ('business', 'sales', 'supply', 'finance', 'people', 'restaurant')
     * @param string|null $module Optional module name if bundled or optional
     * @param string $description Human-readable description
     * @param array<string, mixed> $parameters Input parameters schema
     * @param array<string> $requiredPermissions SalePro permissions required for this skill
     * @param string $permissionPolicy How permissions are evaluated: 'all' (default, fail-closed) or 'any'
     * @param string $warehousePolicy Warehouse scoping requirement ('none', 'optional', 'required', 'strict')
     * @param string|null $moduleRequirement Specific module that must be operational
     * @param mixed $handler Invokable, class string, closure, or AssistantSkill instance
     * @param int $resultLimit Default maximum number of records returned
     * @param bool $supportsContext Whether this skill accepts page context
     * @param bool $futureLlmToolEligible Whether this skill is eligible as an LLM function/tool in Phase 3
     * @param bool $requiresAuthoritativeAccounting Whether this skill requires active double-entry GL
     * @param bool $supportsPortal Whether customer portal identities can access this skill
     */
    public function __construct(
        public string $skillId,
        public string $specialty,
        public ?string $module = null,
        public string $description = '',
        public array $parameters = [],
        public array $requiredPermissions = [],
        public string $permissionPolicy = 'all',
        public string $warehousePolicy = 'optional',
        public ?string $moduleRequirement = null,
        public mixed $handler = null,
        public int $resultLimit = 10,
        public bool $supportsContext = true,
        public bool $futureLlmToolEligible = true,
        public bool $requiresAuthoritativeAccounting = false,
        public bool $supportsPortal = false,
    ) {}

    /**
     * Wrap a legacy AssistantSkill instance into a declarative SkillDefinition.
     */
    public static function fromLegacySkill(AssistantSkill $skill): self
    {
        $key = $skill->key();

        // Infer default specialty and permissions from legacy skill key
        [$specialty, $permissions, $permissionPolicy] = match ($key) {
            'sales_summary', 'top_products', 'slow_moving_products' => ['sales', ['sales-index'], 'all'],
            'purchase_summary' => ['supply', ['purchases-index'], 'all'],
            'low_stock' => ['supply', ['products-index'], 'all'],
            'customer_due' => ['sales', ['customers-index', 'sales-index'], 'all'],
            'supplier_due' => ['supply', ['suppliers-index', 'purchases-index'], 'all'],
            'expense_summary' => ['finance', ['expenses-index'], 'all'],
            'cash_bank_summary' => ['finance', ['account-index'], 'all'],
            'financial_pnl' => ['finance', ['account-index'], 'all'],
            'daily_snapshot' => ['business', [], 'all'],
            default => ['business', [], 'all'],
        };

        return new self(
            skillId: $key,
            specialty: $specialty,
            description: $skill->description(),
            requiredPermissions: $permissions,
            permissionPolicy: $permissionPolicy,
            warehousePolicy: 'optional',
            handler: $skill,
            resultLimit: 10,
            supportsContext: true,
            futureLlmToolEligible: true,
            requiresAuthoritativeAccounting: ($key === 'financial_pnl')
        );
    }
}
