<?php

namespace Modules\AIAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\StructuredQuestionEngine;
use Modules\AIAssistant\Specialists\SpecialistRegistry;

class StructuredQuestionController extends Controller
{
    public function __construct(
        private StructuredQuestionEngine $questionEngine,
        private ?SpecialistRegistry $specialistRegistry = null
    ) {
        $this->specialistRegistry ??= app(SpecialistRegistry::class);
    }

    /**
     * Get available guided questions and page suggestions for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $pageContext = $request->input('page_context', []);
        if (is_string($pageContext)) {
            $decoded = json_decode($pageContext, true);
            if (is_array($decoded)) {
                $pageContext = $decoded;
            } else {
                $pageContext = [];
            }
        } elseif (!is_array($pageContext)) {
            $pageContext = [];
        }

        $context = AssistantAccessContext::fromUser($user, $pageContext);
        $page = (string) $request->input('page', 'dashboard');
        $specialist = $request->has('specialist') && $request->input('specialist') !== ''
            ? (string) $request->input('specialist')
            : null;

        $suggestions = $this->questionEngine->getSuggestionsForPage($context, $page, limit: 8);
        $grouped = $this->questionEngine->getAvailableGrouped($context);

        $questionsList = $specialist !== null
            ? $this->questionEngine->getAvailableQuestions($context, specialist: $specialist)
            : $this->questionEngine->getAvailableQuestions($context);

        $specialists = $this->specialistRegistry?->all() ?? [];

        return response()->json([
            'suggestions' => array_map(fn($q) => $q->toArray(), $suggestions),
            'grouped'     => $grouped,
            'questions'   => array_map(fn($q) => $q->toArray(), $questionsList),
            'specialists' => array_map(fn($s) => $s->toArray(), $specialists),
        ]);
    }
}
