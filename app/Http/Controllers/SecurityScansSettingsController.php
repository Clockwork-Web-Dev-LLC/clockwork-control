<?php

namespace App\Http\Controllers;

use App\Services\Process\BackgroundArtisan;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Settings panel for the security-scan schedule + run-now buttons. Mirrors
 * IngestSettingsController shape — toggles per source, last-run-at display,
 * dispatch the same artisan command the schedule runs. Disabling a toggle
 * makes the scheduled command's `->when()` gate return false (see
 * routes/console.php), turning the next tick into a cheap no-op without
 * removing the schedule entry.
 */
class SecurityScansSettingsController extends Controller
{
    private const SOURCES = [
        'sitecheck' => [
            'label' => 'Sucuri SiteCheck',
            'description' => 'Free public scanner — same engine ManageWP shells out to. Care-plan-only.',
            'cadence' => 'Weekly Mondays 02:00 UTC',
            'command' => 'clockwork:scan-sitecheck',
        ],
        'checksums' => [
            'label' => 'Core file integrity (wp-cli)',
            'description' => "Server-side wp core verify-checksums. Catches modified core files Sucuri can't see. Care-plan-only.",
            'cadence' => 'Daily 02:30 UTC',
            'command' => 'clockwork:verify-wp-core-checksums',
        ],
        'blacklist' => [
            'label' => 'Domain blacklists',
            'description' => 'Spamhaus DBL always (no key). URLHaus + Google Safe Browsing run when their respective free API keys are set in env. Recovers the blacklist signal Sucuri loses behind a CF WAF. Hosting-tier — runs for every site.',
            'cadence' => 'Daily 02:15 UTC',
            'command' => 'clockwork:check-blacklists',
        ],
    ];

    public function index(Settings $settings): View
    {
        $sources = [];
        foreach (self::SOURCES as $key => $meta) {
            $lastRunRaw = $settings->get("security_scans.{$key}_last_run_at");
            $sources[$key] = $meta + [
                'enabled' => (bool) $settings->get("security_scans.{$key}_enabled", true),
                'last_run_at' => $lastRunRaw ? Carbon::parse($lastRunRaw) : null,
            ];
        }

        return view('settings.security-scans', compact('sources'));
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $payload = [];
        foreach (array_keys(self::SOURCES) as $key) {
            $payload["security_scans.{$key}_enabled"] = (bool) $request->input("sources.{$key}.enabled", false);
        }
        $settings->putMany($payload);

        return redirect()->route('settings.security-scans.index')
            ->with('status', 'Security scan settings saved.');
    }

    public function runNow(Request $request): RedirectResponse
    {
        $source = (string) $request->input('source', '');
        if (! isset(self::SOURCES[$source])) {
            return back()->with('queue_error', "Unknown source '{$source}'.");
        }

        $command = self::SOURCES[$source]['command'];
        $result = app(BackgroundArtisan::class)->start(
            'security_scans.'.$source,
            [$command],
            1800,
            'security-scan-'.$source.'-bg',
        );

        if ($result->alreadyRunning()) {
            return back()->with('status', "{$source} scan is already running.");
        }

        if ($result->failed()) {
            return back()->with('queue_error', $result->error ?? "Could not start the {$source} scan.");
        }

        return back()->with('status', "{$source} scan started in the background — refresh this page in a few minutes.");
    }
}
