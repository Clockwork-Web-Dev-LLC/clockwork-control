<?php

namespace Modules\Pressable;

use App\Models\Site;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\CompanionInstaller;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SiteCommandRunner;

class PressableHostingProvider implements HostingProvider
{
    public function __construct(
        private readonly PressableClient $client,
        private readonly PressableApiCommandRunner $commandRunner,
        private readonly PressableCompanionInstaller $companionInstaller,
        private readonly PressableBackupRelayAdapter $backupRelayAdapter,
    ) {}

    public function id(): string
    {
        return Site::HOSTING_PROVIDER_PRESSABLE;
    }

    public function label(): string
    {
        return 'Pressable';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function supports(string $capability): bool
    {
        return match ($capability) {
            // Native Lighthouse scan engine (PressableLighthouseClient) and
            // the site-import sweep (ImportPressable) both exist today.
            self::CAP_PERFORMANCE_SCAN,
            self::CAP_ORPHAN_DETECTION,
            self::CAP_BACKUP_RELAY => true,
            self::CAP_COMPANION => ! $this->client->isViewOnly(),
            // No SSH, no servers row, and no per-site cert API used today —
            // SslChecker falls back to a live TLS probe for these sites.
            self::CAP_SSH,
            self::CAP_SERVER_LINKAGE,
            self::CAP_CERT_SYNC => false,
            default => false,
        };
    }

    public function commandRunner(): ?SiteCommandRunner
    {
        return $this->client->isViewOnly() ? null : $this->commandRunner;
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
        // No confirmed Pressable per-site dashboard URL pattern found in
        // this codebase or its docs — returning a guessed URL would be
        // worse than no link at all.
        return null;
    }
}
