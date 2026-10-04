<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\Companion\CompanionProtectedPlugins;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WordPressPluginsController extends Controller
{
    /**
     * Fleet-wide view of WordPress security plugins and Companion agent per site.
     *
     * Displays deployment status of the Clockwork Companion mu-plugin, Wordfence,
     * and native Gatekeeper login lockout protection across all monitored WordPress sites.
     */
    public function index(): View
    {
        $sites = Site::query()
            ->with(['server.tags:id,name'])
            ->where('is_wordpress', true)
            ->hostMonitored()
            ->orderBy('domain')
            ->get();

        $companionInstalled = $sites->where('companion_installed', true)->count();
        $companionMissing = $sites->count() - $companionInstalled;

        $wordfenceEnabled = $sites->where('wordfence_enabled', true)->count();
        $gatekeeperEnabledCount = $sites->filter(fn (Site $s) => $s->gatekeeperEnabled())->count();

        $noProtection = $sites->filter(function (Site $s) {
            $hasWf = (bool) $s->wordfence_enabled;

            return ! $hasWf && ! $s->gatekeeperEnabled();
        })->count();

        $totals = [
            'sites' => $sites->count(),
            'companion_installed' => $companionInstalled,
            'companion_missing' => $companionMissing,
            'wordfence_enabled' => $wordfenceEnabled,
            'gatekeeper_enabled' => $gatekeeperEnabledCount,
            'no_protection' => $noProtection,
        ];

        $protectedPlugins = implode("\n", CompanionProtectedPlugins::configuredOperationalSlugs());

        return view('settings.wordpress-plugins', compact('sites', 'totals', 'protectedPlugins'));
    }

    public function updateProtectedPlugins(Request $request, Settings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'protected_plugins' => ['nullable', 'string', 'max:8000'],
        ]);

        $slugs = CompanionProtectedPlugins::normalizeList((string) ($validated['protected_plugins'] ?? ''));
        $settings->put(CompanionProtectedPlugins::SETTING_KEY, $slugs);

        return redirect()
            ->route('settings.wordpress-plugins.index')
            ->with('status', 'Protected plugin list saved. Connector plugins stay protected even if omitted.');
    }
}
