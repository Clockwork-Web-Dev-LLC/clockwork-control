<?php

namespace App\Jobs;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Cache\SiteCachePurger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PurgeSiteCacheJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $siteId,
        public readonly string $actor = 'auto',
    ) {}

    public function handle(SiteCachePurger $purger, ActionLogger $logger): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        try {
            $result = $purger->purge($site);
        } catch (Throwable $e) {
            $logger->record(
                actionType: ActionLog::TYPE_CACHE_PURGED,
                summary: 'Cache purge threw: '.$e->getMessage(),
                site: $site,
                ok: false,
                error: $e->getMessage(),
                actor: $this->actor,
            );

            return;
        }

        $okLayers = collect($result['layers'])
            ->filter(fn (array $layer) => $layer['ok'])
            ->keys()
            ->implode(', ');

        $logger->record(
            actionType: ActionLog::TYPE_CACHE_PURGED,
            summary: $result['ok']
                ? 'Cache purged ('.$okLayers.')'
                : 'Cache purge found no applicable layers',
            site: $site,
            details: $result,
            ok: $result['ok'],
            actor: $this->actor,
        );
    }
}
