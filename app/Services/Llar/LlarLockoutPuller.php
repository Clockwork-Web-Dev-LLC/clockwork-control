<?php

namespace App\Services\Llar;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Sites\SiteMySqlClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull active lockouts from a site's "Limit Login Attempts Reloaded" plugin data.
 *
 * Two transports — the puller dispatches per-site:
 *
 *   1. **Companion REST** (preferred when available). If the site has the Clockwork
 *      Companion mu-plugin installed AND it advertises the `lockouts` capability,
 *      pull via signed HTTPS to /wp-json/clockwork/v1/lockouts. No SSH, no DB creds.
 *   2. **SSH + MySQL** (fallback for everything else, AND for Companion errors).
 *      LLAR stores lockouts in two places depending on plugin version:
 *        a. `<prefix>limit_login_lockouts` table (v2.x+).
 *        b. The `<prefix>options` row `limit_login_lockouts` (serialized array, v1.x).
 *      Try the table first, fall back to the option row, merge by IP.
 *
 * Companion failure → SQL fallback so a misbehaving REST endpoint never causes
 * silent data loss in the review queue. The fallback is logged for observability.
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

        if ($this->companionAdvertisesLockouts($site)) {
            try {
                return (new ClockworkCompanionClient($site))->lockouts();
            } catch (Throwable $e) {
                Log::warning('llar.lockouts.companion_failed_falling_back_to_sql', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                // fall through to SSH+SQL
            }
        }

        if (! $site->db_name || ! $site->db_user || ! $site->db_password) {
            return [];
        }

        $prefix = $site->table_prefix ?: 'wp_';
        // We deliberately don't quote-identifier the prefix — LLAR's own SQL doesn't either,
        // and the prefix is sourced from SpinupWP's API (we control its shape upstream).
        $tableName = $prefix.'limit_login_lockouts';
        $optionsTable = $prefix.'options';

        $byIp = [];

        foreach ($this->fromTable($site, $tableName) as $row) {
            $byIp[$row['ip']] = $row;
        }

        // Option-row lookups override the table only when the table didn't already supply
        // the IP (table is more authoritative on newer plugin versions).
        foreach ($this->fromOptionRow($site, $optionsTable) as $row) {
            $byIp[$row['ip']] ??= $row;
        }

        return array_values($byIp);
    }

    /**
     * @return array<int, array{ip: string, unlock_at: ?Carbon, source_table: string}>
     */
    private function fromTable(Site $site, string $tableName): array
    {
        // SHOW TABLES LIKE first so we don't log a noisy "table doesn't exist" error
        // for every site that doesn't have LLAR.
        try {
            $exists = $this->mysql->listTables($site, $tableName);
            if ($exists === [] || ! in_array($tableName, $exists, true)) {
                return [];
            }
        } catch (Throwable $e) {
            Log::warning('llar.lockouts.table_probe_failed', [
                'site' => $site->domain,
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        // Schemas seen in the wild:
        //   v2.x:   id, ip, lockout_start, lockout_end, reason
        //   v1.x:   ip, unlock (unix timestamp)
        // Try the modern shape first, then fall back.
        $sql = "SELECT ip, lockout_end AS unlock_at FROM `{$tableName}` "
            .'WHERE lockout_end IS NULL OR lockout_end > NOW()';

        try {
            $rows = $this->mysql->query($site, $sql);
        } catch (Throwable $e) {
            // Try the v1.x shape.
            $sql = "SELECT ip, FROM_UNIXTIME(unlock) AS unlock_at FROM `{$tableName}` "
                .'WHERE unlock IS NULL OR unlock > UNIX_TIMESTAMP()';
            try {
                $rows = $this->mysql->query($site, $sql);
            } catch (Throwable $e2) {
                Log::warning('llar.lockouts.table_query_failed', [
                    'site' => $site->domain,
                    'table' => $tableName,
                    'error' => $e2->getMessage(),
                ]);

                return [];
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $ip = trim($row['ip'] ?? '');
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $unlock = isset($row['unlock_at']) && $row['unlock_at']
                ? Carbon::parse($row['unlock_at'])
                : null;
            $out[] = [
                'ip' => $ip,
                'unlock_at' => $unlock,
                'source_table' => $tableName,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{ip: string, unlock_at: ?Carbon, source_table: string}>
     */
    private function fromOptionRow(Site $site, string $optionsTable): array
    {
        $sql = "SELECT option_value FROM `{$optionsTable}` "
            ."WHERE option_name = 'limit_login_lockouts' LIMIT 1";

        try {
            $rows = $this->mysql->query($site, $sql);
        } catch (Throwable $e) {
            Log::warning('llar.lockouts.option_query_failed', [
                'site' => $site->domain,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($rows === [] || empty($rows[0]['option_value'])) {
            return [];
        }

        $raw = $rows[0]['option_value'];

        // The mysql -B output replaces newlines inside cells with literal "\n", and tabs
        // with "\t". LLAR's option_value is a PHP-serialized string, which can't legally
        // contain raw newlines/tabs — but we still defend against the encoded form.
        $raw = str_replace(['\\n', '\\t', '\\r'], ["\n", "\t", "\r"], $raw);

        $value = @unserialize($raw, ['allowed_classes' => false]);
        if (! is_array($value)) {
            return [];
        }

        $now = time();
        $out = [];
        foreach ($value as $ip => $unlockTs) {
            $ip = trim((string) $ip);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $unlockTs = (int) $unlockTs;
            if ($unlockTs > 0 && $unlockTs <= $now) {
                continue; // expired
            }
            $out[] = [
                'ip' => $ip,
                'unlock_at' => $unlockTs > 0 ? Carbon::createFromTimestamp($unlockTs) : null,
                'source_table' => $optionsTable.':limit_login_lockouts',
            ];
        }

        return $out;
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
