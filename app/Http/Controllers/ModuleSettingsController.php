<?php

namespace App\Http\Controllers;

use App\Services\ModuleAccessService;
use App\Services\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModuleSettingsController extends Controller
{
    public function index(ModuleAccessService $access)
    {
        abort_unless(auth()->check() && (int) auth()->user()->role_id <= 2, 403);
        $modules = collect(ModuleRegistry::all())->filter(fn ($definition, $slug) =>
            $access->isInstalled($slug) && $access->isCodeEnabled($slug) && $access->tenantCanAccess($slug)
        );
        $enabled = $modules->keys()->filter(fn ($slug) => $access->businessEnabled($slug))->values()->all();
        return view('backend.setting.modules', compact('modules', 'enabled'));
    }

    public function update(Request $request, ModuleAccessService $access)
    {
        abort_unless(auth()->check() && (int) auth()->user()->role_id <= 2, 403);
        if (!config('app.user_verified')) {
            return back()->with('not_permitted', __('db.This feature is disable for demo!'));
        }
        $available = $access->availableModules();
        $enabled = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in($available)],
        ])['modules'] ?? [];
        $access->setBusinessEnabledModules($enabled);
        return back()->with('message', 'Module settings updated successfully.');
    }
}
