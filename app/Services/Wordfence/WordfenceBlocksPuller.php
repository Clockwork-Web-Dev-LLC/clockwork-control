<?php

namespace App\Services\Wordfence;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Sites\SiteMySqlClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull active IP blocks from each site's Wordfence install.
 *
 * Two transports — the puller dispatches per-site:
 *
 *   1. **Companion REST** (preferred when available). If the site has the Clockwork
 *      Companion mu-plugin installed AND it advertises the `wordfence-blocks`
 *      capability, pull via signed HTTPS to /wp-json/clockwork/v1/wordfence-blocks.
 *   2. **SSH + MySQL** (fallback for everything else, AND for Companion errors).
 *      Wordfence stores blocks in `<prefix>wfBlocks7` (modern versions) with the IP
 *      packed as BINARY(16) — we use INET6_NTOA() to convert. We skip country-block
 *      rows (`type = 'cbl'`) and pattern blocks (`type = 'pat'`).
 *
 * Companion failure → SQL fallback so a misbehaving REST endpoint never causes
 * silent data loss in the review queue.
 *
 * IP-targeting types we care about (per Wordfence's wfBlock.php):
 *   brute, lockout, lockout_logged_in, throttle, wfsn-temporary, wfsn-permanent, manual
 */
class WordfenceBlocksPuller
{
    private const IP_TYPES = [
        'brute',
        'lockout',
        'lockout_logged_in',
        'throttle',
        'wfsn-temporary',
        'wfsn-permanent',
        'manual',
    ];

    public function __construct(private readonly SiteMySqlClient $mysql) {}

    /**
     * @return array<int, array{ip: string, expires_at: ?Carbon, source_table: string, reason: ?string, type: ?string}>
     */
    public function activeBlocks(Site $site): array
    {
        if (! $site->is_wordpress) {
            return [];
        }

        if ($this->companionAdvertisesBlocks($site)) {
            try {
                return (new ClockworkCompanionClient($site))->wordfenceBlocks();
            } catch (Throwable $e) {
                Log::warning('wordfence.blocks.companion_failed_falling_back_to_sql', [
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
        $table = $prefix.'wfBlocks7';

        try {
            $tables = $this->mysql->listTables($site, $table);
        } catch (Throwable $e) {
            Log::warning('wordfence.blocks.table_probe_failed', [
                'site' => $site->domain,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! in_array($table, $tables, true)) {
            return []; // Wordfence isn't installed (or is older than wfBlocks7).
        }

        $typeList = "'".implode("','", self::IP_TYPES)."'";
        $sql = 'SELECT INET6_NTOA(IP) AS ip, type, reason, expiration '
            ."FROM `{$table}` "
            ."WHERE type IN ({$typeList}) "
            .'AND (expiration = 0 OR expiration > UNIX_TIMESTAMP())';

        try {
            $rows = $this->mysql->query($site, $sql);
        } catch (Throwable $e) {
            Log::warning('wordfence.blocks.query_failed', [
                'site' => $site->domain,
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $ip = trim((string) ($row['ip'] ?? ''));
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $expiration = (int) ($row['expiration'] ?? 0);
            $expiresAt = $expiration > 0 ? Carbon::createFromTimestamp($expiration) : null;

            $out[] = [
                'ip' => $ip,
                'expires_at' => $expiresAt,
                'source_table' => $table,
                'reason' => isset($row['reason']) ? (string) $row['reason'] : null,
                'type' => isset($row['type']) ? (string) $row['type'] : null,
            ];
        }

        return $out;
    }

    private function companionAdvertisesBlocks(Site $site): bool
    {
        if (! $site->companion_installed || ! $site->companion_secret) {
            return false;
        }

        $caps = $site->companion_capabilities;
        if (! is_array($caps)) {
            return false;
        }

        return in_array('wordfence-blocks', $caps, true);
    }
}
