<?php

namespace Modules\AIAssistant\DTO;

use InvalidArgumentException;

final readonly class AssistantResponseData
{
    use ValidatesJsonSafety;

    public function __construct(
        public string $textSummary,
        public string $responseType = 'text',
        public array $cards = [],
        public array $table = [],
        public array $links = [],
        public array $warnings = [],
        public array $errors = [],
        public array $metadata = [],
        public ?string $status = null,
        public ?string $skill = null,
        public ?string $specialist = null,
        public array $suggestedFollowups = []
    ) {
        $validResponseTypes = ['text', 'card', 'table', 'chart', 'error'];
        if (!in_array($this->responseType, $validResponseTypes, true)) {
            throw new InvalidArgumentException("Invalid response type: {$this->responseType}. Must be one of: " . implode(', ', $validResponseTypes));
        }

        // Validate cards: list entries with required non-empty string title and a JSON-safe value
        if (!empty($this->cards) && !array_is_list($this->cards)) {
            throw new InvalidArgumentException("Invalid cards: must be a list.");
        }
        foreach ($this->cards as $index => $card) {
            if (!is_array($card)) {
                throw new InvalidArgumentException("Invalid card at index {$index}: must be an array.");
            }
            if (!isset($card['title']) || !is_string($card['title']) || trim($card['title']) === '') {
                throw new InvalidArgumentException("Invalid card at index {$index}: title must be a non-empty string.");
            }
            if (!array_key_exists('value', $card)) {
                throw new InvalidArgumentException("Invalid card at index {$index}: value is required.");
            }
        }

        // Validate table: empty or an associative structure with string columns and array rows;
        if (!empty($this->table)) {
            if (!isset($this->table['columns']) || !is_array($this->table['columns'])) {
                throw new InvalidArgumentException("Invalid table: missing or invalid columns array.");
            }
            if (!array_is_list($this->table['columns'])) {
                throw new InvalidArgumentException("Invalid table columns: must be a list.");
            }
            $colCount = count($this->table['columns']);
            foreach ($this->table['columns'] as $colIndex => $column) {
                if (!is_string($column)) {
                    throw new InvalidArgumentException("Invalid table column at index {$colIndex}: must be a string.");
                }
            }
            if (!isset($this->table['rows']) || !is_array($this->table['rows'])) {
                throw new InvalidArgumentException("Invalid table: missing or invalid rows array.");
            }
            if (!array_is_list($this->table['rows'])) {
                throw new InvalidArgumentException("Invalid table rows: must be a list.");
            }
            foreach ($this->table['rows'] as $rowIndex => $row) {
                if (!is_array($row) || !array_is_list($row)) {
                    throw new InvalidArgumentException("Invalid table row at index {$rowIndex}: must be a list.");
                }
                if (count($row) !== $colCount) {
                    throw new InvalidArgumentException("Invalid table row at index {$rowIndex}: row width must equal column count.");
                }
            }
        }

        // Validate links: list entries with required non-empty string label and URL/route value
        if (!empty($this->links) && !array_is_list($this->links)) {
            throw new InvalidArgumentException("Invalid links: must be a list.");
        }
        foreach ($this->links as $index => $link) {
            if (!is_array($link)) {
                throw new InvalidArgumentException("Invalid link at index {$index}: must be an array.");
            }
            if (!isset($link['label']) || !is_string($link['label']) || trim($link['label']) === '') {
                throw new InvalidArgumentException("Invalid link at index {$index}: label must be a non-empty string.");
            }
            if (!isset($link['url']) || !is_string($link['url']) || trim($link['url']) === '') {
                throw new InvalidArgumentException("Invalid link at index {$index}: url must be a non-empty string.");
            }
        }

        // Validate warnings and errors: lists of strings
        if (!empty($this->warnings) && !array_is_list($this->warnings)) {
            throw new InvalidArgumentException("Invalid warnings: must be a list.");
        }
        if (!empty($this->errors) && !array_is_list($this->errors)) {
            throw new InvalidArgumentException("Invalid errors: must be a list.");
        }
        foreach (['warnings' => $this->warnings, 'errors' => $this->errors] as $type => $list) {
            foreach ($list as $index => $item) {
                if (!is_string($item)) {
                    throw new InvalidArgumentException("Invalid {$type} at index {$index}: must be a string.");
                }
            }
        }

        self::ensureJsonPayloadSafe($this->toArray());
    }

    /**
     * Resolves the canonical status string.
     * Allowed: success, empty, permission_denied, module_disabled, unsupported_scope, accounting_not_authoritative, clarification_required, error
     */
    public function resolveStatus(): string
    {
        if ($this->status !== null) {
            return $this->status;
        }

        if (!empty($this->metadata['status'])) {
            return (string) $this->metadata['status'];
        }

        if ($this->responseType === 'error' || !empty($this->errors)) {
            $reason = $this->metadata['reason_code'] ?? $this->metadata['reason'] ?? $this->metadata['state'] ?? null;
            return match ($reason) {
                'ACCOUNTING_NOT_AUTHORITATIVE', 'accounting_not_authoritative' => 'accounting_not_authoritative',
                'MODULE_DISABLED', 'module_disabled' => 'module_disabled',
                'PERMISSION_DENIED', 'permission_denied' => 'permission_denied',
                'UNAUTHORIZED_PARAMETER' => 'permission_denied',
                'UNSUPPORTED_SCOPE', 'unsupported_scope', 'empty_warehouse_scope', 'own_access_restriction' => 'unsupported_scope',
                default => 'error',
            };
        }

        if (($this->metadata['failed_closed'] ?? false) === true) {
            $reason = $this->metadata['reason'] ?? $this->metadata['reason_code'] ?? null;
            if (in_array($reason, ['empty_warehouse_scope', 'own_access_restriction', 'UNSUPPORTED_SCOPE'], true)) {
                return 'unsupported_scope';
            }
            return 'permission_denied';
        }

        if (($this->metadata['reason'] ?? null) === 'accounting_not_authoritative') {
            return 'accounting_not_authoritative';
        }

        if (($this->metadata['entity_found'] ?? null) === false) {
            return 'empty';
        }

        $isTableEmpty = empty($this->table['rows']);
        $isCardsEmpty = empty($this->cards);

        // If card value is explicitly 0 or summary indicates empty
        if (!empty($this->cards) && count($this->cards) === 1 && $this->cards[0]['value'] === 0 && $isTableEmpty) {
            return 'empty';
        }

        if ($isTableEmpty && $isCardsEmpty) {
            return 'empty';
        }

        return 'success';
    }

    public function resolveSkill(): ?string
    {
        return $this->skill ?? $this->metadata['skill'] ?? null;
    }

    public function resolveSpecialist(): string
    {
        if ($this->specialist !== null) {
            return $this->specialist;
        }

        $skill = $this->resolveSkill();
        return match ($skill) {
            'sales_summary', 'top_products', 'customer_due' => 'sales',
            'purchase_summary', 'low_stock', 'slow_moving_products', 'supplier_due' => 'supply',
            'cash_bank_summary', 'expense_summary', 'financial_pnl' => 'finance',
            default => 'business',
        };
    }

    /**
     * @return string[]
     */
    public function resolveSuggestedFollowups(): array
    {
        if (!empty($this->suggestedFollowups)) {
            return $this->suggestedFollowups;
        }

        if (!empty($this->metadata['clarification_choices']) && is_array($this->metadata['clarification_choices'])) {
            return array_values(array_filter(array_column($this->metadata['clarification_choices'], 'prompt')));
        }

        $skill = $this->resolveSkill();
        return match ($skill) {
            'daily_snapshot' => ['sales today', 'low stock products', 'cash and bank summary'],
            'sales_summary' => ['top selling products', 'customer due summary', 'today purchases'],
            'top_products' => ['sales summary', 'low stock products'],
            'customer_due' => ['sales summary', 'top selling products'],
            'supplier_due' => ['purchases today', 'cash and bank summary'],
            'low_stock' => ['purchases today', 'slow moving products'],
            'slow_moving_products' => ['low stock products', 'top selling products'],
            'cash_bank_summary' => ['expense summary', 'today sales'],
            'expense_summary' => ['cash and bank summary', 'financial pnl'],
            'financial_pnl' => ['cash and bank summary', 'sales summary'],
            default => ['daily snapshot', 'today sales', 'low stock products'],
        };
    }

    /**
     * Canonical schema representation:
     * { skill, status, specialist, summary, metrics, rows, context, availability, suggested_followups }
     *
     * @return array{
     *     skill: ?string,
     *     status: string,
     *     specialist: string,
     *     summary: string,
     *     metrics: array<array{title: string, value: mixed}>,
     *     rows: array<array<mixed>>,
     *     context: array<string, mixed>,
     *     availability: array{state: string, is_available: bool},
     *     suggested_followups: string[]
     * }
     */
    public function toCanonical(): array
    {
        $status = $this->resolveStatus();
        $skill = $this->resolveSkill();
        $specialist = $this->resolveSpecialist();
        $followups = $this->resolveSuggestedFollowups();

        $contextMetadata = array_filter(
            $this->metadata,
            fn($k) => !in_array($k, ['status', 'clarification_choices'], true),
            ARRAY_FILTER_USE_KEY
        );

        $unavailableStatuses = ['permission_denied', 'module_disabled', 'unsupported_scope', 'accounting_not_authoritative'];

        return [
            'skill' => $skill,
            'status' => $status,
            'specialist' => $specialist,
            'summary' => $this->textSummary,
            'metrics' => $this->cards,
            'rows' => $this->table['rows'] ?? [],
            'context' => $contextMetadata,
            'availability' => [
                'state' => in_array($status, $unavailableStatuses, true) ? $status : 'available',
                'is_available' => !in_array($status, array_merge($unavailableStatuses, ['error']), true),
            ],
            'suggested_followups' => $followups,
        ];
    }

    /**
     * Unified response serialization returning canonical schema and legacy fields.
     */
    public function toArray(): array
    {
        $canonical = $this->toCanonical();

        return [
            // Canonical schema contract
            'skill' => $canonical['skill'],
            'status' => $canonical['status'],
            'specialist' => $canonical['specialist'],
            'summary' => $canonical['summary'],
            'metrics' => $canonical['metrics'],
            'rows' => $canonical['rows'],
            'context' => $canonical['context'],
            'availability' => $canonical['availability'],
            'suggested_followups' => $canonical['suggested_followups'],

            // Legacy backward-compatible keys
            'text_summary' => $this->textSummary,
            'response_type' => $this->responseType,
            'cards' => $this->cards,
            'table' => $this->table,
            'links' => $this->links,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'metadata' => $this->metadata,
        ];
    }
}
