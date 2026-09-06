<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushCompanionBrandingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $siteId = null
    ) {}

    public function handle(CompanionBrandingManager $brandingManager): void
    {
        if ($this->siteId !== null) {
            $site = Site::query()->find($this->siteId);
            if ($site && $site->companion_installed) {
                $brandingManager->syncSite($site);
            }

            return;
        }

        $brandingManager->syncFleet();
    }
}
