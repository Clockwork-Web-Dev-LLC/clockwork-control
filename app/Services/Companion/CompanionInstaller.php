<?php

namespace App\Services\Companion;

use App\Models\Site;
use App\Services\Ssh\SshClient;
use Modules\Core\Contracts\CompanionInstaller as CompanionInstallerContract;
use Throwable;

/**
 * Deploys the Clockwork Companion mu-plugin onto a site.
 *
 * Two source modes:
 *   - dist_url:   fetch a published .zip, verify sha256, push base64
 *   - local_path: tar the dev repo at ~/Projects/clockwork-companion (default)
 *
 * Transport: ssh exec only (no rsync/scp dependency). The tarball is
 * shaped so that extracting at wp-content/mu-plugins/ produces:
 *   wp-content/mu-plugins/clockwork-companion.php   (loader)
 *   wp-content/mu-plugins/clockwork-companion/src/  (rest of source)
 *
 * Atomicity: extraction lands in a temp staging dir, then we rm the old
 * loader + dir and mv the staged ones into place. A failure mid-extract
 * leaves the previous version intact; a failure mid-swap is the only
 * window where mu-plugins is half-loaded (small, no easy way around it
 * without reload-tolerant code on the WP side).
 */
class CompanionInstaller implements CompanionInstallerContract
{
    public const RESULT_INSTALLED = 'installed';

    public const RESULT_UPDATED = 'updated';

    public const RESULT_ALREADY_CURRENT = 'already-current';

    public const RESULT_FAILED = 'failed';

    public const RESULT_SKIPPED_NOT_WP = 'skipped-not-wp';

    public function __construct(
        private readonly SshClient $ssh,
        private readonly CompanionTarballBuilder $tarballBuilder,
    ) {}

    /**
     * @return array{result: string, message: string, output?: string, version?: string}
     */
    public function installOrUpdate(Site $site, bool $rotateSecret = false): array
    {
        $preflight = $this->preflight($site);
        if ($preflight !== null) {
            return $preflight;
        }

        try {
            $tarball = $this->tarballBuilder->acquire();
        } catch (Throwable $e) {
            return ['result' => self::RESULT_FAILED, 'message' => 'Could not acquire plugin source: '.$e->getMessage()];
        }

        // Decide secret BEFORE pushing anything — if rotating, the new value
        // is the one we'll persist + push; if not, reuse the existing one or
        // generate a fresh one if the site has none yet.
        $secret = $rotateSecret || ! is_string($site->companion_secret) || $site->companion_secret === ''
            ? bin2hex(random_bytes(32))
            : $site->companion_secret;

        $wpPath = $site->wp_path ?: '/sites/'.$site->domain.'/files';
        $extract = $this->extractOnRemote($site, $wpPath, $tarball);
        if ($extract['exit'] !== 0) {
            return [
                'result' => self::RESULT_FAILED,
                'message' => 'Remote extract failed (exit '.$extract['exit'].').',
                'output' => $extract['output'],
            ];
        }

        $pushSecret = $this->pushSecret($site, $wpPath, $secret);
        if ($pushSecret['exit'] !== 0) {
            return [
                'result' => self::RESULT_FAILED,
                'message' => 'wp-cli secret push failed (exit '.$pushSecret['exit'].').',
                'output' => $pushSecret['output'],
            ];
        }

        // Persist the secret before the verify call — the verify call ITSELF
        // signs with $site->companion_secret, so it has to be set on the model
        // first.
        $previouslyInstalled = (bool) $site->companion_installed;
        $site->companion_secret = $secret;
        $site->save();

        try {
            $health = (new ClockworkCompanionClient($site->fresh()))->health();
        } catch (Throwable $e) {
            return [
                'result' => self::RESULT_FAILED,
                'message' => 'Plugin extracted + secret stored, but /health probe failed: '.$e->getMessage(),
            ];
        }

        $version = (string) ($health['version'] ?? '');
        $capabilities = (array) ($health['capabilities'] ?? []);
        $previousVersion = $site->companion_version;

        $site->forceFill([
            'companion_installed' => true,
            'companion_version' => $version,
            'companion_capabilities' => $capabilities,
            'companion_last_seen_at' => now(),
        ])->save();

        // Push active white-label branding if configured (best-effort, non-blocking)
        try {
            app(CompanionBrandingManager::class)->syncSite($site->fresh());
        } catch (Throwable) {
            // Non-blocking on install
        }

        if (! $previouslyInstalled) {
            $result = self::RESULT_INSTALLED;
            $message = "Companion {$version} installed on {$site->domain}.";
        } elseif ($previousVersion !== $version) {
            $result = self::RESULT_UPDATED;
            $message = "Companion upgraded {$previousVersion} → {$version} on {$site->domain}.";
        } else {
            $result = self::RESULT_ALREADY_CURRENT;
            $message = "Companion {$version} already current on {$site->domain}; secret refreshed.";
        }

        return [
            'result' => $result,
            'message' => $message,
            'version' => $version,
        ];
    }

    /**
     * @return null|array{result: string, message: string}
     */
    private function preflight(Site $site): ?array
    {
        if (! $site->is_wordpress) {
            return ['result' => self::RESULT_SKIPPED_NOT_WP, 'message' => 'Not a WordPress site.'];
        }
        if (! $site->server) {
            return ['result' => self::RESULT_FAILED, 'message' => 'Site has no linked server.'];
        }
        if (! $site->site_user) {
            return ['result' => self::RESULT_FAILED, 'message' => 'Site has no site_user; cannot drop privileges to install.'];
        }
        if (! $site->server->ssh_password) {
            return ['result' => self::RESULT_FAILED, 'message' => 'Server has no sudo password stored; cannot run wp-cli as the site user.'];
        }

        return null;
    }

    /**
     * @return array{output: string, exit: int}
     */
    private function extractOnRemote(Site $site, string $wpPath, string $base64Tarball): array
    {
        // The base64 payload is too large to inline in a single `bash -c` call
        // — at ~130 KB it crosses Linux's ARG_MAX once the sudo+escapeshellarg
        // wrapping doubles it. Stream it up in chunks to a /tmp staging file
        // first (as the SSH user, no sudo needed — /tmp is world-writable),
        // then the extract script reads the file and pipes through base64 -d.
        $remoteB64Path = '/tmp/clockwork-companion-stage-'.bin2hex(random_bytes(8)).'.b64';
        $upload = $this->uploadBase64ToRemote($site, $remoteB64Path, $base64Tarball);
        if ($upload['exit'] !== 0) {
            return $upload;
        }

        $escapedB64Path = escapeshellarg($remoteB64Path);

        // Atomic swap pattern: stage in .clockwork-stage, swap loader + dir,
        // remove stage. All as the site_user so file ownership is right by
        // construction. The /tmp staging file is owned by clockwork-deploy (the SSH
        // user), so cleanup happens OUTSIDE the sudo'd block — the site_user
        // can't delete a file it doesn't own.
        $script = <<<BASH
set -euo pipefail
MU="{$wpPath}/wp-content/mu-plugins"
mkdir -p "\$MU"
cd "\$MU"
rm -rf .clockwork-stage
mkdir .clockwork-stage
base64 -d < {$escapedB64Path} | tar -xzf - -C .clockwork-stage
# Swap. We use 'rm -rf' on the stage dir at the end (not 'rmdir') because
# even with COPYFILE_DISABLE on the tar side, a future tarball could carry
# unexpected metadata files (._something, .DS_Store, pax_global_header) that
# rmdir would reject. The two mv's already moved everything we care about.
rm -f clockwork-companion.php
rm -rf clockwork-companion
mv .clockwork-stage/clockwork-companion.php .
mv .clockwork-stage/clockwork-companion .
rm -rf .clockwork-stage
echo "OK: companion staged at \$MU"
BASH;

        $result = $this->runAsSiteUser($site, $script);

        // Best-effort cleanup of the /tmp staging file regardless of extract
        // success — leaking these would slowly fill /tmp on busy fleets. The
        // file is owned by clockwork-deploy, so cleanup runs without sudo.
        $this->ssh->exec($site->server, 'rm -f '.$escapedB64Path);

        return $result;
    }

    /**
     * Chunk-upload a base64 string to a remote file. Each chunk fits well
     * under ARG_MAX (~128 KB on Linux). First chunk truncates the file,
     * subsequent chunks append. Uses `printf %s` (not echo) to avoid the
     * trailing newline that would corrupt the concatenation.
     *
     * @return array{output: string, exit: int}
     */
    private function uploadBase64ToRemote(Site $site, string $remotePath, string $base64): array
    {
        $chunkSize = 50_000;
        $chunks = str_split($base64, $chunkSize);
        $escPath = escapeshellarg($remotePath);

        foreach ($chunks as $i => $chunk) {
            $redirect = $i === 0 ? '>' : '>>';
            $cmd = 'printf %s '.escapeshellarg($chunk).' '.$redirect.' '.$escPath;
            $out = trim($this->ssh->exec($site->server, $cmd));
            if ($out !== '') {
                // Any non-empty output from a pure write is a failure (no
                // success message, no shell prompt). Surface the message.
                return [
                    'exit' => 1,
                    'output' => "uploadBase64ToRemote chunk {$i} of ".count($chunks)." failed: {$out}",
                ];
            }
        }

        return ['exit' => 0, 'output' => 'uploaded '.count($chunks).' chunk(s)'];
    }

    /**
     * Pushes the HMAC secret into wp_options. Three-step to handle a Redis
     * object-cache trap on SpinupWP-managed sites:
     *
     * **The trap.** SpinupWP installs a Redis object-cache drop-in
     * (`wp-content/object-cache.php`). If we ever drop the row from wp_options
     * directly via SQL — cleanup path, partial uninstall — without flushing
     * the cache, the Redis cache still holds the old value. A bare `wp option
     * update` then calls update_option(), which reads cache (value X),
     * tries UPDATE on the DB row (finds none), returns false → "Could not
     * update option" with exit 1. `wp option delete` SHOULD invalidate the
     * cache, but in practice the alloptions blob (where autoloaded options
     * live) keeps the stale value across wp-cli invocations.
     *
     * **The fix.** `wp cache flush` first — clears Redis entirely. Then the
     * NEXT wp-cli boot re-runs Companion's `Secret::ensure()` which sees a
     * truly empty option (cache miss + DB miss), auto-creates a fresh random
     * value via add_option (which inserts into BOTH DB and cache cleanly),
     * and our `option update` then overwrites that with OUR secret value
     * (cache says fresh-auto-value, OUR value differs → UPDATE succeeds).
     *
     * Cost of cache flush: a brief cold-cache page load on this site for
     * the next visitor — measured in tens of ms, invisible to humans. Worth
     * it for installer reliability.
     *
     * **Idempotency.** WP's `update_option()` returns false when the new
     * value equals the existing value — long-standing quirk. wp-cli surfaces
     * that false as exit 1 with "Could not update option", which we used to
     * treat as a hard failure. On re-installs (where $site->companion_secret
     * already matches what's stored remotely) that meant every re-install
     * after the first one looked broken. Fix: read the current value first
     * and skip the write entirely when it's already what we want.
     *
     * @return array{output: string, exit: int}
     */
    private function pushSecret(Site $site, string $wpPath, string $secret): array
    {
        // Read first. If the remote already has our exact secret, skip the
        // write entirely — saves a Redis cache flush on every re-install.
        $readScript = sprintf(
            '/usr/local/bin/wp --path=%s option get clockwork_companion_secret 2>/dev/null',
            escapeshellarg($wpPath),
        );
        $read = $this->runAsSiteUser($site, $readScript);
        if ($read['exit'] === 0 && trim($read['output']) === $secret) {
            return ['output' => 'OK: secret already matches; no write needed', 'exit' => 0];
        }

        // **The bulletproof path.** Skip wp-cli's `option update` (which
        // routes through WP's update_option, which on Redis-backed sites
        // sometimes returns false for reasons that are hard to debug
        // remotely — stale alloptions cache, hook vetoes, value-comparison
        // false-negatives). Instead: a direct SQL upsert via `wp db query`,
        // then a cache flush so the next request reads our row fresh.
        //
        // Why upsert (INSERT ... ON DUPLICATE KEY UPDATE) over delete+add:
        // delete+add briefly leaves the option missing, and a Companion REST
        // request landing in that window would 401. Upsert is one statement,
        // atomic at the row level — no missing-secret window.
        //
        // `wp db query` runs against the same DB connection wp-cli uses,
        // so prefix is correct (it reads $table_prefix from wp-config) and
        // sudo-context credentials apply. The autoload='no' matches what
        // option update --autoload=no would have set.
        //
        // We read the table_prefix LIVE from wp-config via wp-cli rather
        // than trusting `$site->table_prefix` because the cached value drifts
        // after a migration (we saw a site whose new server had a custom
        // prefix while our DB still had the default 'wp_', producing a
        // misleading "Table 'db.wp_options' doesn't exist" error).
        $prefixScript = sprintf(
            '/usr/local/bin/wp --path=%s config get table_prefix 2>/dev/null',
            escapeshellarg($wpPath),
        );
        $prefixRead = $this->runAsSiteUser($site, $prefixScript);
        if ($prefixRead['exit'] !== 0 || trim($prefixRead['output']) === '') {
            return [
                'output' => "Could not read table_prefix from wp-config (exit {$prefixRead['exit']}): ".$prefixRead['output'],
                'exit' => 1,
            ];
        }
        $tablePrefix = trim($prefixRead['output']);
        if (! preg_match('/^[a-z0-9_]+$/i', $tablePrefix)) {
            return ['output' => "table_prefix '{$tablePrefix}' contains invalid characters; refusing to interpolate", 'exit' => 1];
        }
        // Refresh our cache so future operations (this same controller hit,
        // future scheduled jobs) work with the actual prefix.
        if ($site->table_prefix !== $tablePrefix) {
            $site->forceFill(['table_prefix' => $tablePrefix])->save();
        }
        $optionsTable = $tablePrefix.'options';

        $sqlEsc = str_replace("'", "''", $secret);
        $sql = "INSERT INTO `{$optionsTable}` (option_name, option_value, autoload) "
            ."VALUES ('clockwork_companion_secret', '{$sqlEsc}', 'no') "
            ."ON DUPLICATE KEY UPDATE option_value = '{$sqlEsc}', autoload = 'no'";

        $script = sprintf(
            '/usr/local/bin/wp --path=%s db query %s && echo OK: secret upserted',
            escapeshellarg($wpPath),
            escapeshellarg($sql),
        );

        $result = $this->runAsSiteUser($site, $script);

        // Best-effort cache flush, run separately and its result ignored —
        // chaining it with && used to fail this whole step whenever the
        // flush itself failed (seen live on a site with no active object
        // cache), even though the SQL write above had already succeeded,
        // and swallowing its output into /dev/null hid the real cause
        // behind the db query's own unrelated success message.
        $this->runAsSiteUser($site, sprintf('/usr/local/bin/wp --path=%s cache flush', escapeshellarg($wpPath)));

        return $result;
    }

    /**
     * Run an arbitrary bash script as the site_user via sudo. Handles the
     * sudo password the same way LlarInstaller does (env var, never on cmdline).
     *
     * `echo` (not `printf %s`) for the password feed: `printf %s` omits the
     * trailing newline, so sudo -S waits on EOF rather than seeing a
     * line-terminator. On these SpinupWP-managed Ubuntu hosts, that timing
     * makes some wp subcommands (notably `option update` and `core
     * verify-checksums`) start before sudo's stdin handler unblocks,
     * exiting silently with empty output. `echo` adds the newline so sudo
     * sees a complete line and accepts the password cleanly.
     *
     * `-p ''` suppresses sudo's password prompt. Without it, sudo emits
     * "[sudo] password for clockwork-deploy: " on stderr, which gets folded into
     * the captured output by the `2>&1` below. That contamination breaks
     * any code that does string comparison or value-extraction on the
     * captured stdout (e.g. reading back wp option values, parsing
     * `wp config get` output for table_prefix).
     *
     * @return array{output: string, exit: int}
     */
    /**
     * SSH `wp cache flush` for Spinup sites whose Companion is too old to
     * advertise cache-flush. Best-effort; callers treat a non-zero exit as skip.
     *
     * @return array{output: string, exit: int}
     */
    public function flushWpCache(Site $site): array
    {
        $wpPath = (string) $site->wp_path;
        if ($wpPath === '' || ! $site->site_user || ! $site->server) {
            return ['output' => 'missing SSH path or site user', 'exit' => 1];
        }

        $script = sprintf(
            '/usr/local/bin/wp --path=%s cache flush',
            escapeshellarg($wpPath),
        );

        return $this->runAsSiteUser($site, $script);
    }

    private function runAsSiteUser(Site $site, string $script): array
    {
        $sentinel = '__CLOCKWORK_COMPANION_EXIT__';
        $inner = sprintf(
            'echo "$CW_SUDO_PW" | sudo -S -p "" -u %s bash -c %s 2>&1; echo "%s:$?"',
            escapeshellarg((string) $site->site_user),
            escapeshellarg($script),
            $sentinel,
        );
        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s',
            escapeshellarg((string) $site->server->ssh_password),
            escapeshellarg($inner),
        );

        return $this->parseSentinelOutput($this->ssh->exec($site->server, $cmd), $sentinel);
    }

    /**
     * @return array{output: string, exit: int}
     */
    private function parseSentinelOutput(string $raw, string $sentinel): array
    {
        $exit = -1;
        if (preg_match('/'.$sentinel.':(\d+)/', $raw, $m)) {
            $exit = (int) $m[1];
            $raw = (string) preg_replace('/\s*'.$sentinel.':\d+\s*$/', '', $raw);
        }

        return ['output' => trim($raw), 'exit' => $exit];
    }
}
