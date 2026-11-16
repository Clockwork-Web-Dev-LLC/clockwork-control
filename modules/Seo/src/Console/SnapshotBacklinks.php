<?php

namespace Modules\Seo\Console;

use Illuminate\Console\Command;
use Modules\Seo\Backlinks\BacklinkResearch;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\Models\SiteProfile;

/**
 * Once a month: backlink totals for each client and its named competitors,
 * and the list of domains linking to each client, which is where new and
 * lost links come from. A domain already counted this month is skipped.
 */
class SnapshotBacklinks extends Command
{
    protected $signature = 'clockwork:seo-snapshot-backlinks {--site= : Only this site (ID or domain)}';

    protected $description = 'Store this month\'s backlink totals for every SEO client and its competitors, and each client\'s referring domains';

    public function handle(BacklinkResearch $backlinks, DataForSeoClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('DataForSEO is not set up. Add the API login on Settings → Integrations.');

            return self::FAILURE;
        }

        $profiles = SiteProfile::query()->with('site')->where('is_enabled', true)->get()
            ->filter(fn (SiteProfile $p) => $p->site !== null && ! $p->site->is_inactive)
            ->when($this->option('site'), fn ($c) => $c->filter(fn (SiteProfile $p) => (string) $p->site_id === (string) $this->option('site') || $p->site->domain === $this->option('site')));

        $done = $skipped = $failed = 0;
        $cost = 0.0;

        foreach ($profiles as $profile) {
            $own = BacklinkResearch::domain((string) $profile->site->domain);
            $domains = array_unique([$own, ...array_map([BacklinkResearch::class, 'domain'], array_filter((array) ($profile->competitors ?? []), 'is_string'))]);

            foreach ($domains as $domain) {
                if ($domain === '' || $backlinks->hasSnapshotThisMonth($domain)) {
                    $skipped++;

                    continue;
                }

                $result = $backlinks->snapshot($domain, $profile->site_id);
                // The client's own linking domains, in the same month as its totals.
                if ($result['ok'] && $domain === $own) {
                    $list = $backlinks->pullReferringDomains($domain, BacklinkResearch::MAX_DOMAINS, $profile->site_id);
                    $result = $list['ok'] ? ['cost' => $result['cost'] + $list['cost']] + $result : ['ok' => false, 'error' => $list['error'], 'refused' => $list['refused']] + $result;
                }

                if (! $result['ok']) {
                    $failed++;
                    $this->error("{$domain}: {$result['error']}");
                    if ($result['refused']) {
                        $this->line("Stopped. Counted {$done}, skipped {$skipped}, failed {$failed}.");

                        return self::FAILURE;
                    }

                    continue;
                }

                $done++;
                $cost += $result['cost'];
            }
        }

        $this->line(sprintf('Counted %d domains for $%.4f; %d already done this month; %d failed.', $done, $cost, $skipped, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
