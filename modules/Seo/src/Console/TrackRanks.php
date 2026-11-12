<?php

namespace Modules\Seo\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\Ranks\RankTracker;

/**
 * Rank tracking, run every hour. Each run first stores any finished checks,
 * then queues a check for every keyword that is due. Nothing is due twice,
 * so running it more often costs nothing.
 */
class TrackRanks extends Command
{
    protected $signature = 'clockwork:seo-track-ranks
        {--site= : Only this site (ID or domain)}
        {--collect-only : Store finished checks but queue nothing new}';

    protected $description = 'Queue due Google rank checks on DataForSEO and store the finished ones';

    public function handle(RankTracker $tracker, DataForSeoClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('DataForSEO is not set up. Add the API login on Settings → Integrations.');

            return self::FAILURE;
        }

        $siteId = null;
        if ($this->option('site')) {
            $site = Site::query()->where('id', $this->option('site'))->orWhere('domain', $this->option('site'))->first();
            if ($site === null) {
                $this->error('No site matches "'.$this->option('site').'".');

                return self::FAILURE;
            }
            $siteId = $site->id;
        }

        $collected = $tracker->collect();
        $this->line(sprintf('Stored %d finished checks; %d still queued; %d failed.', $collected['collected'], $collected['pending'], $collected['failed']));

        if ($this->option('collect-only')) {
            return $collected['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $posted = $tracker->postDue($siteId);
        $this->line(sprintf('%d keywords due; queued %d for $%.4f; %d not accepted.', $posted['due'], $posted['posted'], $posted['cost'], $posted['failed']));

        if ($posted['error'] !== null) {
            $this->error($posted['error']);
        }

        return ($posted['failed'] > 0 || $posted['refused'] || $collected['failed'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
