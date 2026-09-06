<?php

namespace Modules\Core\Contracts;

use App\Models\Site;

/**
 * A WordPress hosting backend (SpinupWp, Pressable, ...), keyed on
 * sites.hosting_provider. Unlike CloudProvider (every method applies to
 * every registered IaaS provider), hosting capabilities genuinely differ —
 * Pressable has no server/SSH concept at all, SpinupWp has no built-in
 * performance-scan engine — so most behavior is gated through supports()
 * rather than every provider implementing every method meaningfully.
 */
interface HostingProvider
{
    /** SSH access to the underlying server exists (server_id is populated, ssh/wp-cli commands work). */
    public const CAP_SSH = 'ssh';

    /** Sites of this provider are always linked to a servers row — server_id is never null. */
    public const CAP_SERVER_LINKAGE = 'server_linkage';

    /** This provider has its own SSL certificate API/state to sync from (vs. falling back to a live TLS probe). */
    public const CAP_CERT_SYNC = 'cert_sync';

    /** This provider exposes its own native performance-scan engine (bypass the GTmetrix/PSI fallback chain). */
    public const CAP_PERFORMANCE_SCAN = 'performance_scan';

    /** Sites can be compared against this provider's own site list to detect ones no longer tracked there. */
    public const CAP_ORPHAN_DETECTION = 'orphan_detection';

    /** Companion mu-plugin can be installed/updated on this provider's sites. */
    public const CAP_COMPANION = 'companion';

    /** This provider supports remote backup streaming and relay archival. */
    public const CAP_BACKUP_RELAY = 'backup_relay';

    public function id(): string;

    public function label(): string;

    public function isConfigured(): bool;

    public function supports(string $capability): bool;

    /**
     * Null when this provider has no command-execution transport at all
     * (there is none today, but the contract doesn't assume one exists).
     */
    public function commandRunner(): ?SiteCommandRunner;

    /**
     * Null when this provider has no way to install the Companion
     * mu-plugin at all (there is none today — both real providers support
     * it via CAP_COMPANION — but the contract doesn't assume one exists).
     */
    public function companionInstaller(): ?CompanionInstaller;

    /**
     * Backup relay adapter for streaming backups to Glacier/S3, or null if unsupported.
     */
    public function backupRelayAdapter(): ?BackupRelayAdapter;

    /**
     * Deep link into this provider's own control panel for the site, or
     * null if none applies.
     */
    public function panelUrl(Site $site): ?string;
}
