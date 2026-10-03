<?php

namespace Modules\AIAssistant\DTO;

final readonly class SkillAvailabilityResult
{
    public const AVAILABLE = 'available';
    public const PERMISSION_DENIED = 'permission_denied';
    public const MODULE_DISABLED = 'module_disabled';
    public const ACCOUNTING_NOT_AUTHORITATIVE = 'accounting_not_authoritative';
    public const UNSUPPORTED_SCOPE = 'unsupported_scope';
    public const TEMPORARILY_UNAVAILABLE = 'temporarily_unavailable';

    public function __construct(
        public string $state,
        public bool $isAvailable,
        public ?string $userMessage = null,
        public ?string $reasonCode = null,
    ) {}

    public static function available(): self
    {
        return new self(self::AVAILABLE, true);
    }

    public static function permissionDenied(?string $message = null): self
    {
        return new self(
            state: self::PERMISSION_DENIED,
            isAvailable: false,
            userMessage: $message ?: __('db.ai_assistant_permission_denied'),
            reasonCode: 'PERMISSION_DENIED'
        );
    }

    public static function moduleDisabled(string $module, ?string $message = null): self
    {
        return new self(
            state: self::MODULE_DISABLED,
            isAvailable: false,
            userMessage: $message ?: __('db.ai_assistant_module_disabled', ['module' => $module]),
            reasonCode: 'MODULE_DISABLED'
        );
    }

    public static function accountingNotAuthoritative(?string $message = null): self
    {
        return new self(
            state: self::ACCOUNTING_NOT_AUTHORITATIVE,
            isAvailable: false,
            userMessage: $message ?: 'Financial statement unavailable because double-entry accounting is not currently authoritative for this business.',
            reasonCode: 'ACCOUNTING_NOT_AUTHORITATIVE'
        );
    }

    public static function unsupportedScope(?string $message = null): self
    {
        return new self(
            state: self::UNSUPPORTED_SCOPE,
            isAvailable: false,
            userMessage: $message ?: __('db.ai_assistant_scope_unsupported'),
            reasonCode: 'UNSUPPORTED_SCOPE'
        );
    }

    public static function temporarilyUnavailable(?string $message = null): self
    {
        return new self(
            state: self::TEMPORARILY_UNAVAILABLE,
            isAvailable: false,
            userMessage: $message ?: __('db.ai_assistant_temporarily_unavailable'),
            reasonCode: 'TEMPORARILY_UNAVAILABLE'
        );
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }
}
