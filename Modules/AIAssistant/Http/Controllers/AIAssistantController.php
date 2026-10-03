<?php

namespace Modules\AIAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\StructuredQuestionEngine;
use Modules\AIAssistant\Specialists\SpecialistRegistry;

class AIAssistantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $theme = env('THEME', 'default');
        if (\Schema::hasTable('general_settings')) {
            $theme = \App\Models\GeneralSetting::latest()->value('theme');
        }
        $languages = \App\Models\Language::orderBy('name')->get();

        $questionEngine = app(StructuredQuestionEngine::class);
        $specialistRegistry = app(SpecialistRegistry::class);

        $user = $request->user();
        $context = $user ? AssistantAccessContext::fromUser($user) : null;
        $specialists = $specialistRegistry ? $specialistRegistry->all() : [];
        $initialSuggestions = $context ? $questionEngine->getSuggestionsForPage($context, 'dashboard', 8) : [];
        $initialGrouped = $context ? $questionEngine->getAvailableGrouped($context) : [];

        return view('aiassistant::index', compact('theme', 'languages', 'specialists', 'initialSuggestions', 'initialGrouped'));
    }
}
