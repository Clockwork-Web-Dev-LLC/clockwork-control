<?php

namespace Modules\GridPane;

use App\Models\Site;
use App\Services\Companion\CompanionInstaller as SshCompanionInstaller;
use App\Services\Ssh\SshCommandRunner;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\CompanionInstaller;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SiteCommandRunner;

class GridPaneHostingProvider implements HostingProvider
{
    public function __construct(
        private readonly GridPaneClient $client,
        private readonly SshCommandRunner $sshCommandRunner,
        private readonly SshCompanionInstaller $companionInstaller,
    ) {}

    public function id(): string
    {
        return Site::HOSTING_PROVIDER_GRIDPANE;
    }

    public function label(): string
    {
        return 'GridPane';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function supports(string $capability): bool
    {
        if ($this->client->isViewOnly()) {
            return match ($capability) {
                self::CAP_SERVER_LINKAGE,
                self::CAP_CERT_SYNC,
                self::CAP_ORPHAN_DETECTION => true,
                self::CAP_SSH,
                self::CAP_COMPANION,
                self::CAP_PERFORMANCE_SCAN => false,
                default => false,
            };
        }

        return match ($capability) {
            self::CAP_SSH,
            self::CAP_SERVER_LINKAGE,
            self::CAP_CERT_SYNC,
            self::CAP_ORPHAN_DETECTION,
            self::CAP_COMPANION => true,
            self::CAP_PERFORMANCE_SCAN => false,
            default => false,
        };
    }

    public function commandRunner(): ?SiteCommandRunner
    {
        if ($this->client->isViewOnly()) {
            return null;
        }

        return $this->sshCommandRunner;
    }

    public function companionInstaller(): ?CompanionInstaller
    {
        if ($this->client->isViewOnly()) {
            return null;
        }

        return $this->companionInstaller;
    }

    public function backupRelayAdapter(): ?BackupRelayAdapter
    {
        return null;
    }

    public function panelUrl(Site $site): ?string
    {
        return 'https://my.gridpane.com/sites';
    }
}
