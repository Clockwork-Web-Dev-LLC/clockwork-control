<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Sites\SiteMySqlClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fleet-scale replacement for the manual "log into wp-admin → Clockwork →
 * Login Security → migrate" click-through, one user at a time, one site at
 * a time. Companion 1.29.6+ exposes POST /two-factor/migrate specifically
 * so the monitoring app can drive this instead — see
 * ClockworkCompanionClient::migrateTwoFactorUser().
 *
 * Per user: re-points their existing authenticator app entry at a Companion
 * TOTP secret (same underlying key) and deletes the WFLS DB row, so re-run
 * safety matters less than usual — but a failed migrate() call has already
 * committed for anyone before it in the loop, so this always reports a
 * per-user result rather than treating a site as all-or-nothing.
 *
 * GET /two-factor only ever lists administrator/editor users (Companion's
 * TwoFactorStatusRoute queries role__in => [administrator, editor]) even
 * though wfls_unmigrated_total counts every role. Confirmed live
 * 2026-09-09: two sites had leftover wfls_2fa_secrets rows for accounts
 * with no current role (departed-staff accounts) that the REST status
 * call never surfaced, leaving wfls_ready_to_remove stuck false forever.
 * discoverExtraWflsUsers() closes that gap with a direct DB read over SSH
 * — best-effort, since not every site has SSH/DB creds on file (e.g.
 * Pressable). It resolves the real WFLS table name via SHOW TABLES rather
 * than trusting sites.table_prefix, because a migrated/multisite install
 * can have a stale table_prefix (observed: "delete_" on a site whose WFLS
 * table was actually still under "wp_").
 */
class MigrateWflsTwoFactor extends Command
{
    protected $signature = 'clockwork:migrate-wfls-2fa
        {site? : Site id or domain — omit with --all to sweep every flagged site}
        {--all : Migrate every companion-equipped site with at least one WFLS-only user}
        {--dry-run : List migratable users per site without calling /two-factor/migrate}';

    protected $description = 'Migrate admin/editor 2FA from Wordfence Login Security to Companion 2FA, per site, via the Companion REST API.';

    public function handle(ActionLogger $logger, SiteMySqlClient $mysql): int
    {
        if (! $this->option('all') && $this->argument('site') === null) {
            $this->error('Pass a site (id or domain) or --all.');

            return self::INVALID;
        }

        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->warn('No matching companion-equipped sites found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totals = ['sites_touched' => 0, 'migrated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($sites as $site) {
            $client = new ClockworkCompanionClient($site);

            try {
                $status = $client->twoFactorStatus();
            } catch (Throwable $e) {
                $this->warn("  [fail] {$site->domain}: could not fetch /two-factor status — {$e->getMessage()}");

                continue;
            }

            if (! ($status['ok'] ?? false)) {
                $this->warn("  [fail] {$site->domain}: /two-factor responded without ok=true");

                continue;
            }

            $users = is_array($status['users'] ?? null) ? $status['users'] : [];
            $candidates = array_values(array_filter($users, fn ($u) => ($u['state'] ?? null) === 'wfls'));

            $knownIds = array_map(fn ($u) => (int) ($u['id'] ?? 0), $candidates);
            $extra = $this->discoverExtraWflsUsers($site, $mysql, $knownIds);
            if ($extra !== []) {
                $this->line('  + '.count($extra).' additional WFLS secret(s) found via direct DB check (no current admin/editor role): '.implode(', ', array_column($extra, 'login')));
                $candidates = array_merge($candidates, $extra);
            }

            if ($candidates === []) {
                continue;
            }

            $totals['sites_touched']++;
            $this->line("{$site->domain} — ".count($candidates).' user(s) still on WFLS 2FA:');

            foreach ($candidates as $user) {
                $login = (string) ($user['login'] ?? '?');
                $userId = (int) ($user['id'] ?? 0);

                if ($dryRun) {
                    $this->line("  [dry-run] would migrate {$login} (#{$userId})");

                    continue;
                }

                try {
                    $result = $client->migrateTwoFactorUser($userId);
                } catch (Throwable $e) {
                    $totals['failed']++;
                    $this->error("  [fail] {$login}: {$e->getMessage()}");

                    $logger->record(
                        actionType: ActionLog::TYPE_WFLS_2FA_MIGRATED,
                        summary: "WFLS→Companion 2FA migration failed (transport) for {$login} on {$site->domain}.",
                        site: $site,
                        target: $login,
                        ok: false,
                        error: $e->getMessage(),
                        actor: 'cli',
                    );

                    continue;
                }

                $migrated = (bool) ($result['migrated'] ?? false);
                if ($migrated) {
                    $totals['migrated']++;
                    $this->info("  [ok]   {$login} migrated to Companion 2FA.");
                } else {
                    // ok=true, migrated=false: WflsMigrator no-op'd — already
                    // enrolled in Companion or no WFLS secret found since the
                    // status call above (another migration ran concurrently).
                    $totals['skipped']++;
                    $this->line("  [skip] {$login}: nothing to migrate (already enrolled or no WFLS secret).");
                }

                $logger->record(
                    actionType: ActionLog::TYPE_WFLS_2FA_MIGRATED,
                    summary: $migrated
                        ? "Migrated {$login}'s 2FA from Wordfence Login Security to Companion on {$site->domain}."
                        : "WFLS→Companion 2FA migration no-op for {$login} on {$site->domain} (already migrated).",
                    site: $site,
                    target: $login,
                    details: $result,
                    ok: (bool) ($result['ok'] ?? false),
                    actor: 'cli',
                );
            }

            if (! $dryRun) {
                $this->refreshSnapshot($site, $client);
            }
        }

        if ($dryRun) {
            $this->info("Dry run complete — {$totals['sites_touched']} site(s) have WFLS-only users. Re-run without --dry-run to migrate.");
        } else {
            $this->info(sprintf(
                'Done — sites touched=%d migrated=%d skipped=%d failed=%d',
                $totals['sites_touched'],
                $totals['migrated'],
                $totals['skipped'],
                $totals['failed'],
            ));
        }

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Direct-DB backstop for users GET /two-factor can't see (see class
     * docblock). Finds the real wfls_2fa_secrets table via SHOW TABLES
     * (not sites.table_prefix, which can be stale) and returns every user
     * id with an 'authenticator' row that isn't already in $knownIds.
     * Best-effort: returns [] on any failure — no server/SSH linked, no DB
     * creds on file, connection error, or no WFLS table found — since this
     * is a supplementary check and the REST-derived candidates must still
     * go through regardless.
     *
     * @param  array<int, int>  $knownIds
     * @return array<int, array{id: int, login: string}>
     */
    private function discoverExtraWflsUsers(Site $site, SiteMySqlClient $mysql, array $knownIds): array
    {
        if (! $site->server) {
            return [];
        }

        try {
            $tables = $mysql->query($site, "SHOW TABLES LIKE '%wfls_2fa_secrets'");
        } catch (Throwable) {
            return [];
        }

        $wflsTable = (string) (array_values($tables[0] ?? [])[0] ?? '');
        if ($wflsTable === '' || ! str_ends_with($wflsTable, 'wfls_2fa_secrets')) {
            return [];
        }

        // Table names came straight from SHOW TABLES on this connection, so
        // they're already known-safe identifiers, not user-supplied —
        // backtick-quoted (with the identifier's own backticks doubled)
        // purely as standard practice.
        $basePrefix = substr($wflsTable, 0, -strlen('wfls_2fa_secrets'));
        $usersTable = $basePrefix.'users';
        $quote = fn (string $identifier) => '`'.str_replace('`', '``', $identifier).'`';

        try {
            $rows = $mysql->query($site, sprintf(
                "SELECT DISTINCT s.user_id, u.user_login FROM %s s LEFT JOIN %s u ON u.ID = s.user_id WHERE s.mode = 'authenticator'",
                $quote($wflsTable),
                $quote($usersTable),
            ));
        } catch (Throwable $e) {
            Log::warning('wfls_2fa_migration.db_discovery_failed', [
                'site' => $site->domain,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $extra = [];
        foreach ($rows as $row) {
            $id = (int) ($row['user_id'] ?? 0);
            if ($id === 0 || in_array($id, $knownIds, true)) {
                continue;
            }
            $extra[] = ['id' => $id, 'login' => (string) ($row['user_login'] ?? "user #{$id}")];
        }

        return $extra;
    }

    /**
     * Pull a fresh /snapshot so sites.companion_snapshot (and therefore the
     * Issues dashboard) reflects the migration immediately instead of
     * waiting for the 01:30 ET clockwork:refresh-companion-snapshot cron.
     * Best-effort — a failure here doesn't undo the migration above.
     */
    private function refreshSnapshot(Site $site, ClockworkCompanionClient $client): void
    {
        try {
            $payload = $client->snapshot();
        } catch (Throwable $e) {
            Log::warning('companion.snapshot.refresh_after_2fa_migration_failed', [
                'site' => $site->domain,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $site->forceFill([
            'companion_snapshot' => $payload,
            'companion_snapshot_at' => Carbon::now(),
            'companion_last_seen_at' => Carbon::now(),
        ])->save();
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret')
            ->hostMonitored();

        if ($needle = $this->argument('site')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', $needle)->orWhere('domain', $needle);
            });
        } else {
            // --all: narrow to sites the cached snapshot already flags as
            // having WFLS-only users, so we don't fire a live /two-factor
            // call at every companion-equipped site in the fleet. Sites
            // whose snapshot is stale or missing this field are still
            // covered by the daily refresh cron catching up separately.
            $q->whereRaw(
                'COALESCE('.
                "JSON_EXTRACT(companion_snapshot, '$.two_factor.wfls_unmigrated_total'), ".
                "JSON_EXTRACT(companion_snapshot, '$.two_factor.counts.wfls_only')".
                ') > 0'
            );
        }

        return $q->orderBy('domain')->get();
    }
}
