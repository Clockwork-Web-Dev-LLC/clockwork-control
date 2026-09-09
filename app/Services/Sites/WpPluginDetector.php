<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Ssh\SshClient;

class WpPluginDetector
{
    public const RESULT_DETECTED = 'detected';

    public const RESULT_FAILED = 'failed';

    public const RESULT_SKIPPED = 'skipped';

    /** Plugin slugs we care about right now. */
    private const SLUGS = [
        'llar' => 'limit-login-attempts-reloaded',
        'wordfence' => 'wordfence',
    ];

    public function __construct(private readonly SshClient $ssh) {}

    /**
     * SSH into a site, ask wp-cli which of our tracked security plugins are
     * active, and write the result to llar_enabled / wordfence_enabled +
     * wp_plugins_detected_at.
     *
     * "Active" — not "installed" — is the right semantic: an installed-but-
     * inactive plugin isn't doing anything for the site.
     *
     * @return array{result: string, message: string, llar: ?bool, wordfence: ?bool}
     */
    public function detect(Site $site): array
    {
        if (! $site->is_wordpress) {
            return ['result' => self::RESULT_SKIPPED, 'message' => 'Not a WordPress site.', 'llar' => null, 'wordfence' => null];
        }
        if (! $site->server || $site->server->is_ignored) {
            return ['result' => self::RESULT_SKIPPED, 'message' => 'No server / server is ignored.', 'llar' => null, 'wordfence' => null];
        }
        if (! $site->site_user || ! $site->server->ssh_password) {
            return ['result' => self::RESULT_FAILED, 'message' => 'Missing site_user or sudo password.', 'llar' => null, 'wordfence' => null];
        }

        $wpPath = $site->resolveWpPath();
        if ($wpPath === null) {
            return ['result' => self::RESULT_FAILED, 'message' => 'No wp_path recorded and this hosting provider has no known on-disk convention.', 'llar' => null, 'wordfence' => null];
        }

        // One wp-cli invocation lists every active plugin matching either slug.
        // Single SSH connect, single wp-cli boot — much cheaper than two probes.
        $slugList = implode('\n', array_values(self::SLUGS));
        $wpArgs = sprintf(
            'plugin list --status=active --field=name --format=csv 2>&1 | grep -xE %s || true',
            escapeshellarg('('.implode('|', array_map('preg_quote', self::SLUGS)).')'),
        );

        $sentinel = '__CLOCKWORK_WP_EXIT__';
        // `echo` (not `printf %s`) for the sudo password feed — printf omits
        // the trailing newline, so sudo -S waits on EOF rather than seeing a
        // complete line, and some wp-cli subcommands start before sudo's
        // stdin handler unblocks, producing empty output. This detector's
        // own `| grep ... || true` used to mask that as a clean "nothing
        // active" result instead of a failure — confirmed live 2026-09-09 on
        // a client site, which reported llar_enabled=false while LLAR
        // was demonstrably active in wp-admin. `-p ""` suppresses sudo's
        // password prompt so it can't leak into the piped stdout. See
        // CompanionInstaller::runAsSiteUser()'s docblock for the same fix.
        $inner = sprintf(
            'echo "$CW_SUDO_PW" | sudo -S -p "" -u %s /usr/local/bin/wp --path=%s %s; echo "%s:$?"',
            escapeshellarg($site->site_user),
            escapeshellarg($wpPath),
            $wpArgs,
            $sentinel,
        );

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s',
            escapeshellarg((string) $site->server->ssh_password),
            escapeshellarg($inner),
        );

        $raw = $this->ssh->exec($site->server, $cmd);

        $exit = -1;
        if (preg_match('/'.$sentinel.':(\d+)/', $raw, $m)) {
            $exit = (int) $m[1];
            $raw = (string) preg_replace('/\s*'.$sentinel.':\d+\s*$/', '', $raw);
        }

        if ($exit !== 0) {
            return [
                'result' => self::RESULT_FAILED,
                'message' => 'wp-cli probe failed (exit '.$exit.').',
                'llar' => null,
                'wordfence' => null,
                'output' => trim($raw),
            ];
        }

        // grep prints one matching slug per line. Empty output = neither active.
        $activeSlugs = array_filter(array_map('trim', explode("\n", $raw)));
        $llar = in_array(self::SLUGS['llar'], $activeSlugs, true);
        $wordfence = in_array(self::SLUGS['wordfence'], $activeSlugs, true);

        $site->llar_enabled = $llar;
        $site->wordfence_enabled = $wordfence;
        $site->wp_plugins_detected_at = now();
        $site->save();

        return [
            'result' => self::RESULT_DETECTED,
            'message' => sprintf('LLAR=%s, Wordfence=%s.', $llar ? 'on' : 'off', $wordfence ? 'on' : 'off'),
            'llar' => $llar,
            'wordfence' => $wordfence,
        ];
    }
}
