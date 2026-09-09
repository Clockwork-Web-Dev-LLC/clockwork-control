<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Ssh\SshClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Pressable\PressableClient;
use Modules\Pressable\PressableCommandRunner;
use Throwable;

/**
 * Second half of the WFLS deprecation, after clockwork:migrate-wfls-2fa has
 * moved everyone's 2FA over: deactivate + delete the Wordfence Login
 * Security plugin itself. Companion only migrates 2FA data — it has no
 * route to touch the plugin's install state, so this goes over wp-cli like
 * the rest of this app's plugin-file-level operations — SSH+sudo for
 * server-hosted sites, Pressable's command API for Pressable (no Server
 * row, no sudo, and its wp-cli shim needs `cd` into a fixed docroot rather
 * than `--path=`; see WpCoreChecksumVerifier::verifyViaPressable() for the
 * same convention).
 *
 * Gated strictly on Companion's own wfls_ready_to_remove flag (re-checked
 * live, not from cache) — true only when the plugin's files are still on
 * disk AND wfls_unmigrated_total is 0. Never runs against a site with any
 * unmigrated user.
 */
class RemoveWflsPlugin extends Command
{
    private const SLUG = 'wordfence-login-security';

    /** Matches WpCoreChecksumVerifier::PRESSABLE_DOCROOT. */
    private const PRESSABLE_DOCROOT = '/srv/htdocs';

    protected $signature = 'clockwork:remove-wfls-plugin
        {site? : Site id or domain — omit with --all to sweep every site ready for removal}
        {--all : Remove WFLS from every companion-equipped site Companion reports as ready}
        {--dry-run : Show which sites/commands would run without changing anything}';

    protected $description = 'Deactivate and delete the Wordfence Login Security plugin on sites where every user has migrated to Companion 2FA.';

    public function handle(ActionLogger $logger, SshClient $ssh, PressableCommandRunner $pressableRunner, PressableClient $pressableClient): int
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
        $totals = ['removed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($sites as $site) {
            $client = new ClockworkCompanionClient($site);

            try {
                $status = $client->twoFactorStatus();
            } catch (Throwable $e) {
                $this->warn("  [fail] {$site->domain}: could not fetch /two-factor status — {$e->getMessage()}");
                $totals['failed']++;

                continue;
            }

            if (! ($status['ok'] ?? false)) {
                $this->warn("  [fail] {$site->domain}: /two-factor responded without ok=true");
                $totals['failed']++;

                continue;
            }

            $unmigrated = (int) ($status['wfls_unmigrated_total'] ?? 0);
            if ($unmigrated > 0) {
                $this->line("  [skip] {$site->domain}: {$unmigrated} user(s) still unmigrated — run clockwork:migrate-wfls-2fa first.");
                $totals['skipped']++;

                continue;
            }

            if (! ($status['wfls_ready_to_remove'] ?? false)) {
                $this->line("  [skip] {$site->domain}: Companion doesn't report this as ready to remove (already removed, or no WFLS install found).");
                $totals['skipped']++;

                continue;
            }

            if ($site->isPressable()) {
                if (! $site->pressable_site_id) {
                    $this->warn("  [fail] {$site->domain}: no pressable_site_id on file — can't run wp-cli via the Pressable API.");
                    $totals['failed']++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  [dry-run] would deactivate + delete '".self::SLUG."' on {$site->domain} (Pressable, ".self::PRESSABLE_DOCROOT.').');

                    continue;
                }

                $result = $this->deactivateAndDeleteViaPressable($pressableRunner, $site);

                // Pressable's edge cache serves stale GET responses
                // indefinitely once cached (same pitfall documented on
                // RefreshCompanionSnapshot's /snapshot pull) — confirmed
                // live 2026-09-09: GET /two-factor kept reporting
                // wfls_active=true immediately after a verified-on-disk
                // successful removal, until purged. Best-effort: a purge
                // failure shouldn't block the removal that already
                // succeeded above.
                if ($result['ok']) {
                    try {
                        $pressableClient->purgeEdgeCache($site->pressable_site_id);
                    } catch (Throwable) {
                        // non-fatal
                    }
                }
            } else {
                $wpPath = $site->resolveWpPath();
                if (! $site->server || $site->server->is_ignored || ! $site->site_user || ! $site->server->ssh_password || $wpPath === null) {
                    $this->warn("  [fail] {$site->domain}: missing SSH prerequisites (server/site_user/ssh_password/wp_path) — can't run wp-cli.");
                    $totals['failed']++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  [dry-run] would deactivate + delete '".self::SLUG."' on {$site->domain} ({$wpPath}).");

                    continue;
                }

                $result = $this->deactivateAndDelete($ssh, $site, $wpPath);
            }

            if (! $result['ok']) {
                $totals['failed']++;
                $this->error("  [fail] {$site->domain}: {$result['message']}");

                $logger->record(
                    actionType: ActionLog::TYPE_WFLS_PLUGIN_REMOVED,
                    summary: "WFLS plugin removal failed on {$site->domain}.",
                    site: $site,
                    target: self::SLUG,
                    details: ['output' => $result['output']],
                    ok: false,
                    error: $result['message'],
                    actor: 'cli',
                );

                continue;
            }

            $totals['removed']++;
            $this->info("  [ok]   {$site->domain}: Wordfence Login Security deactivated and deleted.");

            $logger->record(
                actionType: ActionLog::TYPE_WFLS_PLUGIN_REMOVED,
                summary: "Removed Wordfence Login Security from {$site->domain} — every user had already migrated to Companion 2FA.",
                site: $site,
                target: self::SLUG,
                details: ['output' => $result['output']],
                ok: true,
                actor: 'cli',
            );

            $this->refreshSnapshot($site, $client);
        }

        if ($dryRun) {
            $this->info('Dry run complete.');
        } else {
            $this->info(sprintf(
                'Done — removed=%d skipped=%d failed=%d',
                $totals['removed'],
                $totals['skipped'],
                $totals['failed'],
            ));
        }

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{ok: bool, message: string, output: string}
     */
    private function deactivateAndDelete(SshClient $ssh, Site $site, string $wpPath): array
    {
        // A network-activated plugin on a multisite install refuses a plain
        // `plugin deactivate` ("must be deactivated with --network flag").
        // Confirmed live 2026-09-09 on a client multisite. Try plain
        // deactivate first (the common case); only fall back to --network
        // if that specific one fails, rather than always passing --network
        // (which errors on a non-multisite install instead).
        $script = sprintf(
            '(/usr/local/bin/wp --path=%s plugin deactivate %s 2>&1 || /usr/local/bin/wp --path=%s plugin deactivate %s --network 2>&1) '
            .'&& /usr/local/bin/wp --path=%s plugin delete %s 2>&1',
            escapeshellarg($wpPath),
            escapeshellarg(self::SLUG),
            escapeshellarg($wpPath),
            escapeshellarg(self::SLUG),
            escapeshellarg($wpPath),
            escapeshellarg(self::SLUG),
        );

        $sentinel = '__CLOCKWORK_WFLS_REMOVE_EXIT__';
        // `echo` (not `printf %s`) for the sudo password feed — printf omits
        // the trailing newline, which leaves sudo -S waiting on EOF and some
        // wp-cli subcommands starting before stdin unblocks. See
        // CompanionInstaller::runAsSiteUser()'s docblock for the same note.
        // `-p ""` suppresses the password prompt so it can't contaminate the
        // captured stdout via the 2>&1 merge.
        $inner = sprintf(
            'echo "$CW_SUDO_PW" | sudo -S -p "" -u %s bash -c %s; echo "%s:$?"',
            escapeshellarg((string) $site->site_user),
            escapeshellarg($script),
            $sentinel,
        );
        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s',
            escapeshellarg((string) $site->server->ssh_password),
            escapeshellarg($inner),
        );

        try {
            $raw = $ssh->exec($site->server, $cmd);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => "SSH transport failure: {$e->getMessage()}", 'output' => ''];
        }

        $exit = -1;
        if (preg_match('/'.$sentinel.':(\d+)/', $raw, $m)) {
            $exit = (int) $m[1];
            $raw = (string) preg_replace('/\s*'.$sentinel.':\d+\s*$/', '', $raw);
        }
        $output = trim($raw);

        if ($exit !== 0) {
            return ['ok' => false, 'message' => "wp-cli exited {$exit}.", 'output' => $output];
        }

        return ['ok' => true, 'message' => 'ok', 'output' => $output];
    }

    /**
     * @return array{ok: bool, message: string, output: string}
     */
    private function deactivateAndDeleteViaPressable(PressableCommandRunner $runner, Site $site): array
    {
        $script = sprintf(
            'cd %s && wp plugin deactivate %s 2>&1 && wp plugin delete %s 2>&1',
            self::PRESSABLE_DOCROOT,
            escapeshellarg(self::SLUG),
            escapeshellarg(self::SLUG),
        );

        try {
            // runOrFail() throws on non-zero exit — no sentinel/exit-code
            // parsing needed here, unlike the SSH path (see
            // SiteCommandRunner's docblock for why the two transports'
            // error semantics differ).
            $output = $runner->runOrFail($site->pressable_site_id, $script, 120);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'output' => ''];
        }

        return ['ok' => true, 'message' => 'ok', 'output' => trim($output)];
    }

    /**
     * Best-effort — a failure here doesn't undo the removal above.
     */
    private function refreshSnapshot(Site $site, ClockworkCompanionClient $client): void
    {
        try {
            $payload = $client->snapshot();
        } catch (Throwable $e) {
            Log::warning('companion.snapshot.refresh_after_wfls_removal_failed', [
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
            // ready-to-remove, mirroring resolveSites() in
            // clockwork:migrate-wfls-2fa. Re-verified live per-site above
            // before anything is touched.
            $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(companion_snapshot, '$.two_factor.wfls_ready_to_remove')) = 'true'");
        }

        return $q->orderBy('domain')->get();
    }
}
