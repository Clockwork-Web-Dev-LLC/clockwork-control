<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Companion\CompanionInstaller;
use App\Services\Gatekeeper\GatekeeperSettingsPusher;
use App\Support\CompanionExclusion;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Modules\Core\ModuleStateResolver;
use Throwable;

/**
 * Move a site off Limit Login Attempts Reloaded and onto Gatekeeper.
 *
 * Two kinds of site need this:
 *
 *   - No Companion yet (the original pre-launch case). Install it first.
 *   - Companion already there but still running LLAR — the bulk of the
 *     fleet after the 1.39 upgrade. Nothing else ever flips these; the
 *     nightly push honours the fleet default (off) and per-site overrides,
 *     and no site had an override until a human set one.
 *
 * Per site, in order — each step gates the next so LLAR is never removed
 * from a site that doesn't have Gatekeeper actually enforcing:
 *
 *   1. Install / upgrade Companion when it's missing or too old to
 *      advertise `gatekeeper`. Re-read /health so the capability list is
 *      current before we make any decisions on it.
 *
 *   2. If LLAR was active here, persist `gatekeeper_settings.enabled=true`
 *      as a per-site override *and then* push. Persisting matters: the
 *      nightly `clockwork:push-gatekeeper-settings` rebuilds the payload
 *      from fleet default + overrides, so a push without the override
 *      would be undone at 06:40 the next morning. The push also trips
 *      Companion's maybeDeactivateLegacyLlar() on the WordPress side.
 *
 *      Sites that never had LLAR get Companion but Gatekeeper stays off —
 *      enabling it there would introduce lockout behaviour the client
 *      never had.
 *
 *   3. Deactivate + delete LLAR over Companion REST. The protected-plugins
 *      guard exempts LLAR once Site::gatekeeperEnabled() is true, which is
 *      exactly the state step 2 leaves us in. Network-wide deactivation is
 *      tried first (multisite), then single-site.
 *
 *   4. Mark llar_enabled=false and record an action-log entry.
 *
 * All failures are soft — one site failing never aborts the rest.
 *
 * Options:
 *   --scope=all|no-companion|llar   Which population to consider (default all)
 *   --limit=N                       Max sites per run (default 20; 0 = all)
 *   --dry-run                       Preview candidates only
 *   --force                         Skip the confirmation prompt (cron / CI)
 *   --throttle-ms=N                 Sleep between sites (default 2000)
 *   --skip=a,b                      Comma-separated domains to skip
 */
class GatekeeperRollout extends Command
{
    protected const LLAR_SLUG = 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php';

    protected const SCOPES = ['all', 'no-companion', 'llar'];

    protected $signature = 'clockwork:gatekeeper-rollout
        {--site= : Limit to a specific domain or ID}
        {--scope=all : all | no-companion (sites with no Companion) | llar (Companion sites still on LLAR)}
        {--limit=20 : Max sites to process per run (0 = all eligible)}
        {--dry-run : Show candidates without making any changes}
        {--force : Skip the confirmation prompt}
        {--throttle-ms=2000 : Milliseconds to sleep between sites}
        {--skip= : Comma-separated domains to skip}';

    protected $description = 'Migrate sites from Limit Login Attempts Reloaded to Gatekeeper: install/upgrade Companion, enable Gatekeeper per-site, remove LLAR.';

    public function handle(
        GatekeeperSettingsPusher $pusher,
        CompanionExclusion $exclusion,
        ActionLogger $logger,
    ): int {
        if (! app(ModuleStateResolver::class)->isEnabled('gatekeeper')) {
            $this->error('The Gatekeeper module is disabled in the Module Directory.');

            return self::FAILURE;
        }

        $scope = (string) $this->option('scope');
        if (! in_array($scope, self::SCOPES, true)) {
            $this->error('--scope must be one of: '.implode(', ', self::SCOPES));

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $throttleMs = max(0, (int) $this->option('throttle-ms'));
        $skipDomains = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('skip')))));

        $sites = $this->candidateSites($scope, $exclusion, $skipDomains, $limit);

        if ($sites->isEmpty()) {
            $this->info('No eligible sites found for scope "'.$scope.'".');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s%d site(s) queued for Gatekeeper rollout (scope: %s):',
            $dryRun ? '[DRY RUN] ' : '',
            $sites->count(),
            $scope,
        ));

        foreach ($sites as $site) {
            $this->line('  · '.$this->describe($site));
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn('--dry-run: no changes made.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Proceed with rollout on {$sites->count()} site(s)?", false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $stats = [
            'installed' => 0,
            'already_current' => 0,
            'install_failed' => 0,
            'gatekeeper_enabled' => 0,
            'gatekeeper_push_failed' => 0,
            'llar_deactivated' => 0,
            'llar_deleted' => 0,
            'llar_removal_skipped' => 0,
            'no_llar' => 0,
            'skipped' => 0,
        ];

        foreach ($sites->values() as $i => $site) {
            if ($i > 0 && $throttleMs > 0) {
                usleep($throttleMs * 1000);
            }

            $this->newLine();
            $this->line("━━━ [{$site->domain}] ━━━");

            // ── Step 1: Companion present and Gatekeeper-capable ────────────
            if (! $this->ensureCompanion($site, $logger, $stats)) {
                continue;
            }

            $site = $site->fresh();

            if (! $site->llar_enabled) {
                $this->line('  · Site never had LLAR — Gatekeeper left at fleet default, nothing to remove');
                $stats['no_llar']++;

                continue;
            }

            // ── Step 2: Enable Gatekeeper (persist override, then push) ─────
            if (! $this->enableGatekeeper($site, $pusher, $stats)) {
                $this->warn('  ⚠ Leaving LLAR in place — Gatekeeper is not confirmed enforcing on this site');
                $stats['llar_removal_skipped']++;

                continue;
            }

            $site = $site->fresh();

            // ── Step 3: Remove LLAR ─────────────────────────────────────────
            if (! $site->gatekeeperEnabled()) {
                // Belt and braces: the guard below would refuse anyway, but
                // say why instead of surfacing a RuntimeException.
                $this->warn('  ⚠ gatekeeperEnabled() is false after push — refusing to remove LLAR');
                $stats['llar_removal_skipped']++;

                continue;
            }

            $deactivated = $this->deactivateLlar($site, $stats);
            if (! $deactivated) {
                $stats['llar_removal_skipped']++;

                continue;
            }

            $deleted = $this->deleteLlar($site, $stats);

            // ── Step 4: Bookkeeping ─────────────────────────────────────────
            $site->llar_enabled = false;
            $site->save();
            $this->line('  · llar_enabled → false');

            $logger->record(
                actionType: ActionLog::TYPE_LLAR_RETIRED,
                summary: "Retired Limit Login Attempts Reloaded on {$site->domain} — Gatekeeper is now the login lockout layer.",
                site: $site,
                target: self::LLAR_SLUG,
                details: ['deactivated' => true, 'deleted' => $deleted],
                actor: 'clockwork:gatekeeper-rollout',
            );
        }

        $this->newLine();
        $this->info('Rollout complete: '.json_encode($stats));

        return ($stats['install_failed'] > 0) ? self::FAILURE : self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $skipDomains
     * @return Collection<int, Site>
     */
    private function candidateSites(string $scope, CompanionExclusion $exclusion, array $skipDomains, int $limit): Collection
    {
        $query = Site::query()
            ->where('is_wordpress', true)
            ->where('is_inactive', false)
            ->whereNull('consolidated_into_site_id')
            ->where('domain', 'not like', '%.mystagingwebsite.com')
            ->where('domain', 'not like', 'staging.%')
            ->where('domain', 'not like', '%.staging.%')
            ->orderBy('domain');

        $noCompanion = fn ($q) => $q->where(function ($q) {
            $q->where('companion_installed', false)->orWhereNull('companion_installed');
        });

        $companionOnLlar = fn ($q) => $q->where('companion_installed', true)->where('llar_enabled', true);

        match ($scope) {
            'no-companion' => $noCompanion($query),
            'llar' => $companionOnLlar($query),
            default => $query->where(function ($q) use ($noCompanion, $companionOnLlar) {
                $noCompanion($q)->orWhere(fn ($q2) => $companionOnLlar($q2));
            }),
        };

        if ($siteFilter = $this->option('site')) {
            $query->where(function ($q) use ($siteFilter) {
                $q->where('id', is_numeric($siteFilter) ? (int) $siteFilter : 0)
                    ->orWhere('domain', $siteFilter);
            });
        }

        if ($skipDomains !== []) {
            $query->whereNotIn('domain', $skipDomains);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        [$sites] = $exclusion->partition($query->get());

        return $sites;
    }

    private function describe(Site $site): string
    {
        $provider = ucfirst((string) $site->hosting_provider);
        $bits = [];
        if (! $site->companion_installed) {
            $bits[] = 'no Companion';
        } elseif (! $this->advertisesGatekeeper($site)) {
            $bits[] = 'Companion '.($site->companion_version ?: '?').' (no gatekeeper cap)';
        }
        if ($site->llar_enabled) {
            $bits[] = 'LLAR';
        }

        return "{$site->domain} ({$provider})".($bits ? ' ['.implode(', ', $bits).']' : '');
    }

    private function advertisesGatekeeper(Site $site): bool
    {
        $caps = is_array($site->companion_capabilities) ? $site->companion_capabilities : [];

        return in_array('gatekeeper', $caps, true);
    }

    /**
     * Install or upgrade Companion when it's missing or can't do Gatekeeper,
     * then refresh capabilities from /health. Returns false when the site
     * can't be brought to a Gatekeeper-capable state.
     *
     * @param  array<string, int>  $stats
     */
    private function ensureCompanion(Site $site, ActionLogger $logger, array &$stats): bool
    {
        if ($site->companion_installed && $this->advertisesGatekeeper($site)) {
            $this->line("  ↺ Companion {$site->companion_version} already advertises gatekeeper");
            $stats['already_current']++;

            return true;
        }

        $installer = $site->host()->companionInstaller();

        if ($installer === null) {
            $this->warn('  ⊘ No Companion installer for this provider — skipping.');
            $stats['skipped']++;

            return false;
        }

        try {
            $result = $installer->installOrUpdate($site);
            $logger->recordCompanionInstall($site, $result);
        } catch (Throwable $e) {
            $this->error("  ✗ Install threw: {$e->getMessage()}");
            $stats['install_failed']++;

            return false;
        }

        if (($result['result'] ?? '') === CompanionInstaller::RESULT_FAILED) {
            $this->error('  ✗ Install failed: '.($result['message'] ?? ''));
            $stats['install_failed']++;

            return false;
        }

        if (($result['result'] ?? '') === CompanionInstaller::RESULT_ALREADY_CURRENT) {
            $this->line('  ↺ Companion already current ('.($result['message'] ?? '').')');
            $stats['already_current']++;
        } else {
            $this->info('  ✓ '.($result['message'] ?? 'Companion installed'));
            $stats['installed']++;
        }

        // The installer records what it knows at install time; re-read
        // /health so the capability check below sees the live plugin.
        $this->refreshCapabilities($site);

        if (! $this->advertisesGatekeeper($site->fresh())) {
            $this->warn('  ⚠ Companion still does not advertise gatekeeper after install — skipping.');
            $stats['skipped']++;

            return false;
        }

        return true;
    }

    private function refreshCapabilities(Site $site): void
    {
        $site = $site->fresh();
        if (! $site->companion_installed || ! $site->companion_secret) {
            return;
        }

        try {
            $health = (new ClockworkCompanionClient($site))->health();
        } catch (Throwable $e) {
            $this->warn("  ⚠ /health failed after install: {$e->getMessage()}");

            return;
        }

        $site->forceFill([
            'companion_capabilities' => (array) ($health['capabilities'] ?? []),
            'companion_version' => (string) ($health['version'] ?? $site->companion_version),
            'companion_last_seen_at' => now(),
        ])->save();
    }

    /**
     * Persist enabled=true as a per-site override, then push the merged
     * payload. Returns true only when the push succeeded — that's the
     * signal Gatekeeper is live on the WordPress side.
     *
     * @param  array<string, int>  $stats
     */
    private function enableGatekeeper(Site $site, GatekeeperSettingsPusher $pusher, array &$stats): bool
    {
        $overrides = is_array($site->gatekeeper_settings) ? $site->gatekeeper_settings : [];
        $overrides['enabled'] = true;
        $site->gatekeeper_settings = $overrides;
        $site->save();

        try {
            $payload = $pusher->buildPayload($site->fresh());
            (new ClockworkCompanionClient($site))->pushGatekeeperSettings($payload);
        } catch (Throwable $e) {
            $this->warn("  ⚠ Gatekeeper push failed: {$e->getMessage()}");
            $stats['gatekeeper_push_failed']++;

            return false;
        }

        $this->info('  ✓ Gatekeeper enabled (per-site override persisted, pushed to WordPress)');
        $stats['gatekeeper_enabled']++;

        return true;
    }

    /**
     * Deactivate LLAR via Companion REST. Returns true on success or when
     * the plugin isn't active/installed (nothing to deactivate).
     *
     * @param  array<string, int>  $stats
     */
    private function deactivateLlar(Site $site, array &$stats): bool
    {
        $client = new ClockworkCompanionClient($site);

        try {
            // Network-wide first — harmless no-op on single-site installs.
            try {
                $response = $client->togglePlugin(self::LLAR_SLUG, 'deactivate', true);
            } catch (Throwable) {
                $response = ['ok' => false];
            }

            if (! ($response['ok'] ?? false)) {
                $response = $client->togglePlugin(self::LLAR_SLUG, 'deactivate', false);
            }
        } catch (Throwable $e) {
            $this->warn("  ⚠ LLAR deactivate failed: {$e->getMessage()}");

            return false;
        }

        if ($response['ok'] ?? false) {
            $this->info('  ✓ LLAR deactivated');
            $stats['llar_deactivated']++;

            return true;
        }

        $msg = $response['message'] ?? $response['error'] ?? 'n/a';
        $this->line("  · LLAR deactivate: {$msg} (not active — proceeding to delete)");

        return true;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function deleteLlar(Site $site, array &$stats): bool
    {
        try {
            $response = (new ClockworkCompanionClient($site))->deletePlugin(self::LLAR_SLUG);
        } catch (Throwable $e) {
            $this->warn("  ⚠ LLAR delete failed (non-fatal, plugin is deactivated): {$e->getMessage()}");

            return false;
        }

        if ($response['ok'] ?? false) {
            $this->info('  ✓ LLAR files deleted');
            $stats['llar_deleted']++;

            return true;
        }

        $msg = $response['message'] ?? $response['error'] ?? 'n/a';
        $this->line("  · LLAR delete: {$msg} (may not be on disk)");

        return false;
    }
}
