<?php

namespace Modules\AIAssistant\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Modules\AIAssistant\Entities\AIConversation;
use Modules\AIAssistant\Entities\AIMessage;
use Throwable;

class AssistantExecutionService
{
    public function __construct(
        private AssistantOrchestrator $orchestrator
    ) {}

    /**
     * Executes the prompt without persisting any conversation.
     * Used by the generic /prompt endpoint.
     */
    public function execute(string $prompt, User $user, array $pageContext = []): AssistantResponseData
    {
        $accessContext = \Modules\AIAssistant\Security\AssistantAccessContext::fromUser($user, $pageContext);

        $parser = new \Modules\AIAssistant\Services\StructuredIntentParser();
        $parseResult = $parser->parse($prompt, $accessContext);

        if (!$parseResult['is_authorized']) {
            $userMessage = $parseResult['unauthorized_message'] ?? __('db.ai_assistant_permission_denied');
            return new AssistantResponseData(
                textSummary: $userMessage,
                responseType: 'error',
                errors: [$userMessage],
                metadata: [
                    'failed_closed' => true,
                    'reason_code' => 'UNAUTHORIZED_PARAMETER',
                    'status' => 'permission_denied',
                ]
            );
        }

        if ($parseResult['clarification_needed']) {
            return new AssistantResponseData(
                textSummary: $parseResult['clarification_message'] ?? 'Please clarify your request.',
                responseType: 'card',
                metadata: [
                    'status' => 'clarification_required',
                    'clarification_choices' => $parseResult['clarification_choices'],
                ]
            );
        }

        if (!empty($parseResult['parameters']['warehouse_id'])) {
            $pageContext['warehouse_id'] = $parseResult['parameters']['warehouse_id'];
            $accessContext = \Modules\AIAssistant\Security\AssistantAccessContext::fromUser($user, $pageContext);
        }

        $businessContext = $accessContext->toBusinessContext();
        $businessContext['parsed_parameters'] = $parseResult['parameters'];
        if ($parseResult['skill'] !== null) {
            $businessContext['parsed_skill'] = $parseResult['skill'];
        }

        $message = new AssistantMessageData('user', $prompt);
        $context = new AssistantContextData(
            tenantId: $accessContext->tenantId,
            userId: $user->id,
            businessContext: $businessContext,
            accessContext: $accessContext
        );

        return $this->orchestrator->executeStructured($message, $context);
    }

    /**
     * Executes the prompt and optionally creates/appends to a conversation transactionally.
     *
     * @return array{conversation: AIConversation, response: AssistantResponseData}
     */
    public function executeAndPersist(
        string $prompt,
        User $user,
        ?AIConversation $conversation = null,
        ?string $displayPrompt = null,
        array $pageContext = []
    ): array
    {
        // We use a transaction so if execute() fails (e.g., orchestrator throws), 
        // no partial message pair is saved. If execute() succeeds but returns fallback,
        // it persists the fallback safely.
        return DB::transaction(function () use ($prompt, $user, $conversation, $displayPrompt, $pageContext) {
            $tenantId = function_exists('tenant') && tenant('id') ? (string) tenant('id') : null;
            $persistedPrompt = $displayPrompt ?: $prompt;

            if ($conversation !== null) {
                $actualTenantId = $conversation->tenant_id !== null ? (string) $conversation->tenant_id : null;
                if ($conversation->user_id !== $user->id || $actualTenantId !== $tenantId) {
                    abort(404);
                }
            } else {
                // Ensure conversation title is bounded to schema length (usually 255)
                $title = mb_substr($persistedPrompt, 0, 100);
                
                $conversation = AIConversation::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'provider' => 'structured',
                    'mode' => 'structured',
                    'title' => $title ?: __('db.ai_assistant_new_conversation'),
                ]);
            }

            // Persist User Message
            AIMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $persistedPrompt,
                'response_type' => 'text',
                'metadata' => [],
            ]);

            // Execute (this throws if an unexpected error occurs, rolling back the tx)
            $response = $this->execute($prompt, $user, $pageContext);

            // Persist Assistant Message
            AIMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $response->textSummary,
                'response_type' => $response->responseType,
                'metadata' => [
                    'cards' => $response->cards,
                    'table' => $response->table,
                    'warnings' => $response->warnings,
                    'errors' => $response->errors,
                    'links' => $response->links,
                    'metadata' => $response->metadata,
                    'skill' => $response->metadata['skill'] ?? null,
                ],
            ]);

            // Update conversation's updated_at to bring it to the top
            $conversation->touch();

            return [
                'conversation' => $conversation,
                'response' => $response,
            ];
        });
    }
}
