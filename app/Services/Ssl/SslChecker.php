<?php

namespace App\Services\Ssl;

use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use Modules\Core\Contracts\HostingProvider;

class SslChecker
{
    public function __construct(
        private readonly ChatNotifier $mattermost,
        private readonly SiteCertRefresher $refresher,
        private readonly LiveCertProbe $liveCertProbe,
    ) {}

    /**
     * Re-evaluate SSL state for every non-ignored site.
     *
     * For any site whose computed state is non-green, we re-pull cert data from the
     * per-site SpinupWP endpoint before evaluating — LE auto-renewals are picked up
     * on the first check run after cert_renews_at passes, not the second, so sites
     * never show a false "Renewal needed" alert for a cert that already renewed.
     *
     * Sites whose HostingProvider doesn't support CAP_CERT_SYNC (Pressable
     * today, or any future non-cert-syncing provider) have no equivalent
     * per-site cert API to call — LiveCertProbe reads the cert straight off
     * the wire via a TLS handshake instead. Run every cycle (not gated on
     * non-green like the sync-capable path above) since it's the *only*
     * source of cert data these sites have; without it they'd never get past
     * cert_source=none. Skipped for sites with a manual cert_source override
     * (external/redirect_only) so an operator's explicit choice isn't clobbered.
     *
     * Detects state transitions vs. the stored `cert_state`, persists the new state,
     * and posts to Mattermost on transitions only — not every run.
     *
     * @param  bool  $silent  if true, persists state but skips notifications (for initial backfill).
     * @return array{checked:int, transitioned:int, notified:int, refreshed:int}
     */
    public function run(bool $silent = false): array
    {
        $checked = 0;
        $transitioned = 0;
        $notified = 0;
        $refreshed = 0;

        Site::query()
            ->with('server:id,name,is_ignored')
            ->hostMonitored()
            ->chunkById(200, function ($sites) use (&$checked, &$transitioned, &$notified, &$refreshed, $silent) {
                foreach ($sites as $site) {
                    $checked++;

                    // Compute the state from current (possibly stale) DB data first.
                    // If it's non-green, refresh from the provider before committing —
                    // this catches LE auto-renewals on the *first* run after
                    // cert_renews_at passes rather than the second, eliminating false
                    // "Renewal needed" alerts that appeared for one full check cycle
                    // before clearing.
                    $computedState = $site->sslState();

                    if ($site->host()->supports(HostingProvider::CAP_CERT_SYNC)) {
                        if ($computedState !== Site::SSL_STATE_GREEN
                            && $computedState !== Site::SSL_STATE_NONE) {
                            try {
                                $this->refresher->refresh($site);
                                $site->refresh();
                                $refreshed++;
                            } catch (\Throwable) {
                                // Provider API unavailable, or (a site whose spinupwp_id
                                // was nulled by the orphan sweep since the last import)
                                // SiteCertRefresher throws for having no id to call with —
                                // either way, fall through to evaluate on stale data.
                            }
                        }
                    } elseif (! in_array($site->cert_source, [Site::CERT_SOURCE_EXTERNAL, Site::CERT_SOURCE_REDIRECT_ONLY], true)) {
                        $expiry = $this->liveCertProbe->expiryFor($site->domain);
                        if ($expiry !== null) {
                            $site->cert_source = Site::CERT_SOURCE_LIVE_PROBE;
                            $site->cert_expires_at = $expiry;
                            $site->cert_renews_at = null;
                            // Persist immediately — unlike the SpinupWP path, this is the
                            // ONLY place these sites' cert data ever gets refreshed, so it
                            // can't wait on the state-transition-gated save() below (that
                            // one no-ops via `continue` whenever the state bucket itself
                            // hasn't changed, which would otherwise freeze cert_expires_at
                            // at whatever the very first probe returned).
                            if ($site->isDirty(['cert_source', 'cert_expires_at', 'cert_renews_at'])) {
                                $site->save();
                            }
                            $refreshed++;
                        }
                    }

                    $newState = $site->sslState();
                    $oldState = $site->cert_state;

                    if ($oldState === $newState) {
                        continue;
                    }

                    $site->cert_state = $newState;
                    $site->cert_state_changed_at = now();
                    $site->save();
                    $transitioned++;

                    // Skip notifications on first-ever assignment (oldState = null) and on backfill.
                    if ($silent || $oldState === null) {
                        continue;
                    }

                    if ($this->mattermost->sslStateChanged($site, $oldState, $newState)) {
                        $notified++;
                    }
                }
            });

        return ['checked' => $checked, 'transitioned' => $transitioned, 'notified' => $notified, 'refreshed' => $refreshed];
    }
}
