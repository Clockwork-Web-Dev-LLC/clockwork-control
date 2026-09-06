<?php

namespace Modules\Azure;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Modules\Core\Contracts\CloudProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class AzureServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(AzureClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit AzureClient's
            // own Settings-driven operator override.
            return new AzureClient(
                tenantId: $r->get('azure.tenant_id'),
                clientId: $r->get('azure.client_id'),
                clientSecret: $r->get('azure.client_secret'),
                subscriptionId: $r->get('azure.subscription_id'),
                baseUrl: $r->get('azure.base_url', 'https://management.azure.com'),
                loginUrl: $r->get('azure.login_url', 'https://login.microsoftonline.com'),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'azure',
            name: 'Azure',
            description: 'Poll Azure VM CPU/memory metrics and match VMs to servers by IP.',
            credentialFields: [
                'tenant_id' => ['label' => 'Tenant ID', 'secret' => false],
                'client_id' => ['label' => 'Client ID', 'secret' => false],
                'client_secret' => ['label' => 'Client Secret', 'secret' => true],
                'subscription_id' => ['label' => 'Subscription ID', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'Code is written for Azure VM telemetry & reconciliation. Looking for agencies with Azure VMs to test in production.',
        );
    }

    public function cloudProvider(): ?CloudProvider
    {
        return $this->app->make(AzureCloudProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(AzureCheck::class);
    }
}
