<?php

namespace App\Services\Llar;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Sites\SiteMySqlClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull active lockouts from Gatekeeper (via Companion HMAC REST or native clockwork_lockouts table).
 *
 * Two transports — the puller dispatches per-site:
 *
 *   1. **Companion REST** (preferred). If the site has Clockwork Companion installed
 *      AND it advertises the `gatekeeper` or `lockouts` capability, pull via signed HTTPS
 *      to /wp-json/clockwork/v1/lockouts. No SSH, no DB creds.
 *   2. **Native Gatekeeper MySQL table** (fallback for SpinupWP/SSH sites where DB creds exist).
 *      Reads `<prefix>clockwork_lockouts` directly.
 */
class LlarLockoutPuller
{
    public function __construct(private readonly SiteMySqlClient $mysql) {}

    /**
     * @return array<int, array{ip: string, unlock_at: ?Carbon, source_table: string}>
     */
    public function activeLockouts(Site $site): array
    {
        if (! $site->is_wordpress) {
            return [];
        }

        // Gatekeeper native: REST GET /lockouts is primary. If REST fails on a site with
        // direct MySQL credentials (SpinupWP), fall back directly to clockwork_lockouts.
        if ($this->companionAdvertisesGatekeeper($site) || $this->companionAdvertisesLockouts($site)) {
            try {
                return (new ClockworkCompanionClient($site))->lockouts();
            } catch (Throwable $e) {
                Log::warning('gatekeeper.lockouts.companion_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);

                if ($site->db_name && $site->db_user && $site->db_password) {
                    return $this->fromGatekeeperTable($site, ($site->table_prefix ?: 'wp_').'clockwork_lockouts');
                }

                return [];
            }
        }

        if (! $site->db_name || ! $site->db_user || ! $site->db_password) {
            return [];
        }

        $tableName = ($site->table_prefix ?: 'wp_').'clockwork_lockouts';

        return $this->fromGatekeeperTable($site, $tableName);
    }

    /**
     * @return array<int, array{ip: string, unlock_at: ?Carbon, source_table: string}>
     */
    private function fromGatekeeperTable(Site $site, string $tableName): array
    {
        try {
            $exists = $this->mysql->listTables($site, $tableName);
            if ($exists === [] || ! in_array($tableName, $exists, true)) {
                return [];
            }
        } catch (Throwable $e) {
            Log::warning('gatekeeper.lockouts.table_probe_failed', [
                'site' => $site->domain,
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $sql = "SELECT ip, unlock_at FROM `{$tableName}` "
            .'WHERE unlock_at IS NOT NULL AND unlock_at > UTC_TIMESTAMP()';

        try {
            $rows = $this->mysql->query($site, $sql);
        } catch (Throwable $e) {
            Log::warning('gatekeeper.lockouts.table_query_failed', [
                'site' => $site->domain,
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $ip = trim((string) ($row['ip'] ?? ''));
            if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $unlock = isset($row['unlock_at']) && $row['unlock_at']
                ? Carbon::parse($row['unlock_at'], 'UTC')
                : null;
            $out[] = [
                'ip' => $ip,
                'unlock_at' => $unlock,
                'source_table' => 'clockwork_lockouts',
            ];
        }

        return $out;
    }

    private function companionAdvertisesGatekeeper(Site $site): bool
    {
        if (! $site->companion_installed || ! $site->companion_secret) {
            return false;
        }

        $caps = $site->companion_capabilities;
        if (! is_array($caps)) {
            return false;
        }

        return in_array('gatekeeper', $caps, true);
    }

    private function companionAdvertisesLockouts(Site $site): bool
    {
        if (! $site->companion_installed || ! $site->companion_secret) {
            return false;
        }

        $caps = $site->companion_capabilities;
        if (! is_array($caps)) {
            return false;
        }

        return in_array('lockouts', $caps, true);
    }
}
