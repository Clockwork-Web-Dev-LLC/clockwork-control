<?php

namespace App\Services\HostingProvider;

use App\Models\Site;
use Modules\BackupRelay\Services\CompanionBackupRelayAdapter;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\CompanionInstaller;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SiteCommandRunner;

/**
 * Hosting provider adapter for standalone WordPress sites managed purely
 * via the Clockwork Companion plugin (e.g. client-owned WP Engine, Kinsta,
 * SiteGround, or custom hosting where Clockwork has no hosting provider API
 * or SSH access).
 *
 * All monitoring and management operates over public HTTPS (uptime, live TLS
 * cert probes, domain RDAP) and HMAC-SHA256 signed Companion REST calls
 * (/snapshot, /plugins/update, /malware-scan, /admins, /sso/magic-link, etc.).
 */
class CustomHostingProvider implements HostingProvider
{
    public function id(): string
    {
        return Site::HOSTING_PROVIDER_CUSTOM;
    }

    public function label(): string
    {
        return 'Custom / Standalone';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supports(string $capability): bool
    {
        return match ($capability) {
            // No server row, no direct SSH gateway.
            self::CAP_SSH => false,
            self::CAP_SERVER_LINKAGE => false,
            // Uses live TLS handshake (LiveCertProbe) instead of provider API sync.
            self::CAP_CERT_SYNC => false,
            // Fallback to GTmetrix / PageSpeed Insights public scanning.
            self::CAP_PERFORMANCE_SCAN => false,
            // No host API to reconcile site existence against.
            self::CAP_ORPHAN_DETECTION => false,
            // Full Companion REST capability is supported!
            self::CAP_COMPANION => true,
            // Off-site S3 Glacier Instant Retrieval streaming supported via Companion!
            self::CAP_BACKUP_RELAY => true,
            default => false,
        };
    }

    public function commandRunner(): ?SiteCommandRunner
    {
        return null;
    }

    public function companionInstaller(): ?CompanionInstaller
    {
        return null;
    }

    public function backupRelayAdapter(): ?BackupRelayAdapter
    {
        return app(CompanionBackupRelayAdapter::class);
    }

    public function panelUrl(Site $site): ?string
    {
        return null;
    }
}
