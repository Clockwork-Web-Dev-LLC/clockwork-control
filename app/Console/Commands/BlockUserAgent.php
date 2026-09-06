<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshClient;
use Illuminate\Console\Command;

class BlockUserAgent extends Command
{
    protected $signature = 'clockwork:block-ua
        {--server= : Target server (name, hostname, or ID). Required.}
        {--ua=* : One or more User-Agent substrings to block (regex-safe literals). Defaults to the Chrome/126.0.0.0 scraper.}
        {--remove : Remove the UA block instead of writing it.}
        {--dry-run : Print the nginx snippets without touching any server.}';

    protected $description = 'Push nginx User-Agent blocking rules to a server. Writes a map directive to /etc/nginx/conf.d/clockwork-ua-block.conf (http context) and per-site if-return-403 snippets into each site\'s .d include directory. Safe: nginx -t is run before any reload.';

    /** @var list<string> */
    private array $defaultUas = [
        'Chrome/126.0.0.0',
    ];

    public function handle(SshClient $ssh): int
    {
        $serverOpt = $this->option('server');
        if (! $serverOpt) {
            $this->error('--server is required.');

            return self::FAILURE;
        }

        $server = Server::query()
            ->where('id', is_numeric($serverOpt) ? (int) $serverOpt : 0)
            ->orWhere('name', $serverOpt)
            ->orWhere('hostname', $serverOpt)
            ->first();

        if (! $server) {
            $this->error("No server found matching: {$serverOpt}");

            return self::FAILURE;
        }

        $remove = (bool) $this->option('remove');
        $uaPatterns = $this->option('ua') ?: $this->defaultUas;

        $sites = Site::where('server_id', $server->id)->get(['id', 'domain']);

        if ($sites->isEmpty()) {
            $this->warn("No sites found on server {$server->name}.");

            return self::SUCCESS;
        }

        $mapSnippet = $remove ? '' : $this->buildMapSnippet($uaPatterns);
        $siteSnippet = $remove ? '' : $this->buildSiteSnippet();

        if ($this->option('dry-run')) {
            $this->line("Server: {$server->name} ({$server->hostname})");
            $this->line('Sites: '.$sites->pluck('domain')->join(', '));
            $this->line('');
            if ($remove) {
                $this->line('Would remove: /etc/nginx/conf.d/clockwork-ua-block.conf');
                foreach ($sites as $site) {
                    $this->line("Would remove: [site {$site->domain}]/.d/clockwork-ua-block.conf");
                }
            } else {
                $this->line('--- /etc/nginx/conf.d/clockwork-ua-block.conf ---');
                $this->line($mapSnippet);
                $this->line('--- per-site .d/clockwork-ua-block.conf ---');
                $this->line($siteSnippet);
            }

            return self::SUCCESS;
        }

        if (! $server->ssh_password) {
            $this->error("Server {$server->name} has no sudo password stored.");

            return self::FAILURE;
        }

        $domains = $sites->pluck('domain')->all();
        $script = $this->buildScript($mapSnippet, $siteSnippet, $domains, $remove);

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($script),
        );

        $this->line("Connecting to {$server->name}…");

        try {
            $output = $ssh->exec($server, $cmd, 60);
        } catch (\Throwable $e) {
            $this->error('SSH failed: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach (explode("\n", trim($output)) as $line) {
            $this->line('  '.$line);
        }

        if (str_contains($output, 'STATUS: ok')) {
            $this->info($remove ? 'UA block removed and nginx reloaded.' : 'UA block applied and nginx reloaded.');

            return self::SUCCESS;
        }

        if (str_contains($output, 'STATUS: unchanged')) {
            $this->info('Already up to date — no reload needed.');

            return self::SUCCESS;
        }

        $this->error('Script did not report success. Review output above.');

        return self::FAILURE;
    }

    /** @param list<string> $patterns */
    private function buildMapSnippet(array $patterns): string
    {
        $lines = [
            '# Managed by Clockwork — clockwork:block-ua',
            '# Maps User-Agent to $clockwork_ua_blocked. Per-site configs use this',
            '# variable to return 403. Update with: php artisan clockwork:block-ua --server=<name>',
            '',
            'map $http_user_agent $clockwork_ua_blocked {',
            '    default 0;',
        ];

        foreach ($patterns as $pattern) {
            // Escape for use in a PCRE-style nginx regex (~* prefix = case-insensitive).
            $escaped = preg_quote($pattern, '~');
            $lines[] = "    ~*{$escaped} 1;";
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function buildSiteSnippet(): string
    {
        return implode("\n", [
            '# Managed by Clockwork — clockwork:block-ua',
            'if ($clockwork_ua_blocked) {',
            '    return 403;',
            '}',
            '',
        ]);
    }

    /**
     * Build the bash script that pushes both snippets to the server.
     *
     * @param  list<string>  $domains
     */
    private function buildScript(
        string $mapSnippet,
        string $siteSnippet,
        array $domains,
        bool $remove,
    ): string {
        $mapSnippetEscaped = $mapSnippet;
        $siteSnippetEscaped = $siteSnippet;
        $domainsJson = json_encode($domains);

        // Build the domain list as a bash array literal.
        $domainsBash = 'DOMAINS=(';
        foreach ($domains as $d) {
            $domainsBash .= ' '.escapeshellarg($d);
        }
        $domainsBash .= ' )';

        $removeFlag = $remove ? '1' : '0';

        return <<<BASH
        #!/usr/bin/env bash
        set -e

        REMOVE={$removeFlag}
        {$domainsBash}

        if sudo -n true 2>/dev/null; then
            do_sudo() { sudo "\$@"; }
        else
            do_sudo() { printf '%s\n' "\$CW_SUDO_PW" | sudo -S -p '' "\$@"; }
        fi

        # Detect where to put the map block — it must be in the http context.
        # SpinupWP servers vary: some include conf.d/*.conf, others do not.
        if grep -qE "include.*conf\.d" /etc/nginx/nginx.conf 2>/dev/null; then
            MAP_TARGET=/etc/nginx/conf.d/clockwork-ua-block.conf
        else
            MAP_TARGET=/etc/nginx/sites-enabled/clockwork-ua-map.conf
        fi

        STAGE=\$(mktemp -d)
        trap "rm -rf \$STAGE" EXIT

        if [ "\$REMOVE" = "1" ]; then
            # ── Remove mode ────────────────────────────────────────────────────
            CHANGED=0

            # Check both possible map locations.
            for MPATH in "/etc/nginx/conf.d/clockwork-ua-block.conf" "/etc/nginx/sites-enabled/clockwork-ua-map.conf"; do
                if [ -f "\$MPATH" ]; then
                    do_sudo rm -f "\$MPATH"
                    echo "[cw-block-ua] Removed \$MPATH"
                    CHANGED=1
                fi
            done

            for DOMAIN in "\${DOMAINS[@]}"; do
                # Try all known SpinupWP include-dir patterns.
                for EXTRA_D in \
                    "/etc/nginx/sites-available/\${DOMAIN}.d" \
                    "/etc/nginx/extra.d/\${DOMAIN}" \
                    "/etc/nginx/sites-available/\${DOMAIN}/server"; do
                    SITE_CONF="\${EXTRA_D}/clockwork-ua-block.conf"
                    if [ -f "\$SITE_CONF" ]; then
                        do_sudo rm -f "\$SITE_CONF"
                        echo "[cw-block-ua] Removed \$SITE_CONF"
                        CHANGED=1
                    fi
                done
            done

            if [ "\$CHANGED" = "1" ]; then
                if ! do_sudo nginx -t 2>&1; then
                    echo "ERROR: nginx -t failed after removal."
                    exit 1
                fi
                do_sudo systemctl reload nginx
                echo "STATUS: ok"
            else
                echo "STATUS: unchanged"
            fi
            exit 0
        fi

        # ── Write mode ─────────────────────────────────────────────────────────
        CHANGED=0

        # 1) Map snippet → /etc/nginx/conf.d/clockwork-ua-block.conf
        cat > "\$STAGE/map.conf" <<'MAP_EOF'
        {$mapSnippetEscaped}
        MAP_EOF

        if do_sudo cmp -s "\$STAGE/map.conf" "\$MAP_TARGET" 2>/dev/null; then
            echo "[cw-block-ua] Map snippet unchanged."
        else
            do_sudo cp "\$STAGE/map.conf" "\$MAP_TARGET"
            do_sudo chmod 644 "\$MAP_TARGET"
            do_sudo chown root:root "\$MAP_TARGET"
            echo "[cw-block-ua] Wrote \$MAP_TARGET"
            CHANGED=1
        fi

        # 2) Per-site if-block snippets
        cat > "\$STAGE/site.conf" <<'SITE_EOF'
        {$siteSnippetEscaped}
        SITE_EOF

        for DOMAIN in "\${DOMAINS[@]}"; do
            EXTRA_D=""

            # Detect which include-dir pattern SpinupWP uses for this site.
            # Candidates in priority order: legacy .d suffix, extra.d/, server/ subdir.
            for CANDIDATE in \
                "/etc/nginx/sites-available/\${DOMAIN}.d" \
                "/etc/nginx/extra.d/\${DOMAIN}" \
                "/etc/nginx/sites-available/\${DOMAIN}/server"; do
                if [ -d "\$CANDIDATE" ]; then
                    EXTRA_D="\$CANDIDATE"
                    break
                fi
            done

            if [ -z "\$EXTRA_D" ]; then
                echo "[cw-block-ua] WARNING: Could not find include dir for \${DOMAIN} — skipping site-level block."
                continue
            fi

            SITE_TARGET="\${EXTRA_D}/clockwork-ua-block.conf"

            if do_sudo cmp -s "\$STAGE/site.conf" "\$SITE_TARGET" 2>/dev/null; then
                echo "[cw-block-ua] Site snippet unchanged for \${DOMAIN}."
            else
                do_sudo mkdir -p "\$EXTRA_D"
                do_sudo cp "\$STAGE/site.conf" "\$SITE_TARGET"
                do_sudo chmod 644 "\$SITE_TARGET"
                do_sudo chown root:root "\$SITE_TARGET"
                echo "[cw-block-ua] Wrote \$SITE_TARGET"
                CHANGED=1
            fi
        done

        # 3) Validate and reload only if something changed.
        if [ "\$CHANGED" = "1" ]; then
            if ! do_sudo nginx -t 2>&1; then
                echo "ERROR: nginx -t failed. Reverting map snippet."
                do_sudo rm -f "\$MAP_TARGET"
                exit 1
            fi
            do_sudo systemctl reload nginx
            echo "STATUS: ok"
        else
            echo "STATUS: unchanged"
        fi
        BASH;
    }
}
