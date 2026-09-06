<?php

namespace Modules\Sucuri;

use App\Support\CredentialResolver;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class SucuriServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(SucuriSiteCheckClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new SucuriSiteCheckClient(
                baseUrl: $r->get('sucuri.base_url', config('clockwork.sucuri.base_url', 'https://sitecheck.sucuri.net')),
                timeout: (int) $r->get('sucuri.timeout', config('clockwork.sucuri.timeout', 30)),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'sucuri',
            name: 'Sucuri SiteCheck',
            description: 'Automated remote malware and blacklist scanning via the Sucuri SiteCheck v3 API (ManageWP replacement suite).',
            credentialFields: [],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified remote malware detection and blacklist scanning.',
        );
    }
}
