<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\View\View;
use Modules\Core\ModuleStateResolver;

class WordPressPluginsController extends Controller
{
    /**
     * Fleet-wide view of WordPress security plugins and Companion agent per site.
     *
     * Displays deployment status of the Clockwork Companion mu-plugin and security
     * plugins across all monitored WordPress sites. If the Limit Login Attempts
     * Reloaded (LLAR) module is enabled, LLAR status and install options are shown.
     */
    public function index(ModuleStateResolver $moduleState): View
    {
        $llarEnabled = $moduleState->isEnabled('llar');

        $sites = Site::query()
            ->with(['server.tags:id,name'])
            ->where('is_wordpress', true)
            ->hostMonitored()
            ->orderBy('domain')
            ->get();

        $companionInstalled = $sites->where('companion_installed', true)->count();
        $companionMissing = $sites->count() - $companionInstalled;

        $wordfenceEnabled = $sites->where('wordfence_enabled', true)->count();
        $llarEnabledCount = $llarEnabled ? $sites->where('llar_enabled', true)->count() : 0;
        $llarMissingCount = $llarEnabled ? ($sites->count() - $llarEnabledCount) : 0;

        $noProtection = $sites->filter(function ($s) use ($llarEnabled) {
            $hasWf = (bool) $s->wordfence_enabled;
            $hasLlar = $llarEnabled && (bool) $s->llar_enabled;

            return ! $hasWf && ! $hasLlar;
        })->count();

        $totals = [
            'sites' => $sites->count(),
            'companion_installed' => $companionInstalled,
            'companion_missing' => $companionMissing,
            'wordfence_enabled' => $wordfenceEnabled,
            'llar_enabled' => $llarEnabledCount,
            'llar_missing' => $llarMissingCount,
            'no_protection' => $noProtection,
        ];

        return view('settings.wordpress-plugins', compact('sites', 'totals', 'llarEnabled'));
    }
}
