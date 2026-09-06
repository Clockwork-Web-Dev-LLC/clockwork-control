<?php

namespace Modules\Kinsta;

use App\Services\Companion\CompanionTarballBuilder;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\Support\SshConnector;

class KinstaServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\KinstaTest::class,
            ]);
        }
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(KinstaClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);
            $viewOnlyVal = $r->get('kinsta.view_only');
            $viewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.kinsta.view_only', true);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit KinstaClient's
            // own Settings-driven operator override.
            return new KinstaClient(
                apiKey: $r->get('kinsta.api_key'),
                baseUrl: $r->get('kinsta.base_url'),
                viewOnly: $viewOnly,
            );
        });

        $this->app->bind(KinstaSshCommandRunner::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new KinstaSshCommandRunner(
                connector: $app->make(SshConnector::class),
                client: $app->make(KinstaClient::class),
                configuredPassword: $r->get('kinsta.ssh_password'),
            );
        });

        $this->app->bind(KinstaCompanionInstaller::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new KinstaCompanionInstaller(
                connector: $app->make(SshConnector::class),
                tarballBuilder: $app->make(CompanionTarballBuilder::class),
                client: $app->make(KinstaClient::class),
                configuredPassword: $r->get('kinsta.ssh_password'),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'kinsta',
            name: 'Kinsta',
            description: 'Managed WordPress hosting with real per-environment SSH but no server concept — built from '
                .'Kinsta\'s documented API shapes, NOT yet validated against a live account. Biggest unverified '
                .'assumption: the SSH host/port/username lookup (KinstaClient::sshConnectionInfo()) is a best-effort '
                .'guess at where Kinsta\'s API exposes that data — confirm against a real environment before relying '
                .'on SSH-based features (Companion install, command execution) in production.',
            credentialFields: [
                'api_key' => ['label' => 'API Key', 'secret' => true],
                'ssh_password' => ['label' => 'SSH Password (optional — generated on demand per environment if unset)', 'secret' => true],
                'view_only' => ['label' => 'View-Only Mode', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'Code is written against Kinsta API docs. Looking for agencies with live Kinsta accounts to test endpoints and SSH connection info.',
        );
    }

    public function hostingProvider(): ?HostingProvider
    {
        return $this->app->make(KinstaHostingProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(KinstaCheck::class);
    }
}
