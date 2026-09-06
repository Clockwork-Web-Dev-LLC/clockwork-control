<?php

namespace App\Providers;

use App\Services\Cloudflare\CloudflareClient;
use App\Services\DigitalOcean\SpacesClient;
use App\Services\Security\BlacklistChecker;
use App\Services\Ssh\SshClient;
use App\Support\CredentialResolver;
use Illuminate\Support\ServiceProvider;
use Modules\PageSpeedInsights\PageSpeedInsightsClient;
use Modules\Sucuri\SucuriSiteCheckClient;

/**
 * One lazy container binding per credential-holding client: each closure
 * resolves CredentialResolver and passes explicit values into the client's
 * existing nullable constructor params, so the client's own
 * `??= config(...)` fallback never fires and client internals stay
 * untouched. Every closure body only runs when the class is actually
 * resolved — never at register() time — so a fresh install with no
 * migrations run yet never hits integration_credentials during boot.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CredentialResolver::class);

        $this->app->bind(SpacesClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new SpacesClient(
                key: $r->get('do_spaces.key'),
                secret: $r->get('do_spaces.secret'),
                region: $r->get('do_spaces.region'),
                bucket: $r->get('do_spaces.bucket'),
                prefixTemplate: $r->get('do_spaces.prefix_template'),
            );
        });

        $this->app->bind(CloudflareClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new CloudflareClient(
                token: $r->get('cloudflare.api_token'),
                baseUrl: $r->get('cloudflare.base_url'),
                timeout: (int) $r->get('cloudflare.timeout', 15),
                writeToken: $r->get('cloudflare.write_token'),
            );
        });

        $this->app->bind(BlacklistChecker::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new BlacklistChecker(
                gsbApiKey: $r->get('security_scans.google_safe_browsing_key', ''),
                urlhausAuthKey: $r->get('security_scans.urlhaus_auth_key', ''),
                httpTimeout: (int) $r->get('security_scans.blacklist_timeout', 10),
            );
        });

        $this->app->bind(SshClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new SshClient(
                connectTimeout: (int) $r->get('ssh.connect_timeout', 10),
                execTimeout: (int) $r->get('ssh.exec_timeout', 30),
                preflightTimeout: (float) $r->get('ssh.preflight_timeout', 2),
                defaultKeyPath: $r->get('ssh.default_key_path'),
                defaultKeyPassphrase: $r->get('ssh.default_key_passphrase'),
            );
        });

        if (class_exists(PageSpeedInsightsClient::class) && class_exists(\App\Services\Performance\PageSpeedInsightsClient::class)) {
            $this->app->alias(PageSpeedInsightsClient::class, \App\Services\Performance\PageSpeedInsightsClient::class);
        }

        if (class_exists(SucuriSiteCheckClient::class) && class_exists(\App\Services\Security\SucuriSiteCheckClient::class)) {
            $this->app->alias(SucuriSiteCheckClient::class, \App\Services\Security\SucuriSiteCheckClient::class);
        }
    }
}
