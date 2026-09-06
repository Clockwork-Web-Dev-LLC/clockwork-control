<?php

namespace Modules\Cloudways;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Modules\Core\Contracts\CloudProvider;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

/**
 * Cloudways is architecturally different from the other HostingProvider
 * modules (Pressable, WP Engine, Kinsta): it provisions real servers, each
 * hosting multiple apps, matching this app's Server->hasMany(Site) model
 * exactly — the same shape as SpinupWP. That's why this one module
 * implements BOTH hostingProvider() and cloudProvider(): the server/app
 * split means Cloudways is simultaneously "a place sites live with SSH
 * access" (HostingProvider) and "a fleet of cloud instances to poll metrics
 * from and reconcile for deletion" (CloudProvider) — both halves are
 * genuinely needed, not a stretch to justify one ServiceProvider doing two
 * jobs. ModuleServiceProvider's hostingProvider()/cloudProvider() hooks are
 * independent and null-default in the base class specifically so a module
 * can override either, both, or neither.
 */
class CloudwaysServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\CloudwaysTest::class,
            ]);
        }
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(CloudwaysClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);
            $viewOnlyVal = $r->get('cloudways.view_only');
            $viewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.cloudways.view_only', true);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit CloudwaysClient's
            // own Settings-driven operator override.
            return new CloudwaysClient(
                apiKey: $r->get('cloudways.api_key'),
                email: $r->get('cloudways.email'),
                baseUrl: $r->get('cloudways.base_url'),
                viewOnly: $viewOnly,
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'cloudways',
            name: 'Cloudways',
            description: 'Cloudways provisions real servers hosting multiple apps each (same Server->hasMany(Site) shape as SpinupWP), so this module implements both the HostingProvider and CloudProvider contracts. '
                .'Needs verification against a real Cloudways account before production use — endpoint shapes here are modeled on Cloudways\' public docs, not confirmed against live responses. '
                .'Only Cloudways API v2 is supported (v1 reached end-of-life 2026-03-31). '
                .'Server metrics come from Cloudways\' own monitoring API, not the underlying cloud\'s native one, since this app only holds Cloudways credentials — not credentials for whichever cloud (DO/AWS/GCP/Vultr/Linode) a given Cloudways server actually runs on.',
            credentialFields: [
                'api_key' => ['label' => 'API Key', 'secret' => true],
                'email' => ['label' => 'Account Email', 'secret' => false],
                'view_only' => ['label' => 'View-Only Mode', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'Code is written for Cloudways API v2 & server monitoring. Looking for agencies with live Cloudways accounts to test SSH/sudo and metrics.',
        );
    }

    public function hostingProvider(): ?HostingProvider
    {
        return $this->app->make(CloudwaysHostingProvider::class);
    }

    public function cloudProvider(): ?CloudProvider
    {
        return $this->app->make(CloudwaysCloudProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(CloudwaysCheck::class);
    }
}
