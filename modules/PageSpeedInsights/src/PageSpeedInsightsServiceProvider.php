<?php

namespace Modules\PageSpeedInsights;

use App\Support\CredentialResolver;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class PageSpeedInsightsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(PageSpeedInsightsClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new PageSpeedInsightsClient(
                apiKey: $r->get('psi.api_key', config('clockwork.psi.api_key', '')),
                timeout: (int) $r->get('psi.timeout', config('clockwork.psi.timeout', 90)),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'psi',
            name: 'PageSpeed Insights',
            description: 'Automated Lighthouse & Core Web Vitals performance scanning via Google PageSpeed Insights v5 API.',
            credentialFields: [
                'api_key' => ['label' => 'API Key', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified performance engine for Lighthouse metrics.',
        );
    }
}
