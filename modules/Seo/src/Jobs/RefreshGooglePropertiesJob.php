<?php

namespace Modules\Seo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Seo\Google\GoogleApiResult;
use Modules\Seo\Google\PropertyDiscovery;
use Modules\Seo\Models\GoogleConnection;

/** "Refresh list" on the profile page, and right after connecting. */
class RefreshGooglePropertiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(PropertyDiscovery $discovery): void
    {
        $connection = GoogleConnection::current();
        if ($connection === null) {
            return;
        }

        $result = $discovery->refresh($connection);

        if ($result instanceof GoogleApiResult) {
            Log::warning('seo.google.properties_refresh_failed', ['error' => $result->error]);
        }
    }
}
