<?php

namespace App\Services\Ssl;

use App\Models\Site;
use Modules\SpinupWp\SpinupWpClient;
use RuntimeException;

class SiteCertRefresher
{
    public function __construct(private readonly SpinupWpClient $spinupwp) {}

    /**
     * Refresh a single site's cert info from SpinupWP.
     *
     * @return array{from_state: ?string, to_state: string, expires_at: ?string, renews_at: ?string}
     */
    public function refresh(Site $site): array
    {
        if (! $site->spinupwp_id) {
            throw new RuntimeException(
                'Site has no SpinupWP ID. Run the next nightly import (or `php artisan clockwork:import-spinupwp`) to backfill it.'
            );
        }

        if (! $this->spinupwp->isConfigured()) {
            throw new RuntimeException('SpinupWP API token is not configured.');
        }

        $row = $this->spinupwp->site($site->spinupwp_id);

        $httpsEnabled = (bool) ($row['https']['enabled'] ?? false);
        $expires = $row['https']['certificate_expires'] ?? null;
        $renews = $row['https']['certificate_renews'] ?? null;

        $previousState = $site->cert_state;

        // Don't stomp user-set source overrides (external, redirect_only).
        $userOverrides = [Site::CERT_SOURCE_EXTERNAL, Site::CERT_SOURCE_REDIRECT_ONLY];
        if (! in_array($site->cert_source, $userOverrides, true)) {
            $site->cert_source = $httpsEnabled ? Site::CERT_SOURCE_SPINUPWP_LE : Site::CERT_SOURCE_NONE;
        }

        $site->cert_expires_at = $httpsEnabled && $expires ? $expires : null;
        $site->cert_renews_at = $httpsEnabled && $renews ? $renews : null;
        $site->save();

        $newState = $site->fresh()->sslState();
        $site->update([
            'cert_state' => $newState,
            'cert_state_changed_at' => $previousState !== $newState ? now() : $site->cert_state_changed_at,
        ]);

        return [
            'from_state' => $previousState,
            'to_state' => $newState,
            'expires_at' => $site->cert_expires_at?->toDateTimeString(),
            'renews_at' => $site->cert_renews_at?->toDateTimeString(),
        ];
    }
}
