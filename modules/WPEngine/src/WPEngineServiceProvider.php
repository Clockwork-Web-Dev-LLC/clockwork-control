<?php

namespace Modules\WPEngine;

use App\Services\Companion\CompanionTarballBuilder;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\Support\SshConnector;

class WPEngineServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\WPEngineTest::class,
            ]);
        }
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(WPEngineClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);
            $viewOnlyVal = $r->get('wpengine.view_only');
            $viewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.wpengine.view_only', true);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit WPEngineClient's
            // own Settings-driven operator override.
            return new WPEngineClient(
                apiUserId: $r->get('wpengine.api_user_id'),
                apiPassword: $r->get('wpengine.api_password'),
                baseUrl: $r->get('wpengine.base_url'),
                viewOnly: $viewOnly,
            );
        });

        $this->app->bind(WPEngineSshCommandRunner::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new WPEngineSshCommandRunner(
                connector: $app->make(SshConnector::class),
                privateKey: $r->get('wpengine.ssh_private_key'),
                privateKeyPassphrase: $r->get('wpengine.ssh_private_key_passphrase'),
            );
        });

        $this->app->bind(WPEngineCompanionInstaller::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new WPEngineCompanionInstaller(
                connector: $app->make(SshConnector::class),
                tarballBuilder: $app->make(CompanionTarballBuilder::class),
                privateKey: $r->get('wpengine.ssh_private_key'),
                privateKeyPassphrase: $r->get('wpengine.ssh_private_key_passphrase'),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'wpengine',
            name: 'WP Engine',
            description: 'Managed WordPress hosting via WP Engine\'s REST API (installs/domains) plus a real per-install SSH gateway for command execution and Companion installs. Built from WP Engine\'s published API documentation only — NOT yet validated against a live WP Engine account. Endpoint shapes for backups and SSL certificates in particular are best-effort guesses; see WPEngineClient\'s docblocks before relying on them.',
            credentialFields: [
                'api_user_id' => ['label' => 'API User ID', 'secret' => false],
                'api_password' => ['label' => 'API Password', 'secret' => true],
                'ssh_private_key' => ['label' => 'SSH Private Key (PEM)', 'secret' => true],
                'ssh_private_key_passphrase' => ['label' => 'SSH Private Key Passphrase (optional)', 'secret' => true],
                'view_only' => ['label' => 'View-Only Mode', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'Code is written against WP Engine API docs. Looking for agencies with live WP Engine accounts to test endpoints and SSH gateway.',
        );
    }

    public function hostingProvider(): ?HostingProvider
    {
        return $this->app->make(WPEngineHostingProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(WPEngineCheck::class);
    }
}
