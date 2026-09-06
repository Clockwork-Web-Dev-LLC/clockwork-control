<?php

namespace Modules\WPEngine;

use App\Models\Site;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\CompanionInstaller;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SiteCommandRunner;

/**
 * WP Engine has no server concept at all (same shape as Pressable) — every
 * operation is addressed by install name. Unlike Pressable, WP Engine does
 * expose a real per-install SSH gateway (see WPEngineSshCommandRunner), so
 * this provider's CAP_SSH-adjacent capabilities differ from Pressable's.
 *
 * WPEngineSshCommandRunner and WPEngineCompanionInstaller are resolved via
 * app() inside commandRunner()/companionInstaller() rather than injected
 * into this class's constructor — both require the SSH private key to be
 * resolved from CredentialResolver, and isConfigured() (checked far more
 * often, e.g. on every integrations-settings page load) should not force
 * that resolution just to answer "are the API credentials set".
 */
class WPEngineHostingProvider implements HostingProvider
{
    public function __construct(
        private readonly WPEngineClient $client,
    ) {}

    public function id(): string
    {
        return Site::HOSTING_PROVIDER_WPENGINE;
    }

    public function label(): string
    {
        return 'WP Engine';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function supports(string $capability): bool
    {
        return match ($capability) {
            // Real per-install SSH gateway exists, but there's no "server"
            // row concept — installs are addressed by name, not server_id.
            self::CAP_SSH => false,
            self::CAP_SERVER_LINKAGE => false,
            // See WPEngineClient::sslCertificates()'s docblock — the
            // endpoint this would sync from is unconfirmed, but the
            // capability itself (a provider-side cert API to sync from,
            // vs. falling back to a live TLS probe) is real once confirmed.
            self::CAP_CERT_SYNC => true,
            // No native performance-scan engine documented for WP Engine —
            // falls back to the GTmetrix/PSI chain like SpinupWp.
            self::CAP_PERFORMANCE_SCAN => false,
            self::CAP_ORPHAN_DETECTION => true,
            self::CAP_COMPANION => ! $this->client->isViewOnly(),
            default => false,
        };
    }

    public function commandRunner(): ?SiteCommandRunner
    {
        return $this->client->isViewOnly() ? null : app(WPEngineSshCommandRunner::class);
    }

    public function companionInstaller(): ?CompanionInstaller
    {
        return $this->client->isViewOnly() ? null : app(WPEngineCompanionInstaller::class);
    }

    public function backupRelayAdapter(): ?BackupRelayAdapter
    {
        return null;
    }

    public function panelUrl(Site $site): ?string
    {
        // No confirmed WP Engine per-install dashboard URL pattern found in
        // this codebase or its published docs — returning a guessed URL
        // would be worse than no link at all (see PressableHostingProvider's
        // identical stance).
        return null;
    }
}
