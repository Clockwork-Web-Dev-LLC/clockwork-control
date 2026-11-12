<?php

namespace Modules\Seo\Console;

use Illuminate\Console\Command;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Research\DomainResearch;

/**
 * Once a month: how many keywords each client and each named competitor
 * ranks for. About a cent per domain. A domain already counted this month
 * is skipped, so running it again costs nothing.
 */
class SnapshotDomains extends Command
{
    protected $signature = 'clockwork:seo-snapshot-domains {--site= : Only this site (ID or domain)}';

    protected $description = 'Store this month\'s ranking-keyword count for every SEO client and its competitors';

    public function handle(DomainResearch $research, DataForSeoClient $client): int
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
            $market = Market::forProfile($profile);
            $domains = [$profile->site->domain, ...array_filter((array) ($profile->competitors ?? []), 'is_string')];

            foreach (array_unique(array_map([DomainResearch::class, 'domain'], $domains)) as $domain) {
                if ($domain === '' || $research->hasSnapshotThisMonth($domain, $market)) {
                    $skipped++;

                    continue;
                }

                $result = $research->snapshot($domain, $market, $profile->site_id);
                if (! $result['ok']) {
                    $failed++;
                    $this->error("{$domain}: {$result['error']}");
                    if ($result['refused']) {
                        // Over budget or a bad login: every next call would fail the same way.
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
