<?php

namespace App\Http\Controllers;

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

        $modules = array_map(function (array $mod) use ($bundled, $installed, $resolver) {
            $id = (string) ($mod['id'] ?? '');
            $isBundled = isset($bundled[$id]);
            $isInstalled = $isBundled || $installed->has($id);
            $isEnabled = $isInstalled ? $resolver->isEnabled($id) : false;

            return [
                ...$mod,
                'is_bundled' => $isBundled,
                'is_installed' => $isInstalled,
                'is_enabled' => $isEnabled,
            ];
        }, $feed['modules']);

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
