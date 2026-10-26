<?php

namespace Modules\TurnkeyWp;

use App\Models\Site;
use App\Services\Companion\CompanionInstaller as SshCompanionInstaller;
use App\Services\Ssh\SshCommandRunner;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\CompanionInstaller;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SiteCommandRunner;

/**
 * HostingProvider adapter over TurnkeyWpClient. TurnkeyWP servers are real
 * Ubuntu boxes with the same on-disk layout SpinupWP uses
 * (/sites/<domain>/files), reached over SSH as the server's `turnkey` sudo
 * user — see clockwork:turnkeywp-authorize-ssh for granting that access.
 */
class TurnkeyWpHostingProvider implements HostingProvider
{
    public function __construct(
        private readonly TurnkeyWpClient $client,
        private readonly SshCommandRunner $sshCommandRunner,
        private readonly SshCompanionInstaller $companionInstaller,
    ) {}

    public function id(): string
    {
        return Site::HOSTING_PROVIDER_TURNKEYWP;
    }

    public function label(): string
    {
        return 'TurnkeyWP';
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
            self::CAP_SERVER_LINKAGE => true,
            // Certs are tracked by the live TLS probe (cert_source live_probe):
            // the site payload has an expiry date but no real renewal date.
            // OrphanSiteFinder only knows SpinupWP today; the import still
            // nulls turnkeywp_site_id for sites TurnkeyWP no longer lists.
            self::CAP_CERT_SYNC,
            self::CAP_ORPHAN_DETECTION,
            self::CAP_BACKUP_RELAY,
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
        $serverId = $site->server?->turnkeywp_server_id;
        if (empty($site->turnkeywp_site_id) || empty($serverId)) {
            return null;
        }

        // The API base is <app>/api/v1; the site page is <app>/servers/{server}/sites/{site}.
        $app = preg_replace('#/api/v1/?$#', '', $this->client->baseUrl());

        return rtrim((string) $app, '/')."/servers/{$serverId}/sites/{$site->turnkeywp_site_id}";
    }
}
