<?php

namespace Modules\DigitalOcean;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Modules\Core\Contracts\CloudProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class DigitalOceanServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(DigitalOceanClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            // timeout intentionally NOT resolved here — a concrete value
            // passed via a promoted constructor param would short-circuit
            // DigitalOceanClient's own Settings-driven operator override
            // (the "??=" in its constructor body never fires once the
            // property is already non-null). Let the client resolve it.
            return new DigitalOceanClient(
                token: $r->get('digitalocean.token'),
                baseUrl: $r->get('digitalocean.base_url'),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'digitalocean',
            name: 'DigitalOcean',
            description: 'Poll DigitalOcean droplet CPU/memory/disk metrics and match droplets to servers by IP. (DigitalOcean Spaces, used for offsite backups, is a separate credential group — not part of this module.)',
            credentialFields: [
                'token' => ['label' => 'Personal Access Token', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and in active daily use across production droplets.',
        );
    }

    public function cloudProvider(): ?CloudProvider
    {
        return $this->app->make(DigitalOceanCloudProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(DigitalOceanCheck::class);
    }
}
