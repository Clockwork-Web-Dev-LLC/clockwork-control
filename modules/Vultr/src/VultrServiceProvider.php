<?php

namespace Modules\Vultr;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Modules\Core\Contracts\CloudProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class VultrServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(VultrClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit VultrClient's
            // own Settings-driven operator override.
            return new VultrClient(
                apiKey: $r->get('vultr.api_key'),
                baseUrl: $r->get('vultr.base_url'),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'vultr',
            name: 'Vultr',
            description: 'Poll Vultr instances, size tiers, and IP matching for SpinupWP and standalone cloud servers.',
            credentialFields: [
                'api_key' => ['label' => 'API Key', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'Code is written for Vultr API v2 instance reconciliation. Looking for agencies with Vultr servers to test in production.',
        );
    }

    public function cloudProvider(): ?CloudProvider
    {
        return $this->app->make(VultrCloudProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(VultrCheck::class);
    }
}
