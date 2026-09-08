<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Screenshots\SiteScreenshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CaptureSiteScreenshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $siteId) {}

    public function handle(SiteScreenshotService $service): void
    {
        $site = Site::find($this->siteId);
        if ($site && ! $site->is_inactive) {
            $service->capture($site);
        }
    }
}
