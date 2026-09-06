<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Ssh\SshClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * WordPress 7.0's auto-upgrade has a flaky filename-extraction bug under
 * certain conditions (interrupted unpack, slow disk, etc.). The
 * wp-includes/php-ai-client/ subtree comes out with filenames truncated
 * at various lengths and stripped of their .php extension — e.g.
 * `AbstractApiBasedModelMetadataDirectory.php` (40 chars) lands as
 * `AbstractApiBasedModelMetada` (27 chars, no extension). PHP autoloading
 * fails for any class whose file got mangled; the site looks fine until
 * code paths actually use the AI Client subsystem, then it fatals with
 * "class not found." We caught 4 sites across 3 different servers in
 * mid-June 2026 — different upgrade windows, not a single bad host.
 *
 * This command sweeps every Companion-installed site for the truncation
 * pattern (files in php-ai-client without a dot-extension). With
 * `--repair`, it runs the same recovery recipe we used by hand:
 *
 *   1. Move the broken wp-includes/php-ai-client/ to a timestamped .bak
 *   2. `sudo -u <site_user> wp core download --force --skip-content
 *      --version=<current WP version>` — pulls the WordPress.org tarball
 *      and re-extracts every core file cleanly. --skip-content preserves
 *      wp-content; --force overwrites existing core files.
 *   3. Verify a known previously-broken filename exists with its full
 *      name. If yes, delete the .bak. If no, leave the .bak so the
 *      operator can roll back.
 *
 * Scheduled weekly. Sunday 05:30 UTC (after the daily 04:15 update poll
 * but before US business hours) so any new fallout from a Saturday-night
 * auto-upgrade gets caught before Monday.
 *
 * Idempotent — re-running over a clean site is a no-op (no truncated
 * files found, no action taken). Sites without the php-ai-client
 * directory (older WP versions) are silently skipped.
 */
class ScanWp7Truncation extends Command
{
    protected $signature = 'clockwork:scan-wp7-truncation
                            {--repair : Auto-repair affected sites via wp core download --force}
                            {--site= : Limit to one site (id or domain)}';

    protected $description = 'Detect (and optionally repair) sites with truncated WordPress 7.0 php-ai-client core files.';

    public function handle(SshClient $ssh, ActionLogger $log): int
    {
        $repair = (bool) $this->option('repair');
        $siteFilter = $this->option('site');

        $query = Site::query()
            ->where('companion_installed', true)
            ->whereHas('server', fn ($q) => $q->monitored())
            ->orderBy('id');

        if ($siteFilter !== null && $siteFilter !== '') {
            $query->where(function ($q) use ($siteFilter) {
                if (ctype_digit((string) $siteFilter)) {
                    $q->where('id', (int) $siteFilter);
                } else {
                    $q->where('domain', $siteFilter);
                }
            });
        }

        $sites = $query->get();
        $this->info('Scanning '.$sites->count().' site(s)...'.($repair ? ' [REPAIR MODE]' : ''));

        $checked = 0;
        $affected = 0;
        $repaired = 0;
        $repairFailed = 0;

        foreach ($sites as $site) {
            $server = $site->server;
            if (! $server || ! $server->ssh_password) {
                continue;
            }
            $checked++;

            $wp = $site->wp_path ?: '/sites/'.$site->domain.'/files';
            $dir = $wp.'/wp-includes/php-ai-client';

            try {
                // -type f, no dot-extension, not a dotfile = the truncation pattern.
                $cmd = 'find '.escapeshellarg($dir).' -type f ! -name \'*.*\' ! -name \'.*\' 2>/dev/null | wc -l';
                $count = (int) trim($ssh->exec($server, $cmd));
            } catch (Throwable $e) {
                $this->warn("  [skip] {$site->domain}: SSH error — ".$e->getMessage());

                continue;
            }

            if ($count === 0) {
                continue;
            }

            $affected++;
            $this->warn("  [affected] {$site->domain} — {$count} truncated file(s)");

            if (! $repair) {
                continue;
            }

            $outcome = $this->repair($ssh, $site, $wp);
            if ($outcome['ok']) {
                $repaired++;
                $this->info('    ✓ repaired ('.$count.' truncated files); WP version downloaded: '.$outcome['version']);
                $log->record(
                    actionType: ActionLog::TYPE_WP_CORE_REPAIRED,
                    summary: "Auto-repaired truncated WP 7.0 php-ai-client files on {$site->domain} ({$count} files)",
                    site: $site,
                    server: $server,
                    details: [
                        'truncated_count' => $count,
                        'wp_version' => $outcome['version'],
                        'backup_path' => $outcome['backup_path'],
                    ],
                    ok: true,
                    actor: 'scheduled',
                );
            } else {
                $repairFailed++;
                $this->error('    ✗ repair failed: '.$outcome['error']);
                $log->record(
                    actionType: ActionLog::TYPE_WP_CORE_REPAIRED,
                    summary: "Auto-repair of {$site->domain} php-ai-client truncation FAILED",
                    site: $site,
                    server: $server,
                    details: ['truncated_count' => $count, 'error' => $outcome['error']],
                    ok: false,
                    error: $outcome['error'],
                    actor: 'scheduled',
                );
            }
        }

        $this->info(sprintf(
            'Done. checked=%d affected=%d repaired=%d repair_failed=%d',
            $checked,
            $affected,
            $repaired,
            $repairFailed,
        ));

        // Non-zero exit when something was found but not repaired so cron
        // catches it via Laravel's scheduled-command failure path.
        return ($affected > 0 && ! $repair) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{ok: bool, version?: string, backup_path?: string, error?: string}
     */
    private function repair(SshClient $ssh, Site $site, string $wpPath): array
    {
        $server = $site->server;
        // Pull the WP version from the live install — we want to redownload
        // whatever's currently active, not pin to 7.0 forever (7.0.1 / 7.1
        // auto-upgrades may surface the same bug).
        $verCmd = 'grep -E "^\\s*\\\\\$wp_version" '.escapeshellarg($wpPath.'/wp-includes/version.php').' | head -1';
        try {
            $verOut = trim($ssh->exec($server, $verCmd));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'could not read wp_version: '.$e->getMessage()];
        }
        if (! preg_match("/'([\\d.]+)'/", $verOut, $m)) {
            return ['ok' => false, 'error' => 'could not parse wp_version from version.php'];
        }
        $version = $m[1];

        $script = sprintf(
            'set -e; do_sudo() { printf "%%s\n" "$CW_SUDO_PW" | sudo -S "$@" 2>/dev/null; } ; '
            .'BAK=%s/wp-includes/php-ai-client.bak.$(date +%%Y%%m%%d-%%H%%M%%S); echo "BACKUP=$BAK"; '
            .'do_sudo mv %s/wp-includes/php-ai-client "$BAK"; '
            .'do_sudo -u %s /usr/local/bin/wp --path=%s core download --force --skip-content --version=%s 2>&1 | tail -3; '
            // Verify a known previously-broken filename is now intact.
            .'test -f %s/wp-includes/php-ai-client/src/Providers/ApiBasedImplementation/AbstractApiBasedModelMetadataDirectory.php; '
            .'do_sudo rm -rf "$BAK"; echo "VERIFY_OK"',
            escapeshellarg($wpPath), escapeshellarg($wpPath),
            escapeshellarg($site->site_user), escapeshellarg($wpPath), escapeshellarg($version),
            escapeshellarg($wpPath),
        );

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($script),
        );

        try {
            $out = (string) $ssh->exec($server, $cmd, 180);
        } catch (Throwable $e) {
            return ['ok' => false, 'version' => $version, 'error' => 'ssh exec failed: '.$e->getMessage()];
        }
        $out = (string) preg_replace('/\[sudo\] password for [^:]+:\s*/', '', $out);

        if (! str_contains($out, 'VERIFY_OK')) {
            return ['ok' => false, 'version' => $version, 'error' => 'verify failed; .bak preserved. Output: '.mb_strimwidth($out, 0, 400, '…')];
        }

        // Extract the backup path from the output for the audit log.
        $backup = '';
        if (preg_match('/^BACKUP=(.+)$/m', $out, $bm)) {
            $backup = trim($bm[1]);
        }

        return ['ok' => true, 'version' => $version, 'backup_path' => $backup];
    }
}
