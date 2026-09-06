<?php

namespace Modules\Cloudways;

use App\Models\Site;
use App\Services\Companion\CompanionInstaller as SshCompanionInstaller;
use App\Services\Ssh\SshCommandRunner;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\CompanionInstaller;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SiteCommandRunner;

/**
 * HostingProvider half of the Cloudways module. Unlike WP Engine/Kinsta
 * (which needed brand-new per-site SSH transports), Cloudways sites DO have
 * a real servers row — hostname/ssh_user/ssh_password/provider_id, exactly
 * like SpinupWP — so this reuses the EXISTING SshCommandRunner and
 * App\Services\Companion\CompanionInstaller directly rather than building
 * new transport code. This mirrors SpinupWpHostingProvider's shape.
 *
 * ASSUMPTION FLAG (needs verification against a real Cloudways account):
 * App\Services\Companion\CompanionInstaller's SSH-based install script
 * assumes the SpinupWP model of "one root/sudo-capable SSH login per
 * server, then `sudo -u {site_user}` to drop privileges per site"
 * (Site::site_user + Server::ssh_password, see that class's
 * runAsSiteUser()). Cloudways' actual SSH model is NOT confirmed here:
 * Cloudways exposes SSH access per-*application* via a per-app "Master
 * Credentials" user (commonly named after the app, e.g. `master_xxxxx`),
 * not a single root login per server with sudo to arbitrary per-app users.
 * Whether Cloudways' master-user account can actually `sudo -u
 * {other_site_user}` to reach a sibling app's files on the same server —
 * or whether it's sandboxed per-app with no cross-app sudo at all — is
 * unknown without testing against a live account. If Cloudways sandboxes
 * per-app SSH (the more likely shape, given app isolation is one of its
 * selling points), the existing CompanionInstaller will fail for any
 * Cloudways site whose site_user doesn't match the SSH login's own app,
 * and a Cloudways-specific companion installer (or a per-app credential
 * model rather than one shared Server::ssh_password) would be needed
 * instead. Populating Site::site_user and Server::ssh_password correctly
 * for Cloudways sites is a prerequisite this hosting-provider adapter
 * assumes but does not itself solve.
 */
class CloudwaysHostingProvider implements HostingProvider
{
    public function __construct(
        private readonly CloudwaysClient $client,
        private readonly SshCommandRunner $sshCommandRunner,
        private readonly SshCompanionInstaller $companionInstaller,
    ) {}

    public function id(): string
    {
        return Site::HOSTING_PROVIDER_CLOUDWAYS;
    }

    public function label(): string
    {
        return 'Cloudways';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function supports(string $capability): bool
    {
        return match ($capability) {
            self::CAP_SSH,
            self::CAP_COMPANION => ! $this->client->isViewOnly(),
            self::CAP_SERVER_LINKAGE,
            self::CAP_CERT_SYNC,
            self::CAP_ORPHAN_DETECTION => true,
            // Cloudways has no built-in performance-scan engine exposed via
            // its API — RunPerformanceScans falls back to the GTmetrix/PSI
            // chain for these sites, same as SpinupWP.
            self::CAP_PERFORMANCE_SCAN => false,
            default => false,
        };
    }

    public function commandRunner(): ?SiteCommandRunner
    {
        return $this->client->isViewOnly() ? null : $this->sshCommandRunner;
    }

    public function companionInstaller(): ?CompanionInstaller
    {
        return $this->client->isViewOnly() ? null : $this->companionInstaller;
    }

    public function backupRelayAdapter(): ?BackupRelayAdapter
    {
        return null;
    }

    public function panelUrl(Site $site): ?string
    {
        // Cloudways' real per-app dashboard URL pattern (something under
        // https://platform.cloudways.com/apps/{app_id}, unconfirmed) isn't
        // established here with enough confidence to return it — a wrong
        // guessed URL is worse than no link, same reasoning
        // SpinupWpHostingProvider::panelUrl() uses.
        return null;
    }
}
