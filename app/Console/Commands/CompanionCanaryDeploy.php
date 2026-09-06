<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Companion\CompanionInstaller;
use App\Support\Settings;
use Illuminate\Console\Command;
use Modules\Pressable\PressableClient;

/**
 * Deploy the current local Companion plugin tarball to the canary site set
 * defined by `companion.canary_site_ids`. Two phases:
 *
 *   1. Install — runs CompanionInstaller against each canary site (same code
 *      path as the existing single-site `clockwork:install-companion` command,
 *      so we reuse the proven install machinery rather than reimplementing).
 *   2. Verify — for each canary site, calls /detect over HMAC and confirms
 *      the new capability ('malware-scan' for v1.15.0) shows up. Then runs
 *      one malware scan against each and reports the transport used (should
 *      be 'companion', not 'ssh', once the new endpoint is live).
 *
 * The verify step's expected capability is read from --expect-capability so
 * future plugin releases don't require code changes — defaults to malware-scan
 * for the 1.15.0 rollout this command was written for.
 *
 * Refuses to do anything if the canary set is empty — companion-canary-set
 * has to be run first.
 */
class CompanionCanaryDeploy extends Command
{
    protected $signature = 'clockwork:companion-canary-deploy
        {--expect-capability=malware-scan : Capability that must appear in /detect after install for the canary to count as verified}
        {--rotate-secret : Generate a fresh per-site secret as part of install}
        {--skip-install : Only run the verify pass (use after a manual install)}';

    protected $description = 'Install the current Companion plugin to canary sites and verify the new endpoint is reachable.';

    public function handle(
        PressableClient $pressable,
        Settings $settings,
        ActionLogger $logger,
    ): int {
        $ids = (array) ($settings->get('companion.canary_site_ids', []));
        if ($ids === []) {
            $this->error('Canary set is empty. Run clockwork:companion-canary-set <domains> first.');

            return self::FAILURE;
        }

        $sites = Site::query()->whereIn('id', $ids)->orderBy('domain')->get();
        $expectCapability = (string) $this->option('expect-capability');

        $installResults = [];

        if (! $this->option('skip-install')) {
            $this->info("Installing Companion on {$sites->count()} canary site(s)…");

            foreach ($sites as $site) {
                $installer = $site->host()->companionInstaller();
                if ($installer === null) {
                    // Nullable by contract — a hosting provider's client in
                    // View-Only mode returns no installer at all, since
                    // installing Companion is a write operation. Skip rather
                    // than crash the whole canary run over one site.
                    $this->line(sprintf('  %s %-40s %s', '✗', $site->domain, 'Companion install unavailable (provider is in View-Only mode)'));

                    continue;
                }

                $result = $installer->installOrUpdate($site, (bool) $this->option('rotate-secret'));
                $installResults[$site->id] = $result;
                $logger->recordCompanionInstall($site, $result);
                $tag = match ($result['result']) {
                    CompanionInstaller::RESULT_INSTALLED,
                    CompanionInstaller::RESULT_UPDATED,
                    CompanionInstaller::RESULT_ALREADY_CURRENT => '✓',
                    default => '✗',
                };
                $this->line(sprintf('  %s %-40s %s', $tag, $site->domain, $result['message'] ?? $result['result']));
            }
        }

        $this->info("\nVerifying canary sites for capability: {$expectCapability}");

        $okCount = 0;
        $failCount = 0;
        foreach ($sites as $site) {
            $site->refresh();

            // Pressable's edge cache can serve a stale cached GET response
            // indefinitely once a URL is hit — confirmed live 2026-08-29 on
            // /snapshot showing a pre-update plugin version well after the
            // real upgrade succeeded. /detect right after a fresh install is
            // exactly the same risk (a pre-install 404/old-capability
            // response cached before this run). Purge first so verification
            // reflects what's actually on the site, not what was cached
            // before we touched it. Best-effort — SpinupWP has no edge
            // cache at all, and a purge failure shouldn't block verification.
            if ($site->isPressable() && $site->pressable_site_id !== null) {
                try {
                    $pressable->purgeEdgeCache($site->pressable_site_id);
                } catch (\Throwable) {
                    // non-fatal
                }
            }

            $caps = is_array($site->companion_capabilities ?? null) ? $site->companion_capabilities : [];
            $hasCap = in_array($expectCapability, $caps, true);

            if (! $hasCap) {
                // Try a live /detect to refresh capabilities — the install path
                // pulls them as part of the post-install hook, but skip-install
                // mode (or a stale capabilities cache) needs an explicit nudge.
                try {
                    $client = new ClockworkCompanionClient($site);
                    $detect = $client->detect();
                    $caps = is_array($detect['capabilities'] ?? null) ? $detect['capabilities'] : [];
                    $hasCap = in_array($expectCapability, $caps, true);
                    if ($hasCap) {
                        $site->update(['companion_capabilities' => $caps]);
                    }
                } catch (\Throwable $e) {
                    $this->line(sprintf('  ✗ %-40s /detect failed: %s', $site->domain, mb_substr($e->getMessage(), 0, 80)));
                    $failCount++;

                    continue;
                }
            }

            if (! $hasCap) {
                $this->line(sprintf('  ✗ %-40s capability missing (caps=%s)', $site->domain, implode(',', $caps) ?: 'none'));
                $failCount++;

                continue;
            }

            // Capability is there — run an actual malware scan to prove the
            // route works end-to-end (HMAC + handler + scanner + return shape).
            try {
                $client = new ClockworkCompanionClient($site);
                $scan = $client->malwareScan();
                if (! ($scan['ok'] ?? false)) {
                    $this->line(sprintf('  ✗ %-40s malware-scan returned not-ok: %s', $site->domain, $scan['error'] ?? 'unknown'));
                    $failCount++;

                    continue;
                }
                $findings = count($scan['findings'] ?? []);
                $files = (int) ($scan['scanned_files_count'] ?? 0);
                $this->line(sprintf('  ✓ %-40s malware-scan ok (%d files, %d findings)', $site->domain, $files, $findings));
                $okCount++;
            } catch (\Throwable $e) {
                $this->line(sprintf('  ✗ %-40s malware-scan threw: %s', $site->domain, mb_substr($e->getMessage(), 0, 80)));
                $failCount++;
            }
        }

        $this->info("\nCanary verify: ok={$okCount} fail={$failCount}");

        if ($failCount === 0) {
            $settings->put('companion.canary_verified_at', now()->toIso8601String());
            $settings->put('companion.canary_verified_capability', $expectCapability);
            $this->info('Canary verified — companion-fleet-deploy is now allowed for capability '.$expectCapability.'.');

            return self::SUCCESS;
        }

        // Make sure a partial / failed verify can't fool the fleet gate.
        $settings->put('companion.canary_verified_at', null);

        return self::FAILURE;
    }
}
