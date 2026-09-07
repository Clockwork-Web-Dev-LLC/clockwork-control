<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\ModuleRegistry;
use Modules\Core\ModuleStateResolver;

class ModuleSettingsController extends Controller
{
    public function index(ModuleRegistry $registry, ModuleStateResolver $resolver): View
    {
        $modules = $registry->all();

        // Build a map of enabled status for each module
        $moduleStatus = [];
        foreach ($modules as $module) {
            $moduleStatus[$module->id()] = $resolver->isEnabled($module->id());
        }

        return view('settings.modules', compact('modules', 'moduleStatus'));
    }

    public function toggle(Request $request, ModuleStateResolver $resolver): RedirectResponse
    {
        $validated = $request->validate([
            'module_id' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        // Store module state (this would update a config or database)
        // For now, we'll just update a config cache
        $enabledModules = config('modules.enabled', []);

        if ($validated['enabled']) {
            $enabledModules[] = $validated['module_id'];
        } else {
            $enabledModules = array_filter($enabledModules, fn($id) => $id !== $validated['module_id']);
        }

        // In a real implementation, this would persist to config/modules.php or a database
        cache()->put('modules.enabled', array_unique($enabledModules), 86400);

        return back()->with('status', "Module '{$validated['module_id']}' " . ($validated['enabled'] ? 'enabled' : 'disabled') . '.');
    }
}
