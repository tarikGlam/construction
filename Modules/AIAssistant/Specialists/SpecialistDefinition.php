<?php

namespace Modules\AIAssistant\Specialists;

final readonly class SpecialistDefinition
{
    /**
     * @param string $key Permanent business key (e.g. 'business', 'sales', 'supply', 'finance', 'people', 'restaurant')
     * @param string $displayName Configurable presentation name (default: 'Salama', 'Jason', 'Abdul', 'Nora', 'Maya', 'Rami')
     * @param string $role Human-readable role description
     * @param string $avatar Icon or avatar representation
     * @param string $welcomeMessage Greeting message for this specialist
     * @param bool $enabled Whether this specialist is active
     * @param array<string> $responsibilities Specific areas of responsibility
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public string $role,
        public string $avatar,
        public string $welcomeMessage,
        public bool $enabled = true,
        public array $responsibilities = [],
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'display_name' => $this->displayName,
            'role' => $this->role,
            'avatar' => $this->avatar,
            'welcome_message' => $this->welcomeMessage,
            'enabled' => $this->enabled,
            'responsibilities' => $this->responsibilities,
        ];
    }
}
