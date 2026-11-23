<?php

namespace Modules\Seo\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Modules\Seo\Ai\AiVisibilityTracker;
use Modules\Seo\DataForSeo\DataForSeoClient;

/**
 * AI visibility, run every hour. Each run stores answers that have come
 * back, then queues every prompt that is due on each chosen AI engine.
 */
class TrackAiVisibility extends Command
{
    protected $signature = 'clockwork:seo-track-ai
        {--site= : Only this site (ID or domain)}
        {--collect-only : Store finished answers but queue nothing new}';

    protected $description = 'Queue due AI visibility prompts on DataForSEO and store the finished answers';

    public function handle(AiVisibilityTracker $tracker, DataForSeoClient $client): int
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
        $this->line(sprintf('Stored %d answers; %d still queued; %d failed.', $collected['collected'], $collected['pending'], $collected['failed']));

        if ($this->option('collect-only')) {
            return $collected['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $posted = $tracker->postDue($siteId);
        $this->line(sprintf('%d checks due; queued %d for $%.4f; %d not accepted.', $posted['due'], $posted['posted'], $posted['cost'], $posted['failed']));
        if ($posted['error'] !== null) {
            $this->error($posted['error']);
        }

        return ($posted['failed'] > 0 || $posted['refused'] || $collected['failed'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
