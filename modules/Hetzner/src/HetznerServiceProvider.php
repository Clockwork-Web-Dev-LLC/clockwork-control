<?php

namespace Modules\Hetzner;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Modules\Core\Contracts\CloudProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class HetznerServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(HetznerClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit HetznerClient's
            // own Settings-driven operator override.
            return new HetznerClient(
                token: $r->get('hetzner.token'),
                baseUrl: $r->get('hetzner.base_url'),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'hetzner',
            name: 'Hetzner',
            description: 'Poll Hetzner Cloud server CPU metrics and match servers to the fleet by IP.',
            credentialFields: [
                'token' => ['label' => 'API Token', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and in active daily use across production servers.',
        );
    }

    public function cloudProvider(): ?CloudProvider
    {
        return $this->app->make(HetznerCloudProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(HetznerCheck::class);
    }
}
