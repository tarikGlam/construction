<?php

namespace Modules\AIAssistant\DTO;

use App\Models\User;
use Modules\AIAssistant\Security\AssistantAccessContext;

final readonly class AssistantContextData
{
    use ValidatesJsonSafety;

    public function __construct(
        public ?string $tenantId = null,
        public ?int $userId = null,
        public array $businessContext = [],
        public array $systemContext = [],
        public ?AssistantAccessContext $accessContext = null
    ) {
        self::ensureJsonPayloadSafe($this->toArray());
    }

    public function resolveUser(): ?User
    {
        if ($this->accessContext?->user !== null) {
            return $this->accessContext->user;
        }

        return $this->userId ? User::find($this->userId) : null;
    }

    public function resolveAccessContext(): AssistantAccessContext
    {
        if ($this->accessContext !== null) {
            return $this->accessContext;
        }

        $user = $this->resolveUser();
        $pageContext = $this->businessContext['page_context'] ?? [];
        return AssistantAccessContext::fromUser($user, $pageContext, $this->tenantId);
    }

    public function resolveWarehouseScope(): WarehouseScope
    {
        if ($this->accessContext !== null) {
            return $this->accessContext->toWarehouseScope();
        }

        return WarehouseScope::fromContext($this);
    }

    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'business_context' => $this->businessContext,
            'system_context' => $this->systemContext,
        ];
    }
}
