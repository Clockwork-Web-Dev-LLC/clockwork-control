<?php

namespace App\Services\Domains;

use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Carbon;

class DomainExpirationChecker
{
    public function __construct(
        private readonly ChatNotifier $notifier,
        private readonly RdapClient $client,
    ) {}

    /**
     * Check domain expirations across the monitored fleet.
     *
     * @param  bool  $silent  If true, suppresses chat notifications (used during initial backfills).
     * @param  int|null  $siteId  Optional single-site filter for on-demand checks.
     * @param  bool  $force  If true, bypasses cadence guards.
     * @return array{checked: int, transitioned: int, notified: int, skipped: int}
     */
    public function run(bool $silent = false, ?int $siteId = null, bool $force = false): array
    {
        $checked = 0;
        $transitioned = 0;
        $notified = 0;
        $skipped = 0;

        $query = Site::query()
            ->with('server:id,name,is_ignored')
            ->whereNotNull('domain')
            ->where('is_inactive', false)
            ->hostMonitored();

        if ($siteId !== null) {
            $query->where('id', $siteId);
        }

        $query->chunkById(100, function ($sites) use (&$checked, &$transitioned, &$notified, &$skipped, $silent, $force) {
            foreach ($sites as $site) {
                if (! $this->shouldCheck($site, $force)) {
                    $skipped++;

                    continue;
                }

                $checked++;
                $this->checkSite($site, $silent, $transitioned, $notified);
            }
        });

        return [
            'checked' => $checked,
            'transitioned' => $transitioned,
            'notified' => $notified,
            'skipped' => $skipped,
        ];
    }

    /**
     * Check a single site and return whether state transitioned.
     */
    public function checkSite(Site $site, bool $silent, int &$transitioned = 0, int &$notified = 0): void
    {
        $result = $this->client->lookup($site->domain);

        if ($result !== null) {
            if ($result->isSuccessful()) {
                $site->domain_expires_at = $result->expiresAt;
                $site->domain_registrar = $result->registrar;
                $site->domain_rdap_status = $result->status;
                $site->domain_rdap_error = null;
            } else {
                $site->domain_rdap_error = $result->rawError;
            }
            $site->domain_rdap_checked_at = Carbon::now();
            $site->save();
        }

        $oldState = $site->domain_expiration_state ?? Site::DOMAIN_EXPIRATION_STATE_NONE;
        $newState = $site->domainExpirationState();

        if ($oldState !== $newState) {
            $site->domain_expiration_state = $newState;
            $site->domain_expiration_state_changed_at = Carbon::now();
            $site->save();
            $transitioned++;

            // Only alert on a transition INTO yellow/red — deliberately not
            // gated on $oldState being non-NONE, so a brand-new site whose
            // domain is already expiring on its very first check still
            // notifies (it used to silently skip this exact case, since
            // every new site's oldState starts at NONE). Silence is only
            // for explicit backfills ($silent) or transitions into green
            // (renewals/recoveries), which don't need an alert.
            if (! $silent) {
                if ($newState === Site::DOMAIN_EXPIRATION_STATE_YELLOW || $newState === Site::DOMAIN_EXPIRATION_STATE_RED) {
                    if ($this->notifier->domainExpirationStateChanged($site, $oldState, $newState)) {
                        $notified++;
                    }
                }
            }
        }
    }

    /**
     * Cadence guard:
     * - Unknown/none: check immediately.
     * - Yellow / Red: check daily (every 20+ hours).
     * - Green: check weekly (every 6+ days).
     */
    public function shouldCheck(Site $site, bool $force = false): bool
    {
        if ($force) {
            return true;
        }

        if ($site->domain_rdap_checked_at === null) {
            return true;
        }

        $state = $site->domain_expiration_state ?? Site::DOMAIN_EXPIRATION_STATE_NONE;
        $now = Carbon::now();

        if ($state === Site::DOMAIN_EXPIRATION_STATE_YELLOW || $state === Site::DOMAIN_EXPIRATION_STATE_RED) {
            return $site->domain_rdap_checked_at->diffInHours($now) >= 20;
        }

        if ($state === Site::DOMAIN_EXPIRATION_STATE_GREEN) {
            return $site->domain_rdap_checked_at->diffInDays($now) >= 6;
        }

        return $site->domain_rdap_checked_at->diffInHours($now) >= 24;
    }
}
