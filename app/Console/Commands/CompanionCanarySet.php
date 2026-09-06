<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Manage the Companion canary set — the small list of sites that get a new
 * plugin version BEFORE it rolls to the rest of the fleet. Persisted as a
 * JSON array of site IDs in the `companion.canary_site_ids` setting.
 *
 * Usage:
 *   clockwork:companion-canary-set example.com mysite.org ...   # save list
 *   clockwork:companion-canary-set --list                       # print current
 *   clockwork:companion-canary-set --clear                      # empty it
 */
class CompanionCanarySet extends Command
{
    protected $signature = 'clockwork:companion-canary-set
        {sites?* : Domains (or site IDs) to mark as canaries}
        {--list : Print the current canary set and exit}
        {--clear : Remove all canary sites}';

    protected $description = 'Set / list / clear the Companion canary site set used by companion-canary-deploy and companion-fleet-deploy.';

    public function handle(Settings $settings): int
    {
        if ($this->option('list')) {
            return $this->printList($settings);
        }

        if ($this->option('clear')) {
            $settings->put('companion.canary_site_ids', []);
            $this->info('Canary set cleared.');

            return self::SUCCESS;
        }

        $tokens = (array) $this->argument('sites');
        if ($tokens === []) {
            $this->error('Pass one or more domain/IDs, or use --list / --clear.');

            return self::FAILURE;
        }

        $resolvedIds = [];
        $missing = [];

        foreach ($tokens as $token) {
            $token = (string) $token;
            $site = is_numeric($token)
                ? Site::query()->find((int) $token)
                : Site::query()->where('domain', $token)->first();

            if ($site === null) {
                $missing[] = $token;

                continue;
            }
            $resolvedIds[] = $site->id;
        }

        if ($missing !== []) {
            $this->error('Could not resolve: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $resolvedIds = array_values(array_unique($resolvedIds));
        $settings->put('companion.canary_site_ids', $resolvedIds);

        $this->info('Canary set saved ('.count($resolvedIds).' sites):');

        return $this->printList($settings);
    }

    private function printList(Settings $settings): int
    {
        $ids = (array) ($settings->get('companion.canary_site_ids', []));
        if ($ids === []) {
            $this->line('  (empty)');

            return self::SUCCESS;
        }

        $sites = Site::query()->whereIn('id', $ids)->orderBy('domain')->get();
        foreach ($sites as $site) {
            $version = $site->companion_version ?? '—';
            $caps = is_array($site->companion_capabilities ?? null)
                ? (in_array('malware-scan', $site->companion_capabilities, true) ? 'malware-scan ✓' : 'malware-scan ✗')
                : '?';
            $this->line(sprintf('  - %-40s installed=%s caps=%s', $site->domain, str_pad($version, 8), $caps));
        }

        return self::SUCCESS;
    }
}
