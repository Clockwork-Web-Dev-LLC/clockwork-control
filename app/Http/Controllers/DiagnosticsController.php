<?php

namespace App\Http\Controllers;

use App\Services\Diagnostics\Checks\CloudflareCheck;
use App\Services\Diagnostics\Checks\DatabaseCheck;
use App\Services\Diagnostics\Checks\DigitalOceanSpacesCheck;
use App\Services\Diagnostics\Checks\GoogleOAuthCheck;
use App\Services\Diagnostics\Checks\GoogleSafeBrowsingCheck;
use App\Services\Diagnostics\Checks\MailerCheck;
use App\Services\Diagnostics\Checks\OutboundHttpCheck;
use App\Services\Diagnostics\Checks\StorageWritableCheck;
use App\Services\Diagnostics\Checks\SucuriCheck;
use App\Services\Diagnostics\Checks\UnregisteredCloudProviderCheck;
use App\Services\Diagnostics\Checks\WpVulnerabilityCheck;
use App\Services\Diagnostics\DiagnosticsRunner;
use Illuminate\View\View;
use Modules\Core\ModuleRegistry;

/**
 * "Are my integrations actually wired up right?" page. Runs a
 * connectivity-only check per external dependency: probes auth, hits
 * read-only endpoints, opens TCP sockets. Never sends test mail, never
 * posts test webhooks — those would be separate explicit actions.
 *
 * Per-site Companion checks aren't here on purpose. Those belong on the
 * per-site page (already there) — fanning out 27 sites of WP probes here
 * would drown the global signal that this page exists for.
 */
class DiagnosticsController extends Controller
{
    /**
     * Order is the visual order. Control checks first (database, outbound,
     * storage) so a failure there explains failures that follow. Then the
     * specific integrations grouped by domain.
     *
     * Container-resolved (not bare `new`) so the checks reading credentials
     * via CredentialResolver (Cloudflare/Twilio/GoogleSafeBrowsing) get it
     * injected automatically — harmless no-op for the rest, which have no
     * constructor dependencies.
     *
     * DigitalOcean/Hetzner/Azure/Pressable/SpinupWP/BillCom/Mattermost/Slack's
     * checks are no longer listed here — they're contributed by their
     * respective modules and merged in via ModuleRegistry::diagnosticChecks().
     * Core checks stay hardcoded; only the module-owned ones moved.
     */
    private function checks(): array
    {
        $raw = [
            ...[
                app(DatabaseCheck::class),
                app(OutboundHttpCheck::class),
                app(StorageWritableCheck::class),
                app(MailerCheck::class),
                app(GoogleOAuthCheck::class),
            ],
            ...app(ModuleRegistry::class)->diagnosticChecks(),
            ...[
                app(DigitalOceanSpacesCheck::class),
                app(UnregisteredCloudProviderCheck::class),
                app(CloudflareCheck::class),

                app(SucuriCheck::class),
                app(WpVulnerabilityCheck::class),
                app(GoogleSafeBrowsingCheck::class),
            ],
        ];

        $unique = [];
        foreach ($raw as $check) {
            $unique[$check->id()] ??= $check;
        }

        return array_values($unique);
    }

    public function index(): View
    {
        $rows = (new DiagnosticsRunner($this->checks()))->run();

        $counts = [
            'ok' => 0,
            'fail' => 0,
            'skipped' => 0,
        ];
        foreach ($rows as $row) {
            $counts[$row['result']->status]++;
        }

        return view('settings.diagnostics', [
            'rows' => $rows,
            'counts' => $counts,
            'ranAt' => now(),
        ]);
    }
}
