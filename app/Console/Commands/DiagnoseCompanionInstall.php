<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Ssh\SshClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Triage why a Companion install failed on a specific site.
 *
 * Runs four probes against the live site, then maps the results onto the
 * three failure clusters we see in production:
 *
 *   - "wp-cli secret push failed (exit 1)" -> wp-cli can't boot (wrong wp_path,
 *     wp not at /usr/local/bin/wp, table_prefix mismatch).
 *   - "/health probe 401 invalid_signature" -> stored secret doesn't match
 *     what the live plugin reads (Redis/opcache alloptions cache, multiple
 *     installs at one domain, wrong table_prefix).
 *
 * Read-only — never writes to the remote site or to the local DB. Safe to run
 * against any site without affecting state.
 */
class DiagnoseCompanionInstall extends Command
{
    protected $signature = 'clockwork:diagnose-companion-install
        {site : Site ID or domain}';

    protected $description = 'Read-only triage for Companion install/health failures on a single site.';

    public function handle(SshClient $ssh): int
    {
        $site = $this->resolveSite();
        if (! $site) {
            return self::FAILURE;
        }

        $wpPath = $site->wp_path ?: '/sites/'.$site->domain.'/files';
        $tablePrefix = $site->table_prefix ?: 'wp_';

        $this->line('');
        $this->line("<info>Diagnosing Companion install for {$site->domain}</info>");
        $this->line("  server         = {$site->server?->name}");
        $this->line('  site_user      = '.($site->site_user ?: '(missing)'));
        $this->line("  wp_path        = {$wpPath}".($site->wp_path ? '' : ' (FALLBACK — site has no wp_path set)'));
        $this->line("  table_prefix   = {$tablePrefix}".($site->table_prefix ? '' : ' (FALLBACK — site has no table_prefix set)'));
        $this->line('  secret_in_db   = '.($site->companion_secret ? substr($site->companion_secret, 0, 8).'…' : '(none)'));

        if (! $site->server || ! $site->site_user || ! $site->server->ssh_password) {
            $this->error('Missing prerequisites (server / site_user / ssh_password). Cannot probe.');

            return self::FAILURE;
        }

        // Probe A: does wp-cli boot at all at the assumed path?
        // `2>/dev/null` strips the sudo password prompt — sudo writes the
        // prompt on stderr, and we don't want it mingling with stdout that
        // we'll be comparing against secrets later.
        $this->line('');
        $this->line('<comment>[A] wp-cli boot probe — `wp option get siteurl`</comment>');
        $probeA = $this->runAsSiteUser($ssh, $site, sprintf(
            '/usr/local/bin/wp --path=%s option get siteurl 2>/dev/null',
            escapeshellarg($wpPath),
        ));
        $this->reportProbe($probeA);

        // Probe B: is /usr/local/bin/wp even there?
        $this->line('');
        $this->line('<comment>[B] wp-cli binary probe — `which wp` + `/usr/local/bin/wp --version`</comment>');
        $probeB = $this->runAsSiteUser($ssh, $site, 'which wp 2>/dev/null; /usr/local/bin/wp --version 2>/dev/null || echo "(no wp at /usr/local/bin/wp)"');
        $this->reportProbe($probeB);

        // Probe B2: read the *actual* $table_prefix from wp-config.php. wp-cli
        // uses this prefix when reading via the WP API; our installer uses
        // whatever Clockwork's Site model has. If they disagree, the INSERT
        // lands in a table that does not exist -> exit 1 (Cluster 1 root cause).
        $this->line('');
        $this->line('<comment>[B2] Actual $table_prefix from wp-config.php</comment>');
        $probeB2 = $this->runAsSiteUser($ssh, $site, sprintf(
            'grep -E \'\\$table_prefix\\s*=\' %s/wp-config.php 2>/dev/null | head -1',
            escapeshellarg($wpPath),
        ));
        $this->reportProbe($probeB2);
        $remotePrefix = '';
        if (preg_match("/\\\$table_prefix\\s*=\\s*['\"]([^'\"]+)['\"]/", $probeB2['output'], $pm)) {
            $remotePrefix = $pm[1];
        }
        if ($remotePrefix !== '' && $remotePrefix !== $tablePrefix) {
            $this->line("  <fg=red>PREFIX MISMATCH</> — Clockwork has '{$tablePrefix}', wp-config.php has '{$remotePrefix}'.");
        }

        // Probe C: what does the *real* options table say the secret is?
        // We bypass wp-cli's option read (which goes through alloptions cache)
        // and hit the DB directly via wp db query against the prefix wp-cli
        // itself would use (i.e., the one we just read from wp-config), not
        // necessarily the one Clockwork has cached.
        $effectivePrefix = $remotePrefix !== '' ? $remotePrefix : $tablePrefix;
        $this->line('');
        $this->line('<comment>[C] Raw secret read from '.$effectivePrefix.'options</comment>');
        $sql = sprintf(
            "SELECT option_value FROM `%soptions` WHERE option_name = 'clockwork_companion_secret'",
            preg_replace('/[^a-z0-9_]/i', '', $effectivePrefix),
        );
        $probeC = $this->runAsSiteUser($ssh, $site, sprintf(
            '/usr/local/bin/wp --path=%s db query %s --skip-column-names 2>/dev/null',
            escapeshellarg($wpPath),
            escapeshellarg($sql),
        ));
        $this->reportProbe($probeC);

        $remoteSecret = trim($probeC['output']);
        $localSecret = (string) ($site->companion_secret ?? '');

        $this->line('');
        $this->line('<comment>[D] Secret comparison (local DB vs remote wp_options)</comment>');
        if ($probeC['exit'] !== 0) {
            $this->line('  (skipped — probe C did not run cleanly)');
        } elseif ($remoteSecret === '' || $remoteSecret === '0') {
            $this->line("  remote: <fg=red>empty</> — the INSERT never landed in `{$tablePrefix}options`.");
            $this->line('  Likely: <fg=yellow>wrong table_prefix</> on the Site model, or wp-cli wrote to a different DB.');
        } elseif ($localSecret !== '' && $remoteSecret === $localSecret) {
            $this->line("  remote secret = local secret = <fg=green>match</> ({$remoteSecret} ... first 16 chars: ".substr($remoteSecret, 0, 16).')');
        } else {
            $this->line('  local  : '.substr($localSecret, 0, 16).'…');
            $this->line('  remote : '.substr($remoteSecret, 0, 16).'…');
            $this->line("  <fg=red>MISMATCH</> — that's why /health 401s.");
        }

        // Probe E: actually call /health and surface what comes back.
        $this->line('');
        $this->line('<comment>[E] Live /health request</comment>');
        $healthOk = false;
        try {
            $health = (new ClockworkCompanionClient($site))->health();
            $this->line('  <fg=green>HTTP 200</>');
            $this->line('  version      = '.($health['version'] ?? '(none)'));
            $this->line('  capabilities = '.implode(', ', (array) ($health['capabilities'] ?? [])));
            $healthOk = true;
        } catch (Throwable $e) {
            $this->line('  <fg=red>FAILED</> — '.$e->getMessage());
        }

        $this->line('');
        $this->printVerdict($probeA, $probeC, $remoteSecret, $localSecret, $healthOk, $tablePrefix, $remotePrefix);

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $arg = (string) $this->argument('site');
        $site = Site::query()
            ->with('server')
            ->where(function ($q) use ($arg) {
                $q->where('id', is_numeric($arg) ? (int) $arg : 0)
                    ->orWhere('domain', $arg);
            })
            ->first();

        if (! $site) {
            $this->error("No site matched id-or-domain '{$arg}'.");
        }

        return $site;
    }

    /**
     * Mirror of CompanionInstaller::runAsSiteUser. Same sentinel pattern —
     * duplicated rather than extracted because this is read-only diagnostic
     * code and we explicitly don't want it sharing state with the writer.
     *
     * @return array{output: string, exit: int}
     */
    private function runAsSiteUser(SshClient $ssh, Site $site, string $script): array
    {
        $sentinel = '__CLOCKWORK_DIAG_EXIT__';
        $inner = sprintf(
            'echo "$CW_SUDO_PW" | sudo -S -u %s bash -c %s 2>&1; echo "%s:$?"',
            escapeshellarg((string) $site->site_user),
            escapeshellarg($script),
            $sentinel,
        );
        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s',
            escapeshellarg((string) $site->server->ssh_password),
            escapeshellarg($inner),
        );

        $raw = $ssh->exec($site->server, $cmd);

        $exit = -1;
        if (preg_match('/'.$sentinel.':(\d+)/', $raw, $m)) {
            $exit = (int) $m[1];
            $raw = (string) preg_replace('/\s*'.$sentinel.':\d+\s*$/', '', $raw);
        }

        // The outer pipeline uses `2>&1` so we capture stderr from the
        // inner script, but that also pulls in sudo's password prompt
        // ("[sudo] password for <user>: "). Strip it before returning so
        // string comparisons (e.g., remote-vs-local secret) aren't corrupted.
        $raw = (string) preg_replace('/\[sudo\] password for [^:]*:\s*/', '', $raw);

        return ['output' => trim($raw), 'exit' => $exit];
    }

    /**
     * @param  array{output: string, exit: int}  $probe
     */
    private function reportProbe(array $probe): void
    {
        $color = $probe['exit'] === 0 ? 'green' : 'red';
        $this->line("  exit = <fg={$color}>{$probe['exit']}</>");
        if ($probe['output'] !== '') {
            foreach (explode("\n", $probe['output']) as $line) {
                $this->line('  | '.$line);
            }
        }
    }

    /**
     * @param  array{output: string, exit: int}  $probeA
     * @param  array{output: string, exit: int}  $probeC
     */
    private function printVerdict(array $probeA, array $probeC, string $remoteSecret, string $localSecret, bool $healthOk, string $localPrefix, string $remotePrefix): void
    {
        $this->line('<info>━━ Verdict ━━</info>');

        if ($probeA['exit'] !== 0) {
            $this->line('<fg=red>[Cluster 1] wp-cli cannot boot at the assumed path.</>');
            $this->line('  Most likely fixes:');
            $this->line('    1. Site model has stale wp_path. Re-run: clockwork:extract-wp-configs --site='.$this->argument('site'));
            $this->line('    2. wp-cli is not at /usr/local/bin/wp on this server. SSH in and `which wp` to confirm.');
            $this->line('    3. Site is multisite or non-standard layout — wp_path may need manual override.');

            return;
        }

        if ($remotePrefix !== '' && $remotePrefix !== $localPrefix) {
            $this->line('<fg=red>[Cluster 1, prefix variant] table_prefix mismatch.</>');
            $this->line("  Clockwork's Site model has <fg=yellow>'{$localPrefix}'</>, wp-config.php has <fg=yellow>'{$remotePrefix}'</>.");
            $this->line('  Installer INSERTs into '.$localPrefix.'options — that table does not exist on this site, so wp db query exits 1.');
            $this->line('  Fix:');
            $this->line('    php artisan clockwork:extract-wp-configs --site='.$this->argument('site'));
            $this->line('    php artisan clockwork:install-companion --site='.$this->argument('site').' --rotate-secret');

            return;
        }

        if ($probeC['exit'] !== 0 || $remoteSecret === '') {
            $this->line('<fg=yellow>[Cluster 2a] wp-cli boots, prefix matches, but the secret row is missing from '.$localPrefix.'options.</>');
            $this->line('  This usually means the install never reached the INSERT step (preceding probes show why),');
            $this->line('  or the row was manually deleted. Re-run install:');
            $this->line('    php artisan clockwork:install-companion --site='.$this->argument('site').' --rotate-secret');

            return;
        }

        if ($localSecret !== '' && $remoteSecret !== $localSecret) {
            $this->line('<fg=yellow>[Cluster 2b] Local and remote secrets diverged.</>');
            $this->line('  Either we wrote to a different DB than the request hits, or our DB write missed a cache.');
            $this->line('  Quick fix: rotate the secret to force-overwrite both sides:');
            $this->line('    php artisan clockwork:install-companion --site='.$this->argument('site').' --rotate-secret');

            return;
        }

        // Secrets match and wp-cli works. If /health *still* 401s, the
        // signature is being rejected for a reason unrelated to which value
        // the option holds. Two suspects:
        //   - Persistent object cache (Redis) keeping the old secret in
        //     alloptions despite our `wp cache flush` — PHP-FPM workers
        //     warmed before the flush still hold it in memory.
        //   - Clock skew: signatures embed a Unix timestamp; if the host's
        //     clock is more than ~5 min off the WP-side timestamp window,
        //     the plugin rejects the request.
        if (! $healthOk) {
            $this->line('<fg=yellow>[Cluster 2c] Secrets match, but /health still 401s.</>');
            $this->line('  The signature is being rejected even though both sides agree on the secret.');
            $this->line('  Most likely:');
            $this->line('    1. Warm PHP-FPM workers serving stale alloptions. Restart the FPM pool on the server:');
            $this->line('       ssh root@<server> "systemctl restart php8.*-fpm"   # see server in header above');
            $this->line('    2. Clock skew between this host and the remote. Check NTP:');
            $this->line('       date -u && ssh root@<server> date -u');
            $this->line('  If neither — there are likely two WP installs sharing this domain.');

            return;
        }

        $this->line('<fg=green>Secrets match, wp-cli works, /health is 200. Companion is healthy.</>');
    }
}
