<?php

namespace Modules\AIAssistant\Services;

use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantResponseData;

class AssistantOrchestrator
{
    public function __construct(
        private SkillRegistry $registry,
        private ?\Modules\AIAssistant\Contracts\AssistantSkillRunRecorder $recorder = null
    ) {
    }

    /**
     * Executes the assistant logic in pure structured mode.
     * It resolves a matching skill from the registry and invokes it.
     * If no skill matches, it returns a safe, non-technical fallback response.
     * 
     * Exception Policy: Any exceptions thrown by `resolve()` (e.g., in a skill's `canHandle`) 
     * or by `$skill->handle()` are deliberately allowed to bubble up to the caller. 
     * We do not silently swallow them.
     */
    public function executeStructured(AssistantMessageData $message, AssistantContextData $context): AssistantResponseData
    {
        $skill = null;
        if (!empty($context->businessContext['parsed_skill'])) {
            $skill = $this->registry->get($context->businessContext['parsed_skill']);
        }
        if ($skill === null) {
            $skill = $this->registry->resolve($message);
        }

        if ($skill === null) {
            $parsedKey = $context->businessContext['parsed_skill'] ?? null;
            if ($parsedKey === null && str_starts_with($message->content, 'intent:')) {
                $parsedKey = substr(trim($message->content), 7);
            }
            if ($parsedKey !== null && OptionalModulePolicy::isDeferred($parsedKey)) {
                $msg = OptionalModulePolicy::getDeferredMessage($parsedKey);
                $details = OptionalModulePolicy::getDeferredDetails($parsedKey);
                return new AssistantResponseData(
                    textSummary: $msg,
                    responseType: 'text',
                    warnings: [$msg],
                    metadata: [
                        'status' => 'module_disabled',
                        'reason_code' => 'MODULE_DISABLED',
                        'module' => $details['module'] ?? 'optional',
                        'feature' => $parsedKey,
                        'is_deferred' => true,
                    ]
                );
            }

            return new AssistantResponseData(
                textSummary: __('db.ai_assistant_fallback_summary'),
                responseType: 'text',
                warnings: [__('db.ai_assistant_fallback_warning')]
            );
        }

        $accessContext = $context->resolveAccessContext();
        $availability = $this->registry->checkAvailability($skill->key(), $accessContext);

        if (!$availability->isAvailable) {
            $userMessage = $availability->userMessage ?? __('db.ai_assistant_permission_denied');
            return new AssistantResponseData(
                textSummary: $userMessage,
                responseType: 'error',
                errors: [$userMessage],
                metadata: [
                    'failed_closed' => true,
                    'reason_code' => $availability->reasonCode,
                    'state' => $availability->state,
                ]
            );
        }

        $startTime = microtime(true);
        $response = $skill->handle($message, $context);
        $executionMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($this->recorder !== null) {
            $this->recorder->record($skill, $message, $context, $response, $executionMs);
        }

        return $response;
    }
}
