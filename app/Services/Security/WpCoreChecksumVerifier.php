<?php

namespace App\Services\Security;

use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Ssh\SshClient;
use Modules\Core\Contracts\HostingProvider;
use Modules\Pressable\PressableCommandRunner;
use Throwable;

/**
 * Runs `wp core verify-checksums` over SSH and returns a SecurityScanResult.
 *
 * wp-cli's verify-checksums command compares every file in the WordPress core
 * manifest (wp-admin, wp-includes, top-level loader files) against the SHA256
 * checksums published at api.wordpress.org/core/checksums/1.0/. Any modified
 * or missing core file is flagged on a separate "Warning:" line.
 *
 * Importantly: this catches the canonical hostile-modification cases (PHP
 * shells dropped into wp-includes, base64 backdoors prepended to wp-load.php,
 * tampered wp-cron) that no remote scanner can see — the entire point of
 * doing this server-side rather than relying on Sucuri's HTML-render scan.
 *
 * Output shape (wp-cli 2.x, no --format flag exists for this subcommand):
 *   Warning: File doesn't verify against checksum: wp-includes/load.php
 *   Warning: File should not exist: wp-content/uploads/wp-shell.php
 *   Warning: File is missing: wp-admin/includes/file.php
 *
 * Exit codes: 0 = all files verified, non-zero = at least one warning OR
 * wp-cli could not boot. We discriminate on the output content — a non-zero
 * exit with "Warning: File" lines is a real finding (status=issues_found);
 * a non-zero exit with anything else is wp-cli failure (status=failed).
 */
class WpCoreChecksumVerifier
{
    /**
     * Files WordPress ships but that hosts and hardening guides commonly
     * remove because they leak the WP version. Their absence is intentional,
     * not tampering — silently filter them out of the "missing" list so the
     * scan doesn't flag every hardened site every week.
     *
     * - license.txt / readme.html — disclosure files; Wordfence, iThemes,
     *   and Sucuri all recommend removing them.
     * - wp-config-sample.php — sample file; not used at runtime, often
     *   stripped after install.
     */
    private const EXPECTED_ABSENT = [
        'license.txt',
        'readme.html',
        'wp-config-sample.php',
    ];

    /**
     * Path prefixes for files that WordPress ships as part of core but that
     * wp-cli's verify-checksums flags as "should not exist" because the WP-CLI
     * checksum manifest hasn't been updated to include them yet.
     *
     * wp-includes/php-ai-client/ — bundled AI Client SDK merged into WP 7.0
     *   (make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/).
     *   WP-CLI's checksums API at api.wordpress.org/core/checksums/1.0/ does not yet
     *   list these files for the 7.0 release, so they are incorrectly reported as
     *   unexpected. Remove this prefix once WP-CLI ships an updated checksum manifest.
     */
    private const KNOWN_SAFE_UNEXPECTED_PREFIXES = [
        'wp-includes/php-ai-client/',
    ];

    /**
     * WP 7.x ships `.htaccess` deny-execution stubs (blocking .php/.phtml/etc.)
     * throughout wp-includes/ as anti-webshell hardening — flagging them as
     * "should not exist" is backwards, since they're the opposite of
     * tampering. Scattered across hundreds of subdirectories, so matched by
     * pattern rather than a fixed prefix list. The WP-CLI checksum manifest
     * doesn't include them yet; remove once it does.
     */
    private const KNOWN_SAFE_UNEXPECTED_PATTERN = '#^wp-includes/(?:.*/)?\.htaccess$#';

    /** Pressable's fixed document root — see PressableCompanionInstaller::DOCROOT. */
    private const PRESSABLE_DOCROOT = '/srv/htdocs';

    public function __construct(
        private readonly SshClient $ssh,
        private readonly PressableCommandRunner $pressableRunner,
    ) {}

    public function verify(Site $site): SecurityScanResult
    {
        $started = (int) (microtime(true) * 1000);

        if (! $site->is_wordpress) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'Skipped — site is not flagged as WordPress.',
                error: 'not_wordpress',
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }

        // Dispatch on the CAP_SSH capability rather than isPressable() for
        // the root-SSH branch — this is the actual question ("does this
        // provider give root/sudo SSH against a tracked Server row"), not
        // "is it specifically SpinupWP". CAP_SSH's own contract docblock
        // ties it to server_id being populated — providers with real,
        // direct per-site SSH but no Server row (WP Engine, Kinsta) are
        // CAP_SSH=false despite genuinely having SSH, same as Pressable.
        //
        // Below that split: verifyViaPressable() stays an isPressable()
        // identity check (not capability-based) because its quirks — a
        // fixed docroot, wp-cli's --path bug on Pressable's shim — are
        // genuinely Pressable-specific, not generalizable. Everything else
        // without CAP_SSH goes through verifyViaCommandRunner(), the
        // generic path for a real (but root/Server-less) SSH-shaped
        // SiteCommandRunner.
        if (! $site->host()->supports(HostingProvider::CAP_SSH)) {
            return $site->isPressable()
                ? $this->verifyViaPressable($site, $started)
                : $this->verifyViaCommandRunner($site, $started);
        }

        if (! $site->server || $site->server->is_ignored) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'Skipped — server missing or ignored.',
                error: 'server_unavailable',
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }

        if (! $site->site_user || ! $site->server->ssh_password) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'Skipped — missing site_user or sudo password.',
                error: 'missing_credentials',
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }

        $wpPath = $site->wp_path ?: '/sites/'.$site->domain.'/files';
        $sentinel = '__CLOCKWORK_WP_EXIT__';

        // Feed the password with `echo` (which appends \n) rather than
        // `printf %s` (no trailing newline). The newline matters: sudo -S
        // reads a complete line for the password; without the terminator
        // sudo waits on EOF, and on these SpinupWP-managed Ubuntu hosts
        // wp-cli starts before sudo's stdin handler unblocks for some
        // subcommands (notably `core verify-checksums`), exiting silently
        // with empty output. WpPluginDetector hides this on the same
        // hosts only because its trailing `| grep ... || true` masks the
        // exit code. Always echo here, never printf.
        $inner = sprintf(
            'echo "$CW_SUDO_PW" | sudo -S -u %s /usr/local/bin/wp --path=%s core verify-checksums 2>&1; echo "%s:$?"',
            escapeshellarg($site->site_user),
            escapeshellarg($wpPath),
            $sentinel,
        );

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s',
            escapeshellarg((string) $site->server->ssh_password),
            escapeshellarg($inner),
        );

        try {
            // verify-checksums hashes every core file; on small VPSes this can
            // run 60-90 seconds. The default 30s read-timeout truncates output
            // and we lose the sentinel, so we extend it for this command.
            $raw = $this->ssh->exec($site->server, $cmd, 180);
        } catch (Throwable $e) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'SSH connection failed.',
                error: substr($e->getMessage(), 0, 480),
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }

        $exit = -1;
        if (preg_match('/'.$sentinel.':(\d+)/', $raw, $m)) {
            $exit = (int) $m[1];
            $raw = (string) preg_replace('/\s*'.$sentinel.':\d+\s*$/', '', $raw);
        }
        // sudo writes "[sudo] password for <user>:" to stderr even with -S;
        // strip it so the parser sees clean "Warning: File..." lines.
        $raw = (string) preg_replace('/\[sudo\] password for [^:]+:\s*/', '', $raw);
        $rawTrim = trim($raw);
        $elapsed = (int) (microtime(true) * 1000) - $started;

        return $this->parseAndRecord($site, $rawTrim, $exit, $wpPath, $elapsed);
    }

    /**
     * Pressable path: no SSH, no sudo — wp-cli runs directly via
     * PressableCommandRunner's async-command-API wrapper. One caveat vs. the
     * SSH path: that wrapper's output is `tail -c 700`'d (Pressable's activity
     * log truncates around ~1KB total), so a severely compromised site with a
     * long findings list may only show its last few warnings. A clean site
     * (empty output) or a lightly-flagged one is unaffected — this only
     * matters for exact enumeration on a badly-compromised site, where the
     * STATUS_ISSUES_FOUND flag itself still fires correctly either way.
     */
    private function verifyViaPressable(Site $site, int $started): SecurityScanResult
    {
        if ($site->pressable_site_id === null) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'Skipped — site has no pressable_site_id.',
                error: 'missing_pressable_site_id',
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }

        try {
            // Pressable's wp-cli shim doesn't honor --path= the way stock
            // wp-cli does (confirmed live: returns "This does not seem to be
            // a WordPress install" with --path, succeeds with cd) — has to
            // run from inside the docroot, matching PressableCompanionInstaller's
            // own convention for every other wp-cli call on this platform.
            $result = $this->pressableRunner->run(
                $site->pressable_site_id,
                'cd '.self::PRESSABLE_DOCROOT.' && wp core verify-checksums 2>&1',
                120,
            );
        } catch (Throwable $e) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'Pressable command failed.',
                error: substr($e->getMessage(), 0, 480),
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }

        $elapsed = (int) (microtime(true) * 1000) - $started;

        if ($result['exit'] === null) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'Pressable command did not complete.',
                details: ['output' => $result['output']],
                error: substr($result['output'], 0, 480),
                elapsedMs: $elapsed,
            );
        }

        return $this->parseAndRecord($site, trim($result['output']), $result['exit'], self::PRESSABLE_DOCROOT, $elapsed);
    }

    /**
     * Generic path for a hosting provider with real, direct per-site SSH
     * but no Server row and no root/sudo layer (e.g. WP Engine, Kinsta) —
     * SiteCommandRunner fits cleanly here specifically because there's no
     * sudo/privilege-drop wrapping needed: a real per-site SSH gateway
     * already lands you as exactly the right user, unlike SpinupWP's
     * root-then-sudo-to-site_user shape above.
     *
     * SiteCommandRunner::run() returns only a string, no exit code — the
     * sentinel-echo trick recovers one, same technique the root-SSH branch
     * above uses. This only works because SSH-shaped SiteCommandRunner
     * implementations return raw shell output regardless of exit code (see
     * SiteCommandRunner's own docblock) rather than throwing on non-zero
     * exit the way PressableApiCommandRunner does — `wp core
     * verify-checksums` deliberately exits non-zero on every real finding,
     * so a throw-on-nonzero transport would turn every finding into an
     * uncaught exception instead of a parseable result.
     *
     * Assumes the SSH session's working directory is already the site's
     * WordPress root — true for a genuinely per-site-scoped SSH gateway,
     * unlike Pressable's async command layer above (which needs an
     * explicit cd into a fixed docroot). Confirm this assumption against a
     * live account for each new provider before relying on it in
     * production — it's a reasonable default, not a verified fact.
     */
    private function verifyViaCommandRunner(Site $site, int $started): SecurityScanResult
    {
        $runner = $site->host()->commandRunner();
        if ($runner === null) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'Skipped — hosting provider has no command runner.',
                error: 'no_command_runner',
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }

        $sentinel = '__CLOCKWORK_WP_EXIT__';
        $cmd = 'wp core verify-checksums 2>&1; echo "'.$sentinel.':$?"';

        try {
            $raw = $runner->run($site, $cmd, 180);
        } catch (Throwable $e) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'Remote command failed.',
                error: substr($e->getMessage(), 0, 480),
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }

        $exit = -1;
        if (preg_match('/'.$sentinel.':(\d+)/', $raw, $m)) {
            $exit = (int) $m[1];
            $raw = (string) preg_replace('/\s*'.$sentinel.':\d+\s*$/', '', $raw);
        }
        $elapsed = (int) (microtime(true) * 1000) - $started;

        return $this->parseAndRecord($site, trim($raw), $exit, '(site root)', $elapsed);
    }

    /**
     * Shared by both transports: parses raw `wp core verify-checksums`
     * output into structured findings and builds the final SecurityScanResult.
     */
    private function parseAndRecord(Site $site, string $rawTrim, int $exit, string $wpPath, int $elapsed): SecurityScanResult
    {
        // Parse "Warning: File ..." lines into structured findings. The exact
        // wording varies by wp-cli version — match all known phrasings for
        // "missing": "File is missing:" (older) and "File doesn't exist:"
        // (newer 2.x). Without both, a stripped-readme.html site falls all
        // the way through to the "wp-cli failed" branch and gets recorded
        // as STATUS_FAILED instead of as a parseable finding.
        $modified = [];
        $missing = [];
        $shouldNotExist = [];
        foreach (preg_split('/\r?\n/', $rawTrim) as $line) {
            if (preg_match('/^(?:Warning:\s+)?File doesn\'t verify against checksum:\s*(.+)$/', $line, $m)) {
                $modified[] = trim($m[1]);
            } elseif (preg_match('/^(?:Warning:\s+)?File (?:is missing|doesn\'t exist):\s*(.+)$/', $line, $m)) {
                $missing[] = trim($m[1]);
            } elseif (preg_match('/^(?:Warning:\s+)?File should not exist:\s*(.+)$/', $line, $m)) {
                $shouldNotExist[] = trim($m[1]);
            }
        }

        // Filter expected-absent disclosure files out of "missing". They're
        // not tampering signal; their removal is a hardening practice. If
        // every parsed "missing" line falls in this list, the scan is clean
        // even though wp-cli's exit code was non-zero.
        $ignoredMissing = [];
        $missing = array_values(array_filter(
            $missing,
            function (string $f) use (&$ignoredMissing) {
                if (in_array($f, self::EXPECTED_ABSENT, true)) {
                    $ignoredMissing[] = $f;

                    return false;
                }

                return true;
            }
        ));

        // Filter known-safe "unexpected" files — legitimate WP core additions
        // that the WP-CLI checksum manifest hasn't caught up to yet.
        $ignoredUnexpected = [];
        $shouldNotExist = array_values(array_filter(
            $shouldNotExist,
            function (string $f) use (&$ignoredUnexpected) {
                foreach (self::KNOWN_SAFE_UNEXPECTED_PREFIXES as $prefix) {
                    if (str_starts_with($f, $prefix)) {
                        $ignoredUnexpected[] = $f;

                        return false;
                    }
                }

                if (preg_match(self::KNOWN_SAFE_UNEXPECTED_PATTERN, $f)) {
                    $ignoredUnexpected[] = $f;

                    return false;
                }

                return true;
            }
        ));

        $totalFlagged = count($modified) + count($missing) + count($shouldNotExist);

        if ($totalFlagged > 0) {
            return $this->result($site, SiteSecurityScan::STATUS_ISSUES_FOUND,
                modifiedFilesCount: $totalFlagged,
                summary: $this->buildSummary($modified, $missing, $shouldNotExist),
                details: [
                    'modified' => $modified,
                    'missing' => $missing,
                    'should_not_exist' => $shouldNotExist,
                    'wp_path' => $wpPath,
                    'exit' => $exit,
                ],
                elapsedMs: $elapsed,
            );
        }

        // wp-cli exits non-zero whenever ANY warning fired, including the
        // expected-absent ones we just filtered out. If the only warnings
        // we saw were ignored disclosure files or known-safe unexpected files,
        // treat the scan as clean — the genuine "wp-cli could not boot"
        // failure path still wins when we couldn't parse any warning at all.
        $sawIgnoredOnly = ($ignoredMissing !== [] || $ignoredUnexpected !== []) && $totalFlagged === 0;
        if ($exit !== 0 && ! $sawIgnoredOnly) {
            return $this->result($site, SiteSecurityScan::STATUS_FAILED,
                summary: 'wp-cli verify-checksums failed (exit '.$exit.').',
                details: ['wp_path' => $wpPath, 'exit' => $exit, 'output' => $rawTrim],
                error: $rawTrim !== '' ? substr($rawTrim, 0, 480) : 'wp_cli_nonzero_exit',
                elapsedMs: $elapsed,
            );
        }

        $ignoredParts = [];
        if ($ignoredMissing !== []) {
            $ignoredParts[] = 'expected-absent: '.implode(', ', $ignoredMissing);
        }
        if ($ignoredUnexpected !== []) {
            $ignoredParts[] = 'known-safe additions: '.count($ignoredUnexpected).' wp-includes/php-ai-client file(s)';
        }
        $summary = $sawIgnoredOnly
            ? 'All core files match WordPress.org checksums (ignored '.implode('; ', $ignoredParts).').'
            : 'All core files match WordPress.org checksums.';

        return $this->result($site, SiteSecurityScan::STATUS_CLEAN,
            summary: $summary,
            details: ['wp_path' => $wpPath, 'exit' => $exit, 'ignored_missing' => $ignoredMissing, 'ignored_unexpected' => $ignoredUnexpected],
            elapsedMs: $elapsed,
        );
    }

    /**
     * @param  array<int, string>  $modified
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $shouldNotExist
     */
    private function buildSummary(array $modified, array $missing, array $shouldNotExist): string
    {
        $parts = [];
        if ($modified !== []) {
            $parts[] = count($modified).' modified';
        }
        if ($missing !== []) {
            $parts[] = count($missing).' missing';
        }
        if ($shouldNotExist !== []) {
            $parts[] = count($shouldNotExist).' unexpected';
        }

        return 'Core file integrity issue — '.implode(', ', $parts).'.';
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function result(
        Site $site,
        string $status,
        int $modifiedFilesCount = 0,
        ?string $summary = null,
        ?array $details = null,
        ?string $error = null,
        ?int $elapsedMs = null,
    ): SecurityScanResult {
        return new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_CORE_CHECKSUMS,
            status: $status,
            modifiedFilesCount: $modifiedFilesCount,
            summary: $summary,
            details: $details,
            error: $error,
            elapsedMs: $elapsedMs,
        );
    }
}
