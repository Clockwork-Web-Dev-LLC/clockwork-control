<?php

namespace Modules\Seo\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Modules\Seo\Audit\SiteAuditRunner;
use Modules\Seo\DataForSeo\DataForSeoClient;

/**
 * Site audits, run every hour. Each run stores crawls that have finished,
 * then starts a crawl for every client whose last one is older than the
 * chosen interval. Nothing is crawled twice in an interval.
 */
class RunSiteAudits extends Command
{
    protected $signature = 'clockwork:seo-site-audit
        {--site= : Only this site (ID or domain)}
        {--collect-only : Store finished crawls but start none}
        {--now : Start a crawl for --site now, whatever the schedule says}';

    protected $description = 'Store finished site crawls from DataForSEO and start the ones that are due';

    public function handle(SiteAuditRunner $runner, DataForSeoClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('DataForSEO is not set up. Add the API login on Settings → Integrations.');

            return self::FAILURE;
        }

        $site = null;
        if ($this->option('site')) {
            $site = Site::query()->where('id', $this->option('site'))->orWhere('domain', $this->option('site'))->first();
            if ($site === null) {
                $this->error('No site matches "'.$this->option('site').'".');

                return self::FAILURE;
            }
        }

        $collected = $runner->collect();
        $this->line(sprintf('Stored %d finished crawls; %d still crawling; %d failed.', $collected['finished'], $collected['crawling'], $collected['failed']));

        if ($this->option('collect-only')) {
            return $collected['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('now')) {
            if ($site === null) {
                $this->error('--now needs --site.');

                return self::FAILURE;
            }
            $result = $runner->start($site);
            $result['ok']
                ? $this->line(sprintf('Started a crawl of %s, up to %d pages.', $site->domain, $result['audit']->max_pages))
                : $this->error((string) $result['error']);

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $started = $runner->startDue($site?->id);
        $this->line(sprintf('%d sites due; started %d crawls; %d not started.', $started['due'], $started['started'], $started['failed']));
        if ($started['error'] !== null) {
            $this->error($started['error']);
        }

        return ($started['failed'] > 0 || $collected['failed'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
