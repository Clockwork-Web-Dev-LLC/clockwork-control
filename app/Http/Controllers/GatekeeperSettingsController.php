<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\Gatekeeper\GatekeeperSettingsPusher;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class GatekeeperSettingsController extends Controller
{
    public function index(Settings $settings): View
    {
        $config = [
            'enabled' => (bool) $settings->get('gatekeeper.enabled', GatekeeperSettingsPusher::DEFAULT_ENABLED),
            'threshold' => (int) $settings->get('gatekeeper.threshold', GatekeeperSettingsPusher::DEFAULT_THRESHOLD),
            'window_seconds' => (int) $settings->get('gatekeeper.window_seconds', GatekeeperSettingsPusher::DEFAULT_WINDOW_SECONDS),
            'lockout_seconds' => (int) $settings->get('gatekeeper.lockout_seconds', GatekeeperSettingsPusher::DEFAULT_LOCKOUT_SECONDS),
            'consecutive_lockouts_for_extended' => (int) $settings->get('gatekeeper.consecutive_lockouts_for_extended', GatekeeperSettingsPusher::DEFAULT_CONSECUTIVE_FOR_EXTENDED),
            'extended_lockout_seconds' => (int) $settings->get('gatekeeper.extended_lockout_seconds', GatekeeperSettingsPusher::DEFAULT_EXTENDED_LOCKOUT_SECONDS),
            'headline' => (string) $settings->get('gatekeeper.headline', GatekeeperSettingsPusher::DEFAULT_HEADLINE),
            'body' => (string) $settings->get('gatekeeper.body', GatekeeperSettingsPusher::DEFAULT_BODY),
            'support_label' => (string) $settings->get('gatekeeper.support_label', GatekeeperSettingsPusher::DEFAULT_SUPPORT_LABEL),
            'support_email' => (string) $settings->get('gatekeeper.support_email', GatekeeperSettingsPusher::DEFAULT_SUPPORT_EMAIL),
            'support_url' => (string) $settings->get('gatekeeper.support_url', GatekeeperSettingsPusher::DEFAULT_SUPPORT_URL),
            'show_ip' => (bool) $settings->get('gatekeeper.show_ip', GatekeeperSettingsPusher::DEFAULT_SHOW_IP),
            'show_unlock_link' => (bool) $settings->get('gatekeeper.show_unlock_link', GatekeeperSettingsPusher::DEFAULT_SHOW_UNLOCK_LINK),
            'unlock_url' => (string) $settings->get('gatekeeper.unlock_url', GatekeeperSettingsPusher::DEFAULT_UNLOCK_URL),
            'ignore_ips' => implode("\n", (array) $settings->get('gatekeeper.ignore_ips', [])),
            'ignore_cidrs' => implode("\n", (array) $settings->get('gatekeeper.ignore_cidrs', [])),
        ];

        $totalCompanionSites = Site::query()
            ->where('companion_installed', true)
            ->where('is_inactive', false)
            ->count();

        $gatekeeperCapableSites = Site::query()
            ->where('companion_installed', true)
            ->where('is_inactive', false)
            ->whereJsonContains('companion_capabilities', 'gatekeeper')
            ->count();

        $sitesWithOverrides = Site::query()
            ->whereNotNull('gatekeeper_settings')
            ->count();

        return view('settings.gatekeeper', [
            'config' => $config,
            'totalCompanionSites' => $totalCompanionSites,
            'gatekeeperCapableSites' => $gatekeeperCapableSites,
            'sitesWithOverrides' => $sitesWithOverrides,
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'threshold' => ['required', 'integer', 'min:3', 'max:20'],
            'window_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'lockout_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'consecutive_lockouts_for_extended' => ['required', 'integer', 'min:1', 'max:20'],
            'extended_lockout_seconds' => ['required', 'integer', 'min:60', 'max:604800'],
            'headline' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:1000'],
            'support_label' => ['nullable', 'string', 'max:100'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_url' => ['nullable', 'url', 'max:255'],
            'show_ip' => ['nullable', 'boolean'],
            'show_unlock_link' => ['nullable', 'boolean'],
            'unlock_url' => ['nullable', 'url', 'max:255'],
            'ignore_ips' => ['nullable', 'string'],
            'ignore_cidrs' => ['nullable', 'string'],
        ]);

        $settings->put('gatekeeper.enabled', (bool) ($validated['enabled'] ?? false));
        $settings->put('gatekeeper.threshold', (int) $validated['threshold']);
        $settings->put('gatekeeper.window_seconds', (int) $validated['window_seconds']);
        $settings->put('gatekeeper.lockout_seconds', (int) $validated['lockout_seconds']);
        $settings->put('gatekeeper.consecutive_lockouts_for_extended', (int) $validated['consecutive_lockouts_for_extended']);
        $settings->put('gatekeeper.extended_lockout_seconds', (int) $validated['extended_lockout_seconds']);
        $settings->put('gatekeeper.headline', (string) ($validated['headline'] ?? GatekeeperSettingsPusher::DEFAULT_HEADLINE));
        $settings->put('gatekeeper.body', (string) ($validated['body'] ?? GatekeeperSettingsPusher::DEFAULT_BODY));
        $settings->put('gatekeeper.support_label', (string) ($validated['support_label'] ?? GatekeeperSettingsPusher::DEFAULT_SUPPORT_LABEL));
        $settings->put('gatekeeper.support_email', (string) ($validated['support_email'] ?? ''));
        $settings->put('gatekeeper.support_url', (string) ($validated['support_url'] ?? ''));
        $settings->put('gatekeeper.show_ip', (bool) ($validated['show_ip'] ?? false));
        $settings->put('gatekeeper.show_unlock_link', (bool) ($validated['show_unlock_link'] ?? false));
        $settings->put('gatekeeper.unlock_url', (string) ($validated['unlock_url'] ?? ''));

        $ignoreIps = array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', (string) ($validated['ignore_ips'] ?? '')) ?: []),
            fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP)
        ));
        $settings->put('gatekeeper.ignore_ips', $ignoreIps);

        $ignoreCidrs = array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', (string) ($validated['ignore_cidrs'] ?? '')) ?: []),
            fn ($cidr) => str_contains($cidr, '/')
        ));
        $settings->put('gatekeeper.ignore_cidrs', $ignoreCidrs);

        return redirect()->route('settings.gatekeeper.index')
            ->with('status', 'Gatekeeper fleet policy settings updated successfully.');
    }

    public function syncNow(): RedirectResponse
    {
        Artisan::call('clockwork:push-gatekeeper-settings');
        $output = trim(Artisan::output());

        return redirect()->route('settings.gatekeeper.index')
            ->with('status', 'Gatekeeper settings push completed: '.$output);
    }
}
