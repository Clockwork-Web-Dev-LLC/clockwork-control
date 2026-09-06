<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\CompanionInstaller;
use App\Support\CompanionExclusion;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Modules\Pressable\PressableCompanionInstaller;

/**
 * Pressable counterpart to clockwork:install-companion. Same end state,
 * different transport (Pressable's async command API instead of SSH) —
 * see PressableCompanionInstaller for the mechanics.
 *
 * Deliberately --site-only for now (repeatable): the Pressable transport is
 * new, and the rollout plan is an explicit 5-10 site test round before any
 * fleet-wide switch exists. A --all-pressable sweep can be added once the
 * transport has proven itself on the test cohort.
 */
class InstallCompanionPressable extends Command
{
    protected $signature = 'clockwork:install-companion-pressable
        {--site=* : One or more site IDs or domains (must be Pressable-tracked)}
        {--force : Override the policy denylist (companion.excluded_domain_suffixes). Use deliberately.}
        {--throttle-ms=2000 : Sleep between sites. Pressable queues commands per site, but spacing whole installs avoids hammering their API. 0 = no delay.}';

    protected $description = 'Install or update the Clockwork Companion mu-plugin on Pressable-hosted sites (async command API transport).';

    public function handle(PressableCompanionInstaller $installer, CompanionExclusion $exclusion, ActionLogger $logger): int
    {
        $selectors = array_filter((array) $this->option('site'));
        if ($selectors === []) {
            $this->error('Pass at least one --site=<id|domain>.');

            return self::FAILURE;
        }

        $sites = $this->targetSites($selectors);

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

        $throttleMs = max(0, (int) $this->option('throttle-ms'));
        $tallies = ['installed' => 0, 'updated' => 0, 'current' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($sites->values() as $i => $site) {
            if ($i > 0 && $throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
            $this->line('');
            $this->line("→ {$site->domain} (pressable_site_id={$site->pressable_site_id})");

            try {
                $result = $installer->installOrUpdate($site);
            } catch (\Throwable $e) {
                $this->error('  ✗ '.$e->getMessage());
                $tallies['failed']++;

                continue;
            }

            $logger->recordCompanionInstall($site, $result);

            switch ($result['result']) {
                case CompanionInstaller::RESULT_INSTALLED:
                    $this->info("  ✓ {$result['message']}");
                    $tallies['installed']++;
                    break;
                case CompanionInstaller::RESULT_UPDATED:
                    $this->info("  ✓ {$result['message']}");
                    $tallies['updated']++;
                    break;
                case CompanionInstaller::RESULT_ALREADY_CURRENT:
                    $this->line("  ↺ {$result['message']}");
                    $tallies['current']++;
                    break;
                case CompanionInstaller::RESULT_SKIPPED_NOT_WP:
                    $this->line("  · {$result['message']}");
                    $tallies['skipped']++;
                    break;
                default:
                    $this->error("  ✗ {$result['message']}");
                    if (! empty($result['output'])) {
                        $this->line('    '.str_replace("\n", "\n    ", $result['output']));
                    }
                    $tallies['failed']++;
            }
        }

        $this->newLine();
        $this->info('Done: '.json_encode($tallies));

        return $tallies['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $selectors
     * @return Collection<int, Site>
     */
    private function targetSites(array $selectors): Collection
    {
        $sites = Site::query()
            ->where('hosting_provider', Site::HOSTING_PROVIDER_PRESSABLE)
            ->where(function ($q) use ($selectors) {
                foreach ($selectors as $selector) {
                    $q->orWhere('domain', $selector);
                    if (is_numeric($selector)) {
                        $q->orWhere('id', (int) $selector);
                    }
                }
            })
            ->orderBy('domain')
            ->get();

        $found = $sites->pluck('domain')
            ->merge($sites->pluck('id')->map(fn ($id) => (string) $id));
        foreach ($selectors as $selector) {
            if (! $found->contains($selector)) {
                $this->warn("  ? '{$selector}' matched no Pressable-tracked site (wrong domain, or a SpinupWP site?).");
            }
        }

        return $sites;
    }
}
