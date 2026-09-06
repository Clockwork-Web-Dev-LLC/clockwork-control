<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\CompanionInstaller;
use App\Support\CompanionExclusion;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Promote the current Companion plugin from the canary set to the rest of
 * the fleet. Refuses to run unless the canary has been verified by
 * companion-canary-deploy in the same plugin-version cycle. The gate is
 * the `companion.canary_verified_at` setting plus the verified capability
 * matching what we expect from the new plugin.
 *
 * Scope: every site with `companion_installed=true` (existing fleet upgrade
 * pattern) MINUS the canary set (already done) MINUS sites the operator
 * passes via --skip=domain1,domain2 if any need to be deferred.
 *
 * Re-uses CompanionInstaller::installOrUpdate (SpinupWP, SSH) or
 * PressableCompanionInstaller::installOrUpdate (Pressable, async command
 * API — no SSH) per site, so install behavior matches the corresponding
 * per-site command. Sequential — one connection per site, ~5s each on a
 * healthy SpinupWP fleet; Pressable sites take longer (chunked upload over
 * the async command API) but need no --throttle-ms (no SSH connect storm
 * risk since there's no SSH involved at all).
 */
class CompanionFleetDeploy extends Command
{
    protected $signature = 'clockwork:companion-fleet-deploy
        {--expect-capability=malware-scan : Capability the canary must have verified before fleet-deploy is allowed}
        {--rotate-secret : Rotate per-site HMAC secrets as part of install (rare; quarterly schedule covers this)}
        {--skip= : Comma-separated domains to skip in this batch}
        {--force : Bypass the canary-verification gate (DANGEROUS — only for emergency rollback)}
        {--throttle-ms=1000 : Sleep this many milliseconds between sites to avoid rapid-fire SSH connect storms that fail2ban/sshd may misread as an attack. 0 = no delay.}';

    protected $description = 'Roll the current Companion plugin to every installed site, after the canary verified the new endpoint. Gated by companion.canary_verified_at.';

    public function handle(
        Settings $settings,
        CompanionExclusion $exclusion,
        ActionLogger $logger,
    ): int {
        $expectCapability = (string) $this->option('expect-capability');

        if (! $this->option('force')) {
            $verifiedAt = (string) ($settings->get('companion.canary_verified_at', '') ?? '');
            $verifiedCap = (string) ($settings->get('companion.canary_verified_capability', '') ?? '');
            if ($verifiedAt === '' || $verifiedCap !== $expectCapability) {
                $this->error('Canary not verified for capability '.$expectCapability.'. Run clockwork:companion-canary-deploy first, or pass --force.');

                return self::FAILURE;
            }
            $this->info("Canary verified at {$verifiedAt} for {$verifiedCap} — proceeding.");
        } else {
            $this->warn('--force passed; skipping canary gate. Hope you know what you are doing.');
        }

        $canaryIds = (array) ($settings->get('companion.canary_site_ids', []));
        $skipDomains = array_filter(array_map('trim', explode(',', (string) $this->option('skip'))));

        $sites = Site::query()
            ->where('companion_installed', true)
            ->whereNotIn('id', $canaryIds)
            ->whereNotIn('domain', $skipDomains)
            ->hostMonitored()
            ->orderBy('domain')
            ->get();

        // Policy denylist (companion.excluded_domain_suffixes) — e.g. *.stateschools.example.
        // Fleet-deploy has no --force; excluded domains are never deployed here.
        // Use install-companion --site=X --force for a deliberate one-off.
        [$sites, $policyExcluded] = $exclusion->partition($sites);
        if ($policyExcluded->isNotEmpty()) {
            $this->line("Excluded by policy ({$policyExcluded->count()}): ".$policyExcluded->pluck('domain')->implode(', '));
        }

        if ($sites->isEmpty()) {
            $this->info('Nothing to deploy — every installed site is either in the canary set or excluded.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Deploy Companion to {$sites->count()} site(s)? This writes a new plugin file to every site.", false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $stats = ['installed' => 0, 'updated' => 0, 'current' => 0, 'failed' => 0, 'skipped' => 0];

        $bar = $this->output->createProgressBar($sites->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->start();

        // Inter-site throttle. Found 2026-06-30: a 125-site fleet pass at
        // ~0ms between sites occasionally trips SSH banner-exchange errors
        // (probably fail2ban or sshd per-IP rate limiting on the receiving
        // servers reading the rapid-fire pattern as an attack). 1s default
        // dampens it cleanly; +2 min total cost for a full fleet pass.
        $throttleMs = max(0, (int) $this->option('throttle-ms'));

        foreach ($sites as $i => $site) {
            if ($i > 0 && $throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
            $bar->setMessage($site->domain);

            // Per-site exceptions (SSH connect timeout, blown TLS, broken
            // permission grant) used to abort the entire fleet pass. Match
            // InstallCompanion's try/catch so one bad host doesn't shadow
            // the remaining 100+. Caught 2026-06-30 when sportsclient.example
            // SSH-timed-out at position 62/124 and killed the rest of the run.
            try {
                $result = $site->host()->companionInstaller()->installOrUpdate($site, (bool) $this->option('rotate-secret'));
            } catch (\Throwable $e) {
                $stats['failed']++;
                $bar->clear();
                $this->warn("  ✗ {$site->domain}: ".$e->getMessage());
                $bar->display();
                $bar->advance();

                continue;
            }

            $logger->recordCompanionInstall($site, $result);

            switch ($result['result']) {
                case CompanionInstaller::RESULT_INSTALLED: $stats['installed']++;
                    break;
                case CompanionInstaller::RESULT_UPDATED: $stats['updated']++;
                    break;
                case CompanionInstaller::RESULT_ALREADY_CURRENT: $stats['current']++;
                    break;
                case CompanionInstaller::RESULT_SKIPPED_NOT_WP: $stats['skipped']++;
                    break;
                default: $stats['failed']++;
                    break;
            }
            if ($result['result'] === CompanionInstaller::RESULT_FAILED && $this->getOutput()->isVerbose()) {
                $bar->clear();
                $this->line("  ✗ {$site->domain}: ".($result['message'] ?? 'failed'));
                $bar->display();
            }
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info("Done. installed={$stats['installed']} updated={$stats['updated']} current={$stats['current']} skipped={$stats['skipped']} failed={$stats['failed']}");

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
