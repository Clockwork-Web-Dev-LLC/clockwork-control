<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\CompanionInstaller;
use App\Support\CompanionExclusion;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class InstallCompanion extends Command
{
    protected $signature = 'clockwork:install-companion
        {--site= : Limit to a specific site ID or domain}
        {--all-enabled : Process every site where contact_form_test_enabled=true (initial Companion rollout to opted-in clients)}
        {--all-installed : Process every site where companion_installed=true (fleet-wide upgrade after a plugin release)}
        {--rotate-secret : Generate a fresh per-site secret instead of reusing the existing one}
        {--force : Override the policy denylist (companion.excluded_domain_suffixes) — e.g. to deploy to an otherwise-excluded government domain. Use deliberately.}
        {--throttle-ms=1000 : Sleep this many milliseconds between sites to avoid rapid-fire SSH connect storms. 0 = no delay. Only applies to --all-enabled / --all-installed.}';

    protected $description = 'Install or update the Clockwork Companion mu-plugin on opted-in sites.';

    public function handle(CompanionInstaller $installer, CompanionExclusion $exclusion, ActionLogger $logger): int
    {
        if (! $this->option('site') && ! $this->option('all-enabled') && ! $this->option('all-installed')) {
            $this->error('Pass --site=<id|domain>, --all-enabled, or --all-installed.');

            return self::FAILURE;
        }

        if ($this->option('all-installed') && ! $this->confirmFleetUpgrade()) {
            return self::SUCCESS;
        }

        $sites = $this->targetSites();

        // Policy denylist. Drop excluded domains (e.g. *.stateschools.example) unless the
        // operator explicitly passes --force. Applies to every path including
        // a single --site, so an excluded government domain can't be deployed
        // by a stray command or a future --all-enabled sweep.
        if (! $this->option('force')) {
            [$sites, $excluded] = $exclusion->partition($sites);
            foreach ($excluded as $site) {
                $this->warn("  ⊘ {$site->domain}: excluded by policy (companion.excluded_domain_suffixes) — pass --force to override.");
            }
        }

        if ($sites->isEmpty()) {
            $this->warn('No sites match the given filters.');

            return self::SUCCESS;
        }

        $installed = 0;
        $updated = 0;
        $current = 0;
        $skipped = 0;
        $failed = 0;

        // Inter-site throttle. Found 2026-06-30: a 125-site fleet pass at
        // 0ms between sites occasionally trips SSH banner-exchange errors
        // (probably fail2ban or sshd per-IP rate limiting reading the
        // rapid-fire pattern as an attack). 1s default dampens it cleanly;
        // +2 min total cost on a full fleet pass.
        $throttleMs = max(0, (int) $this->option('throttle-ms'));

        foreach ($sites as $i => $site) {
            if ($i > 0 && $throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
            $this->line('');
            $this->line("→ {$site->domain} (server={$site->server?->name})");

            // Single-site exceptions (unreachable SSH, blown TLS, broken
            // permission grant) used to abort the entire fleet run. Catch
            // here so one bad host doesn't shadow the other 116 sites.
            try {
                $result = $installer->installOrUpdate($site, (bool) $this->option('rotate-secret'));
            } catch (\Throwable $e) {
                $this->error('  ✗ '.$e->getMessage());
                $failed++;

                continue;
            }

            $logger->recordCompanionInstall($site, $result);

            switch ($result['result']) {
                case CompanionInstaller::RESULT_INSTALLED:
                    $this->info("  ✓ {$result['message']}");
                    $installed++;
                    break;
                case CompanionInstaller::RESULT_UPDATED:
                    $this->info("  ✓ {$result['message']}");
                    $updated++;
                    break;
                case CompanionInstaller::RESULT_ALREADY_CURRENT:
                    $this->line("  ↺ {$result['message']}");
                    $current++;
                    break;
                case CompanionInstaller::RESULT_SKIPPED_NOT_WP:
                    $this->line("  · {$result['message']}");
                    $skipped++;
                    break;
                default:
                    $this->error("  ✗ {$result['message']}");
                    $failed++;
            }

            if (! empty($result['output']) && $this->getOutput()->isVerbose()) {
                $this->line('  --- remote output ---');
                foreach (explode("\n", $result['output']) as $line) {
                    $this->line('  '.$line);
                }
            }
        }

        $this->line('');
        $this->info("Done. installed={$installed}, updated={$updated}, already-current={$current}, skipped={$skipped}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function targetSites()
    {
        $q = Site::query()->with('server')->where('is_wordpress', true);

        if ($siteOpt = $this->option('site')) {
            $q->where(function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });
        } elseif ($this->option('all-installed')) {
            // Fleet-wide upgrade path. Skip ignored servers (no point pushing
            // to hosts we can't reach) and require an existing secret —
            // sites where companion_installed=true but companion_secret was
            // somehow cleared need a manual --site=X --rotate-secret first.
            $q->where('companion_installed', true)
                ->whereNotNull('companion_secret')
                ->whereHas('server', fn ($qq) => $qq->monitored());
        } elseif ($this->option('all-enabled')) {
            $q->where('contact_form_test_enabled', true);
        }

        return $q->orderBy('domain')->get();
    }

    /**
     * --all-installed touches every Companion site at once — at ~150 sites that's
     * dozens of rsync passes plus per-site /health probes. Confirm before kicking
     * off so a stray flag in a different terminal doesn't spam the fleet.
     */
    private function confirmFleetUpgrade(): bool
    {
        $count = Site::query()
            ->where('is_wordpress', true)
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret')
            ->whereHas('server', fn ($q) => $q->monitored())
            ->count();

        if ($count === 0) {
            $this->warn('No Companion-installed sites match.');

            return false;
        }

        return $this->confirm(
            "Push the local Companion build to {$count} site".($count === 1 ? '' : 's').'. Continue?',
            true
        );
    }
}
