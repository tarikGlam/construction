<?php

namespace Modules\AIAssistant\DTO;

final readonly class QuestionDefinition
{
    /**
     * @param string $id Unique question identifier
     * @param string $label Human-readable title/label for UI chips and menus
     * @param string $prompt Exact prompt dispatched when this question is clicked
     * @param string $specialist Responsible specialist ('business', 'sales', 'supply', 'finance', 'people', 'restaurant')
     * @param string $category Grouping category (e.g. 'Sales & Customers', 'Inventory', etc.)
     * @param string $targetSkillKey Canonical skill key triggered by this question
     * @param array<string> $requiredPermissions Spatie permissions required to see and use this question
     * @param string|null $moduleRequirement Optional module requirement (e.g. 'restaurant', 'import_batch')
     * @param array<string> $applicablePages Route names or path patterns where this question is suggested
     * @param bool $isPopular Whether this question is highlighted as a popular/quick action
     * @param int $sortOrder Deterministic sorting weight
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $prompt,
        public string $specialist,
        public string $category,
        public string $targetSkillKey,
        public array $requiredPermissions = [],
        public ?string $moduleRequirement = null,
        public array $applicablePages = [],
        public bool $isPopular = false,
        public int $sortOrder = 100,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'prompt' => $this->prompt,
            'specialist' => $this->specialist,
            'category' => $this->category,
            'target_skill_key' => $this->targetSkillKey,
            'required_permissions' => $this->requiredPermissions,
            'module_requirement' => $this->moduleRequirement,
            'applicable_pages' => $this->applicablePages,
            'is_popular' => $this->isPopular,
            'sort_order' => $this->sortOrder,
        ];
    }
}
