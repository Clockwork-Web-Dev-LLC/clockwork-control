<?php

namespace App\Services\Uptime;

use App\Models\Site;
use App\Services\Ssh\SshClient;

/**
 * SSH-based diagnostic that runs on the up→down transition for 5xx failures.
 * Answers "why is this site returning 5xx?" with a single round-trip so the
 * Mattermost alert and event row can say "SpinupWP maintenance mode is active"
 * instead of just "HTTP 503".
 *
 * Checks (SpinupWP layout):
 *   - /etc/nginx/sites-available/{site-slug}/server/maintenance.conf non-empty
 *     → SpinupWP put the site in maintenance mode (file holds `return 503;`).
 *   - /run/php/php*-{site_user}.sock exists → FPM pool is up.
 *   - php*-fpm.service is-active → the FPM master itself is up.
 *   - /proc/loadavg + nproc → contextualise an overloaded box.
 *   - tail of /sites/{site-slug}/logs/error.log → last few real-time errors.
 *
 * {site-slug} is SpinupWP's on-disk folder name for the site, which is NOT
 * always the same as Site.domain (e.g. a site onboarded as
 * "www.example.com" keeps that folder name even though Site.domain stores
 * "example.com"). Derived from Site.wp_path (".../{site-slug}/files"),
 * falling back to domain only when wp_path is unset.
 *
 * All file paths and the service-name pattern are best-effort and degrade
 * silently if the box isn't SpinupWP-shaped. The diagnosis never throws —
 * failures (no SSH, no sudo, etc.) come back as a `error` key on the result.
 */
class UptimeDiagnostician
{
    public function __construct(private readonly SshClient $ssh) {}

    /**
     * @return array{
     *     maintenance_mode: bool,
     *     maintenance_excerpt: ?string,
     *     fpm_socket_present: ?bool,
     *     fpm_socket_path: ?string,
     *     fpm_service: ?string,
     *     fpm_active: ?string,
     *     loadavg: ?array{0: float, 1: float, 2: float},
     *     cores: ?int,
     *     recent_errors: ?string,
     *     summary: string,
     *     error: ?string,
     * }
     */
    public function diagnose(Site $site): array
    {
        $empty = $this->emptyResult();

        $server = $site->server;
        if ($server === null) {
            return [...$empty, 'error' => 'site has no associated server', 'summary' => 'No server linked — cannot diagnose.'];
        }
        if (($site->site_user ?? '') === '') {
            return [...$empty, 'error' => 'site has no site_user set', 'summary' => 'No site_user on site row — cannot diagnose.'];
        }

        try {
            $session = $this->ssh->connect($server);
        } catch (\Throwable $e) {
            return [
                ...$empty,
                'error' => $e->getMessage(),
                'summary' => 'Could not SSH to server — origin may be unreachable.',
            ];
        }

        $session->setTimeout(15);

        $siteSlug = $site->wp_path
            ? basename(dirname($site->wp_path))
            : $site->domain;

        $cmd = sprintf(
            'CW_SITE_SLUG=%s CW_SITE_USER=%s bash -c %s 2>&1',
            escapeshellarg($siteSlug),
            escapeshellarg((string) $site->site_user),
            escapeshellarg($this->buildScript()),
        );

        try {
            $output = (string) $session->exec($cmd);
        } catch (\Throwable $e) {
            $session->disconnect();

            return [
                ...$empty,
                'error' => $e->getMessage(),
                'summary' => 'SSH connect succeeded but exec failed — server may be overloaded.',
            ];
        }
        $session->disconnect();

        $result = $this->parse($output);
        $result['summary'] = $this->summarize($result);

        return $result;
    }

    /**
     * Single-roundtrip script. Section markers let parsing tolerate stderr
     * noise inside any one check (a missing log file shouldn't void the rest).
     */
    private function buildScript(): string
    {
        return <<<'BASH'
        #!/usr/bin/env bash
        # Inputs: CW_SITE_SLUG, CW_SITE_USER (set by caller).

        echo "===MAINT==="
        MAINT_FILE="/etc/nginx/sites-available/${CW_SITE_SLUG}/server/maintenance.conf"
        if [ -f "$MAINT_FILE" ]; then
            # Cap at 500 bytes — the file is normally `return 503;` (12 bytes)
            # but a custom maintenance page could include HTML.
            head -c 500 "$MAINT_FILE" 2>/dev/null
        fi

        echo ""
        echo "===FPM_SOCK==="
        # Glob returns the literal pattern if no match — filter that out.
        for f in /run/php/php*-"${CW_SITE_USER}".sock; do
            if [ -S "$f" ]; then
                echo "$f"
                break
            fi
        done

        echo "===FPM_SERVICE==="
        # `list-units` only shows loaded units; if FPM is masked or never installed
        # we get an empty line, which the parser treats as "unknown".
        systemctl list-units --no-legend --type=service 'php*-fpm.service' 2>/dev/null \
            | awk '{print $1}' | head -1

        echo "===FPM_ACTIVE==="
        FPM_SVC=$(systemctl list-units --no-legend --type=service 'php*-fpm.service' 2>/dev/null \
            | awk '{print $1}' | head -1)
        if [ -n "$FPM_SVC" ]; then
            systemctl is-active "$FPM_SVC" 2>/dev/null
        fi

        echo "===LOADAVG==="
        cat /proc/loadavg 2>/dev/null

        echo "===CORES==="
        nproc 2>/dev/null || echo 1

        echo "===SITE_ERR==="
        # SpinupWP convention: /sites/{site-slug}/logs/error.log. The log is
        # owned by the site_user; clockwork-deploy can read it (it's group-readable
        # in the default layout). If permissions are tighter, we just get
        # nothing.
        tail -10 "/sites/${CW_SITE_SLUG}/logs/error.log" 2>/dev/null

        echo "===END==="
        BASH;
    }

    /**
     * @return array{
     *     maintenance_mode: bool,
     *     maintenance_excerpt: ?string,
     *     fpm_socket_present: ?bool,
     *     fpm_socket_path: ?string,
     *     fpm_service: ?string,
     *     fpm_active: ?string,
     *     loadavg: ?array{0: float, 1: float, 2: float},
     *     cores: ?int,
     *     recent_errors: ?string,
     *     summary: string,
     *     error: ?string,
     * }
     */
    private function parse(string $output): array
    {
        $sections = $this->splitSections($output);

        $maintRaw = trim($sections['MAINT'] ?? '');
        $maintenanceMode = $maintRaw !== '';

        $fpmSockPath = trim($sections['FPM_SOCK'] ?? '');
        $fpmSocketPresent = $fpmSockPath !== '';

        $fpmService = trim($sections['FPM_SERVICE'] ?? '');
        $fpmActive = trim($sections['FPM_ACTIVE'] ?? '');

        $loadavg = null;
        $loadRaw = trim($sections['LOADAVG'] ?? '');
        if ($loadRaw !== '') {
            $parts = preg_split('/\s+/', $loadRaw) ?: [];
            if (count($parts) >= 3) {
                $loadavg = [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
            }
        }

        $cores = (int) trim($sections['CORES'] ?? '0');
        if ($cores < 1) {
            $cores = null;
        }

        $recentErrors = trim($sections['SITE_ERR'] ?? '');
        if ($recentErrors === '') {
            $recentErrors = null;
        } else {
            // Cap at ~2KB so the payload stays sane in JSON/Mattermost.
            $recentErrors = mb_strimwidth($recentErrors, 0, 2000, '…');
        }

        return [
            'maintenance_mode' => $maintenanceMode,
            'maintenance_excerpt' => $maintenanceMode ? mb_strimwidth($maintRaw, 0, 200, '…') : null,
            'fpm_socket_present' => $fpmSocketPresent,
            'fpm_socket_path' => $fpmSockPath !== '' ? $fpmSockPath : null,
            'fpm_service' => $fpmService !== '' ? $fpmService : null,
            'fpm_active' => $fpmActive !== '' ? $fpmActive : null,
            'loadavg' => $loadavg,
            'cores' => $cores,
            'recent_errors' => $recentErrors,
            'summary' => '',
            'error' => null,
        ];
    }

    /**
     * Headline string surfaced in alerts. Ordered by specificity — the most
     * actionable signal wins.
     *
     * @param  array<string, mixed>  $r
     */
    private function summarize(array $r): string
    {
        if ($r['maintenance_mode']) {
            return 'Maintenance mode is active (SpinupWP-style layout) — '
                .'/etc/nginx/sites-available/{site-slug}/server/maintenance.conf '
                .'returns 503. Disable maintenance mode or truncate the file.';
        }

        if ($r['fpm_socket_present'] === false) {
            return 'PHP-FPM pool socket is missing — the backend for this site is not running. '
                .'Restart the pool: sudo systemctl restart '
                .($r['fpm_service'] ?: 'php-fpm');
        }

        if (in_array($r['fpm_active'], ['failed', 'inactive'], true)) {
            return 'PHP-FPM service is '.$r['fpm_active'].' ('.($r['fpm_service'] ?: 'unknown service').'). '
                .'Restart with: sudo systemctl restart '.($r['fpm_service'] ?: 'php-fpm');
        }

        // Heavy-load heuristic: 1-min load avg > 4× cores is a sustained
        // overload that will be making FPM time out. Lower thresholds give
        // too many false positives on bursty traffic.
        $loadavg = $r['loadavg'];
        $cores = $r['cores'];
        if (is_array($loadavg) && is_int($cores) && $cores > 0 && $loadavg[0] > $cores * 4) {
            return sprintf(
                'Server load is %.1f on %d cores — origin is overloaded, FPM workers likely timing out.',
                $loadavg[0],
                $cores,
            );
        }

        return 'Origin reachable, no obvious cause detected — inspect the site error log tail.';
    }

    /**
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^===([A-Z_]+)===$/', $line, $m)) {
                $current = $m[1];
                $sections[$current] = '';

                continue;
            }
            if ($current !== null) {
                $sections[$current] .= $line."\n";
            }
        }

        return $sections;
    }

    /**
     * @return array{
     *     maintenance_mode: bool,
     *     maintenance_excerpt: ?string,
     *     fpm_socket_present: ?bool,
     *     fpm_socket_path: ?string,
     *     fpm_service: ?string,
     *     fpm_active: ?string,
     *     loadavg: ?array{0: float, 1: float, 2: float},
     *     cores: ?int,
     *     recent_errors: ?string,
     *     summary: string,
     *     error: ?string,
     * }
     */
    private function emptyResult(): array
    {
        return [
            'maintenance_mode' => false,
            'maintenance_excerpt' => null,
            'fpm_socket_present' => null,
            'fpm_socket_path' => null,
            'fpm_service' => null,
            'fpm_active' => null,
            'loadavg' => null,
            'cores' => null,
            'recent_errors' => null,
            'summary' => '',
            'error' => null,
        ];
    }
}
