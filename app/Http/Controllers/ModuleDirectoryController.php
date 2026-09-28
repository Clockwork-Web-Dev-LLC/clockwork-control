<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleCatalog;
use Modules\Core\ModuleDirectoryClient;
use Modules\Core\ModuleStateResolver;

class ModuleDirectoryController extends Controller
{
    /**
     * Display the official and community module directory.
     */
    public function index(
        Request $request,
        ModuleDirectoryClient $client,
        ModuleStateResolver $resolver,
    ): View {
        $feed = $client->fetch();
        $bundled = ModuleCatalog::bundled();
        $installed = InstalledModule::all()->keyBy('module_id');

        $seenIds = [];
        $modules = [];

        foreach ($feed['modules'] as $mod) {
            $id = (string) ($mod['id'] ?? '');
            $canonId = str_replace('_', '-', $id);
            $seenIds[$id] = true;
            $seenIds[$canonId] = true;

            $matchedBundled = $bundled[$id] ?? $bundled[$canonId] ?? null;
            $isBundled = $matchedBundled !== null;
            $moduleKey = $matchedBundled ? $matchedBundled['manifest']->id : $id;

            $isInstalled = $isBundled || $installed->has($id) || $installed->has($canonId);
            $isEnabled = $isInstalled
                ? ($installed->has($moduleKey)
                    ? (bool) $installed->get($moduleKey)->enabled
                    : ($installed->has($id)
                        ? (bool) $installed->get($id)->enabled
                        : $resolver->isEnabled($moduleKey)))
                : false;

            $modules[] = [
                ...$mod,
                'id' => $moduleKey,
                'is_bundled' => $isBundled,
                'is_installed' => $isInstalled,
                'is_enabled' => $isEnabled,
            ];
        }

        // Include any bundled modules present in codebase but not yet indexed in remote feed
        foreach ($bundled as $id => $item) {
            $canonId = str_replace('_', '-', $id);
            if (! isset($seenIds[$id]) && ! isset($seenIds[$canonId])) {
                $manifest = $item['manifest'];
                $isEnabled = $installed->has($manifest->id)
                    ? (bool) $installed->get($manifest->id)->enabled
                    : $resolver->isEnabled($manifest->id);

                $modules[] = [
                    'id' => $manifest->id,
                    'name' => $manifest->name,
                    'description' => $manifest->description,
                    'category' => $item['category'],
                    'author' => 'Clockwork Web Dev',
                    'author_url' => 'https://clockworkwd.com',
                    'status' => $manifest->status,
                    'status_note' => $manifest->statusNote,
                    'repository' => 'https://github.com/Clockwork-Web-Dev-LLC/clockwork-control',
                    'package_name' => "clockwork/module-{$manifest->id}",
                    'composer_type' => 'clockworkcontrol-module',
                    'icon' => $manifest->id,
                    'tags' => [$item['category'], $manifest->id],
                    'min_version' => '1.0.0',
                    'is_bundled' => true,
                    'is_installed' => true,
                    'is_enabled' => $isEnabled,
                    'capabilities' => [],
                    'credential_fields' => array_map(
                        fn ($meta, $key) => [
                            'key' => (string) $key,
                            'label' => (string) ($meta['label'] ?? $key),
                            'secret' => (bool) ($meta['secret'] ?? true),
                        ],
                        $manifest->credentialFields,
                        array_keys($manifest->credentialFields)
                    ),
                ];
                $seenIds[$id] = true;
                $seenIds[$canonId] = true;
            }
        }

        $categories = [
            'all' => 'All Modules',
            'authentication' => 'Authentication',
            'cloud_vps' => 'Cloud Infrastructure',
            'managed_hosts' => 'Managed WordPress',
            'control_panels' => 'Control Panels',
            'notifications' => 'Notifications',
            'performance' => 'Performance',
            'security' => 'Security (ManageWP)',
            'maintenance' => 'Maintenance & QA',
            'billing' => 'Billing',
        ];

        return view('settings.modules.index', [
            'modules' => $modules,
            'categories' => $categories,
            'generatedAt' => $feed['generated_at'] ?? null,
            'feedSource' => $feed['source'] ?? 'network',
            'totalModules' => count($modules),
        ]);
    }

    /**
     * Immediately toggle a module ON or OFF and persist to installed_modules.
     */
    public function toggle(Request $request, ModuleStateResolver $resolver): JsonResponse|RedirectResponse
    {
        $moduleId = (string) ($request->input('module') ?: $request->input('service'));
        $enabled = $request->boolean('enabled');

        $bundled = ModuleCatalog::bundled();
        $canonId = str_replace('_', '-', $moduleId);

        $matchedItem = $bundled[$moduleId] ?? $bundled[$canonId] ?? null;
        if (! $matchedItem) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => "Unknown module: {$moduleId}",
                ], 404);
            }

            return back()->with('error', "Unknown module: {$moduleId}");
        }

        $manifest = $matchedItem['manifest'];
        $canonicalId = $manifest->id;

        InstalledModule::updateOrCreate(
            ['module_id' => $canonicalId],
            [
                'name' => $manifest->name,
                'source' => 'bundled',
                'enabled' => $enabled,
                'status' => 'active',
            ]
        );

        $resolver->flush();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'module' => $canonicalId,
                'enabled' => $enabled,
                'message' => "{$manifest->name} is now ".($enabled ? 'active' : 'disabled').'.',
            ]);
        }

        return back()->with('status', "{$manifest->name} is now ".($enabled ? 'active' : 'disabled').'.');
    }

    /**
     * Force refresh the module directory feed from clockworkcontrol.com.
     */
    public function refresh(ModuleDirectoryClient $client): RedirectResponse
    {
        $client->fetch(forceRefresh: true);

        return redirect()
            ->route('settings.modules.index')
            ->with('status', 'Module directory refreshed successfully from clockworkcontrol.com.');
    }
}
