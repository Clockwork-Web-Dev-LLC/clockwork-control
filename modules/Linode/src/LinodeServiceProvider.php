<?php

namespace Modules\Linode;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Modules\Core\Contracts\CloudProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class LinodeServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(LinodeClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit LinodeClient's
            // own Settings-driven operator override.
            return new LinodeClient(
                token: $r->get('linode.token'),
                baseUrl: $r->get('linode.base_url'),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'linode',
            name: 'Linode (Akamai)',
            description: 'Poll Linode instance metrics, size tiers, and IP matching for SpinupWP and standalone cloud servers.',
            credentialFields: [
                'token' => ['label' => 'Personal Access Token', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'Code is written for Linode API v4 metrics & reconciliation. Looking for agencies with Linode instances to test in production.',
        );
    }

    public function cloudProvider(): ?CloudProvider
    {
        return $this->app->make(LinodeCloudProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(LinodeCheck::class);
    }
}
