<?php

namespace Modules\AIAssistant\Services;

use App\Services\AccountingModeService;
use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\SkillAvailabilityResult;
use Modules\AIAssistant\DTO\SkillDefinition;
use Modules\AIAssistant\Security\AssistantAccessContext;
use InvalidArgumentException;

class SkillRegistry
{
    /**
     * @var array<string, AssistantSkill>
     */
    private array $skills = [];

    /**
     * @var array<string, SkillDefinition>
     */
    private array $definitions = [];

    /**
     * Register a new skill or declarative skill definition. Order is deterministic based on registration sequence.
     * Duplicate keys are rejected.
     *
     * @throws InvalidArgumentException
     */
    public function register(AssistantSkill|SkillDefinition $skill): void
    {
        if ($skill instanceof SkillDefinition) {
            $this->registerDefinition($skill);
            return;
        }

        $key = $skill->key();
        
        if (array_key_exists($key, $this->skills)) {
            throw new InvalidArgumentException("Skill with key '{$key}' is already registered.");
        }
        
        $this->skills[$key] = $skill;
        $this->definitions[$key] = SkillDefinition::fromLegacySkill($skill);
    }

    /**
     * Register a declarative SkillDefinition explicitly.
     */
    public function registerDefinition(SkillDefinition $definition): void
    {
        $key = $definition->skillId;

        if (array_key_exists($key, $this->definitions)) {
            throw new InvalidArgumentException("Skill definition with key '{$key}' is already registered.");
        }

        $this->definitions[$key] = $definition;

        if ($definition->handler instanceof AssistantSkill) {
            $this->skills[$key] = $definition->handler;
        }
    }

    /**
     * Unregister a skill by key (used for test cleanup and dynamic lifecycle).
     */
    public function unregister(string $key): void
    {
        unset($this->skills[$key], $this->definitions[$key]);
    }

    /**
     * Resolves the first skill that can handle the given message.
     * Exceptions from `canHandle()` are not caught; they bubble up predictably.
     */
    public function resolve(AssistantMessageData $message): ?AssistantSkill
    {
        $canonicalKey = LocalizedIntentMatcher::resolve($message->content);
        if ($canonicalKey !== null && isset($this->skills[$canonicalKey])) {
            return $this->skills[$canonicalKey];
        }

        foreach ($this->skills as $skill) {
            if ($skill->canHandle($message)) {
                return $skill;
            }
        }
        
        return null;
    }

    /**
     * Lookup a skill by its exact key.
     */
    public function get(string $key): ?AssistantSkill
    {
        return $this->skills[$key] ?? null;
    }

    /**
     * Lookup a skill definition by its exact key.
     */
    public function getDefinition(string $key): ?SkillDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * Get all registered skills. The returned array is a copy, preventing accidental mutation.
     *
     * @return AssistantSkill[]
     */
    public function all(): array
    {
        return array_values($this->skills);
    }

    /**
     * Get all registered skill definitions.
     *
     * @return array<string, SkillDefinition>
     */
    public function allDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Get skills filtered by specialist domain.
     *
     * @return SkillDefinition[]
     */
    public function bySpecialty(string $specialty): array
    {
        $filtered = [];
        foreach ($this->definitions as $def) {
            if ($def->specialty === $specialty) {
                $filtered[] = $def;
            }
        }
        return $filtered;
    }

    /**
     * Check authorization and operational availability of a skill against the current user's access context.
     * Fail-closed.
     */
    public function checkAvailability(string|SkillDefinition $skill, AssistantAccessContext $accessContext): SkillAvailabilityResult
    {
        $definition = is_string($skill) ? $this->getDefinition($skill) : $skill;
        if (!$definition && is_string($skill) && isset($this->skills[$skill])) {
            $definition = SkillDefinition::fromLegacySkill($this->skills[$skill]);
            $this->definitions[$skill] = $definition;
        }

        if (!$definition) {
            return SkillAvailabilityResult::temporarilyUnavailable('Requested skill is not registered.');
        }

        // 1. Module requirement check
        if ($definition->moduleRequirement !== null) {
            if (!$accessContext->isModuleAvailable($definition->moduleRequirement)) {
                return SkillAvailabilityResult::moduleDisabled($definition->moduleRequirement);
            }
        }

        // 2. Permission check (respects permissionPolicy: 'all' or 'any')
        if (!empty($definition->requiredPermissions)) {
            $hasPermission = ($definition->permissionPolicy === 'any')
                ? $accessContext->hasAnyPermission($definition->requiredPermissions)
                : $accessContext->hasAllPermissions($definition->requiredPermissions);

            if (!$hasPermission) {
                return SkillAvailabilityResult::permissionDenied();
            }
        }

        // 3. Authoritative accounting check for financial statement skills
        if ($definition->requiresAuthoritativeAccounting) {
            /** @var AccountingModeService $accountingMode */
            $accountingMode = app(AccountingModeService::class);
            if (!$accountingMode->isDoubleEntryAuthoritative()) {
                return SkillAvailabilityResult::accountingNotAuthoritative();
            }
        }

        // 4. Warehouse scope check: if skill strictly requires warehouse and user has empty access
        if ($definition->warehousePolicy === 'required') {
            if ($accessContext->isRestrictedWarehouseAccess && empty($accessContext->allowedWarehouseIds)) {
                return SkillAvailabilityResult::unsupportedScope('An active warehouse assignment is required to execute this skill.');
            }
        }

        // 5. Portal check: portal identities can only access portal-supported skills
        if ($accessContext->isPortalUser && !$definition->supportsPortal) {
            return SkillAvailabilityResult::unsupportedScope('This skill is unavailable for customer portal accounts.');
        }

        return SkillAvailabilityResult::available();
    }
}
