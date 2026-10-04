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

        $this->app->bind(ChromeUxReportClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);
            $apiKey = $r->get('crux.api_key') ?: $r->get('psi.api_key', config('clockwork.crux.api_key') ?: config('clockwork.psi.api_key', ''));

            return new ChromeUxReportClient(
                apiKey: $apiKey,
                timeout: (int) $r->get('crux.timeout', config('clockwork.crux.timeout', 30)),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'psi',
            name: 'PageSpeed Insights',
            description: 'Automated Lighthouse & Core Web Vitals performance scanning via Google PageSpeed Insights v5 API and Chrome UX Report (CrUX).',
            credentialFields: [
                'api_key' => ['label' => 'API Key', 'secret' => true],
                'crux_api_key' => ['label' => 'CrUX API Key (Optional)', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified performance engine for Lighthouse and CrUX real-user metrics.',
        );
    }
}
