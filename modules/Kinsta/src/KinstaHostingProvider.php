<?php

namespace Modules\Kinsta;

use App\Models\Site;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\CompanionInstaller;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SiteCommandRunner;

/**
 * Kinsta: managed WordPress hosting with real per-environment SSH but no
 * server concept at all (sites.server_id is always null, same shape as
 * Pressable and WP Engine) — see Site::isKinsta() /
 * Site::HOSTING_PROVIDERS_WITHOUT_SERVER.
 */
class KinstaHostingProvider implements HostingProvider
{
    public function __construct(
        private readonly KinstaClient $client,
        private readonly KinstaSshCommandRunner $commandRunner,
        private readonly KinstaCompanionInstaller $companionInstaller,
    ) {}

    public function id(): string
    {
        return Site::HOSTING_PROVIDER_KINSTA;
    }

    public function label(): string
    {
        return 'Kinsta';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function supports(string $capability): bool
    {
        return match ($capability) {
            self::CAP_ORPHAN_DETECTION => true,
            self::CAP_COMPANION => ! $this->client->isViewOnly(),
            // CAP_SSH's own contract docblock ties it specifically to
            // server_id being populated ("SSH access to the underlying
            // server... server_id is populated"). Kinsta has real
            // per-environment SSH (KinstaSshCommandRunner), but — like WP
            // Engine, and same shape as Pressable's site-scoped API — never
            // links a site to a servers row (kinsta_environment_id addresses
            // the environment directly), so CAP_SSH is false here despite
            // SSH genuinely working. Sites without CAP_SSH dispatch through
            // WpCoreChecksumVerifier::verifyViaCommandRunner() and hide the
            // Traffic/Bans tabs (SitesController::show()) — both correct for
            // Kinsta, since neither reads from a linked Server row.
            self::CAP_SSH,
            // No servers row ever exists for a Kinsta site.
            self::CAP_SERVER_LINKAGE,
            // No Kinsta cert-status endpoint found in research — SslChecker
            // falls back to a live TLS probe for these sites automatically.
            self::CAP_CERT_SYNC,
            // No native performance-scan engine confirmed for Kinsta's API
            // (unlike Pressable's Lighthouse endpoint) — falls back to the
            // app's existing GTmetrix/PSI chain.
            self::CAP_PERFORMANCE_SCAN => false,
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
        return null;
    }

    public function panelUrl(Site $site): ?string
    {
        // No confirmed MyKinsta per-environment dashboard URL pattern —
        // returning a guessed URL would be worse than no link at all, same
        // reasoning as PressableHostingProvider::panelUrl().
        return null;
    }
}
