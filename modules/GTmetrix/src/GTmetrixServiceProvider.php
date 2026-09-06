<?php

namespace Modules\GTmetrix;

use App\Support\CredentialResolver;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class GTmetrixServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(GtmetrixClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new GtmetrixClient(
                apiKey: $r->get('gtmetrix.api_key', ''),
                baseUrl: $r->get('gtmetrix.base_url'),
                timeout: (int) $r->get('gtmetrix.timeout', 120),
                defaultRegion: (int) $r->get('gtmetrix.region', 4),
                pollInterval: (int) $r->get('gtmetrix.poll_interval_seconds', 5),
                pollMaxAttempts: (int) $r->get('gtmetrix.poll_max_attempts', 36),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'gtmetrix',
            name: 'GTmetrix',
            description: 'Nightly Lighthouse & Core Web Vitals performance scanning via the GTmetrix REST API v2.',
            credentialFields: [
                'api_key' => ['label' => 'API Key', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and in active daily use for nightly Lighthouse performance scores.',
        );
    }
}
