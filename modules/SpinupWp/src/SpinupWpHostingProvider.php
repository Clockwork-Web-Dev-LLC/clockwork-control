<?php

namespace Modules\SpinupWp;

use App\Models\Site;
use App\Services\Companion\CompanionInstaller as SshCompanionInstaller;
use App\Services\Ssh\SshCommandRunner;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\CompanionInstaller;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SiteCommandRunner;

/**
 * HostingProvider adapter over SpinupWpClient. Built in-place in Phase 5
 * (before this module existed) to prove the HostingProvider contract
 * against a second, differently-shaped provider; moved into modules/ here
 * in Phase 6 once that was proven.
 */
class SpinupWpHostingProvider implements HostingProvider
{
    public function __construct(
        private readonly SpinupWpClient $client,
        private readonly SshCommandRunner $sshCommandRunner,
        private readonly SshCompanionInstaller $companionInstaller,
        private readonly SpinupWpBackupRelayAdapter $backupRelayAdapter,
    ) {}

    public function id(): string
    {
        return Site::HOSTING_PROVIDER_SPINUPWP;
    }

    public function label(): string
    {
        return 'SpinupWP';
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
            self::CAP_ORPHAN_DETECTION,
            self::CAP_BACKUP_RELAY => true,
            // SpinupWp has no built-in performance-scan engine — RunPerformanceScans
            // falls back to the GTmetrix/PSI chain for these sites.
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
        return $this->backupRelayAdapter;
    }

    public function panelUrl(Site $site): ?string
    {
        // No confirmed SpinupWP per-site dashboard URL pattern found in this
        // codebase or its docs — returning a guessed URL would be worse than
        // no link at all.
        return null;
    }
}
