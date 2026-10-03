<?php

namespace Modules\AIAssistant\Services;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Warehouse;
use Carbon\Carbon;
use Modules\AIAssistant\Security\AssistantAccessContext;

class StructuredIntentParser
{
    /**
     * Parse structured parameters (dates, limits, warehouses, entities) from a prompt.
     *
     * @return array{
     *     skill: ?string,
     *     parameters: array<string, mixed>,
     *     clarification_needed: bool,
     *     clarification_choices: array<array<string, mixed>>,
     *     clarification_message: ?string,
     *     is_authorized: bool,
     *     unauthorized_message: ?string
     * }
     */
    public function parse(string $prompt, ?AssistantAccessContext $context = null): array
    {
        $normalized = PromptNormalizer::normalize($prompt);
        $parameters = [];
        $stripped = $normalized;

        $clarificationNeeded = false;
        $clarificationChoices = [];
        $clarificationMessage = null;
        $isAuthorized = true;
        $unauthorizedMessage = null;

        // 1. Parse Limits ("top 5", "top 10", "top 20")
        if (preg_match('/\btop\s*(\d+)\b/i', $stripped, $matches)) {
            $limitVal = (int) $matches[1];
            if ($limitVal > 0) {
                $parameters['limit'] = min(50, $limitVal);
                $stripped = preg_replace('/\btop\s*\d+\b/i', 'top', $stripped);
            }
        }

        // 2. Parse Dates (today, yesterday, this week, last week, this month, last month)
        if (preg_match('/\b(this month|last month|this week|last week|yesterday|today)\b/i', $stripped, $matches)) {
            $period = strtolower($matches[1]);
            $dateRange = match ($period) {
                'today' => [
                    'period' => 'today',
                    'start_date' => Carbon::today()->toDateString(),
                    'end_date' => Carbon::today()->toDateString(),
                ],
                'yesterday' => [
                    'period' => 'yesterday',
                    'start_date' => Carbon::yesterday()->toDateString(),
                    'end_date' => Carbon::yesterday()->toDateString(),
                ],
                'this week' => [
                    'period' => 'this_week',
                    'start_date' => Carbon::now()->startOfWeek()->toDateString(),
                    'end_date' => Carbon::now()->endOfWeek()->toDateString(),
                ],
                'last week' => [
                    'period' => 'last_week',
                    'start_date' => Carbon::now()->subWeek()->startOfWeek()->toDateString(),
                    'end_date' => Carbon::now()->subWeek()->endOfWeek()->toDateString(),
                ],
                'this month' => [
                    'period' => 'this_month',
                    'start_date' => Carbon::now()->startOfMonth()->toDateString(),
                    'end_date' => Carbon::now()->endOfMonth()->toDateString(),
                ],
                'last month' => [
                    'period' => 'last_month',
                    'start_date' => Carbon::now()->subMonth()->startOfMonth()->toDateString(),
                    'end_date' => Carbon::now()->subMonth()->endOfMonth()->toDateString(),
                ],
                default => null,
            };

            if ($dateRange !== null) {
                $parameters['date_range'] = $dateRange;
                $stripped = preg_replace('/\b(this month|last month|this week|last week|yesterday|today)\b/i', '', $stripped);
            }
        }

        // 3. Parse Warehouse ("in warehouse 2", "warehouse 2", "for warehouse 1", "at warehouse 3")
        if (preg_match('/\b(?:in|at|for)?\s*warehouse\s*(\d+)\b/i', $stripped, $matches)) {
            $whId = (int) $matches[1];
            $stripped = trim(preg_replace('/\b(?:in|at|for)?\s*warehouse\s*\d+\b/i', '', $stripped));

            if ($context !== null) {
                if ($context->isRestrictedWarehouseAccess) {
                    if (!in_array($whId, $context->allowedWarehouseIds, true)) {
                        $isAuthorized = false;
                        $unauthorizedMessage = "You do not have authorization to access warehouse {$whId}.";
                    } else {
                        $parameters['warehouse_id'] = $whId;
                    }
                } else {
                    $whExists = Warehouse::where('id', $whId)->where('is_active', true)->exists();
                    if ($whExists) {
                        $parameters['warehouse_id'] = $whId;
                    }
                }
            } else {
                $parameters['warehouse_id'] = $whId;
            }
        }

        // 4. Parse Customer queries
        // Patterns:
        // "customer John Doe due", "customer John Doe balance", "customer John Doe owes"
        // "due for customer John Doe", "balance for customer John Doe", "debt of customer John Doe"
        $customerName = null;
        if (preg_match('/\bcustomer\s+([a-zA-Z0-9_\-\.\s]+?)\s+(?:due|dues|balance|debt|owe|owes)\b/i', $normalized, $matches)) {
            $customerName = trim($matches[1]);
        } elseif (preg_match('/\b(?:due|dues|balance|debt)\s+(?:for|of|from)\s+customer\s+([a-zA-Z0-9_\-\.\s]+)\b/i', $normalized, $matches)) {
            $customerName = trim($matches[1]);
        }

        if ($customerName !== null && $customerName !== '') {
            $stripped = 'customer due';

            if ($context && !$context->hasPermission('customers-index')) {
                $isAuthorized = false;
                $unauthorizedMessage = 'You do not have permission to view customer details.';
            } else {
                $customers = Customer::where('is_active', true)
                    ->where('name', 'like', "%{$customerName}%")
                    ->orderBy('name', 'asc')
                    ->limit(10)
                    ->get(['id', 'name', 'phone_number']);

                if ($customers->count() === 1) {
                    $parameters['entity_type'] = 'customer';
                    $parameters['entity_id'] = (int) $customers->first()->id;
                    $parameters['entity_name'] = (string) $customers->first()->name;
                } elseif ($customers->count() > 1) {
                    $clarificationNeeded = true;
                    $clarificationMessage = "Multiple customers matched '{$customerName}'. Please specify which one:";
                    $clarificationChoices = $customers->take(5)->map(fn($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'phone' => $c->phone_number,
                        'prompt' => "customer {$c->name} due",
                    ])->values()->all();
                } else {
                    $parameters['entity_type'] = 'customer';
                    $parameters['entity_id'] = null;
                    $parameters['entity_name'] = $customerName;
                    $parameters['entity_not_found'] = true;
                }
            }
        }

        // 5. Parse Supplier queries
        // Patterns:
        // "supplier Apex due", "supplier Apex balance", "supplier Apex debt", "supplier Apex payable"
        // "due to supplier Apex", "balance of supplier Apex", "payable to supplier Apex"
        $supplierName = null;
        if (preg_match('/\bsupplier\s+([a-zA-Z0-9_\-\.\s]+?)\s+(?:due|dues|balance|debt|payable|owes)\b/i', $normalized, $matches)) {
            $supplierName = trim($matches[1]);
        } elseif (preg_match('/\b(?:due|dues|balance|debt|payable)\s+(?:for|to|of)\s+supplier\s+([a-zA-Z0-9_\-\.\s]+)\b/i', $normalized, $matches)) {
            $supplierName = trim($matches[1]);
        }

        if ($supplierName !== null && $supplierName !== '') {
            $stripped = 'supplier due';

            if ($context && !$context->hasPermission('suppliers-index')) {
                $isAuthorized = false;
                $unauthorizedMessage = 'You do not have permission to view supplier details.';
            } else {
                $suppliers = Supplier::where('is_active', true)
                    ->where('name', 'like', "%{$supplierName}%")
                    ->orderBy('name', 'asc')
                    ->limit(10)
                    ->get(['id', 'name', 'phone_number']);

                if ($suppliers->count() === 1) {
                    $parameters['entity_type'] = 'supplier';
                    $parameters['entity_id'] = (int) $suppliers->first()->id;
                    $parameters['entity_name'] = (string) $suppliers->first()->name;
                } elseif ($suppliers->count() > 1) {
                    $clarificationNeeded = true;
                    $clarificationMessage = "Multiple suppliers matched '{$supplierName}'. Please specify which one:";
                    $clarificationChoices = $suppliers->take(5)->map(fn($s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'phone' => $s->phone_number,
                        'prompt' => "supplier {$s->name} balance",
                    ])->values()->all();
                } else {
                    $parameters['entity_type'] = 'supplier';
                    $parameters['entity_id'] = null;
                    $parameters['entity_name'] = $supplierName;
                    $parameters['entity_not_found'] = true;
                }
            }
        }

        // Clean up whitespace in stripped prompt
        $cleanStripped = trim(preg_replace('/\s+/', ' ', $stripped));

        // Resolve canonical skill key from stripped or original prompt
        $resolvedSkill = null;
        if ($cleanStripped !== '') {
            $resolvedSkill = LocalizedIntentMatcher::resolve($cleanStripped);
        }
        if ($resolvedSkill === null) {
            $resolvedSkill = LocalizedIntentMatcher::resolve($normalized);
        }

        return [
            'skill' => $resolvedSkill,
            'parameters' => $parameters,
            'clarification_needed' => $clarificationNeeded,
            'clarification_choices' => $clarificationChoices,
            'clarification_message' => $clarificationMessage,
            'is_authorized' => $isAuthorized,
            'unauthorized_message' => $unauthorizedMessage,
        ];
    }
}
