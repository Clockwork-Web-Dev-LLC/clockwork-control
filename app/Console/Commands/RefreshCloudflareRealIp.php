<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Cloudflare\CloudflareDetector;
use App\Services\Ssh\SshClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class RefreshCloudflareRealIp extends Command
{
    protected $signature = 'clockwork:refresh-cloudflare-real-ip
        {--server= : Limit to a specific server (name, hostname, or ID)}
        {--dry-run : Show the snippet and the targets without touching servers}';

    protected $description = "Push an nginx snippet to /etc/nginx/conf.d/clockwork-cloudflare-real-ip.conf on every server AND bridge it into sites-enabled/ so nginx actually loads it. The snippet trusts Cloudflare edge IPs and rewrites REMOTE_ADDR to CF-Connecting-IP, so PHP, fail2ban, LLAR, Wordfence, and the traffic rollup's visit count all see the actual visitor — not the CF edge. Safe to apply fleet-wide; non-CF requests are unaffected because non-CF visitors don't carry the CF-Connecting-IP header.";

    public function handle(CloudflareDetector $cf, SshClient $ssh): int
    {
        $snippet = $this->buildSnippet($cf);
        $this->info('Snippet is '.strlen($snippet).' bytes ('.substr_count($snippet, "\n").' lines).');

        $servers = $this->targetServers();
        if ($servers->isEmpty()) {
            $this->warn('No servers match.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line('--- snippet ---');
            $this->line($snippet);
            $this->line('--- targets ('.$servers->count().') ---');
            foreach ($servers as $s) {
                $this->line('  '.$s->name);
            }

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($servers->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();

        foreach ($servers as $server) {
            $bar->setMessage($server->name);
            $r = $this->pushTo($server, $snippet, $ssh);
            if ($r['ok']) {
                $ok++;
            } else {
                $failed++;
                $bar->clear();
                $this->warn("  ✗ {$server->name}: {$r['message']}");
                if ($this->getOutput()->isVerbose()) {
                    foreach (explode("\n", trim($r['output'])) as $line) {
                        $this->line('     '.$line);
                    }
                }
                $bar->display();
            }
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info("Done. ok={$ok}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Render the nginx snippet. set_real_ip_from for every CF v4+v6 range so
     * nginx only trusts CF-Connecting-IP when the connection genuinely came
     * from a CF edge — non-CF clients can't spoof the header.
     */
    private function buildSnippet(CloudflareDetector $cf): string
    {
        $lines = [
            '# Managed by Clockwork — clockwork:refresh-cloudflare-real-ip',
            '# Trusts Cloudflare edge IPs and rewrites REMOTE_ADDR to the real visitor IP',
            '# from CF-Connecting-IP. Without this, fail2ban/LLAR/Wordfence end up',
            '# banning Cloudflare itself instead of attackers (which causes 521 outages).',
            '',
        ];
        foreach ($cf->ranges() as $cidr) {
            $lines[] = "set_real_ip_from {$cidr};";
        }
        foreach ($cf->rangesV6() as $cidr) {
            $lines[] = "set_real_ip_from {$cidr};";
        }
        $lines[] = '';
        $lines[] = 'real_ip_header CF-Connecting-IP;';
        $lines[] = 'real_ip_recursive on;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return array{ok: bool, output: string, message: string}
     */
    private function pushTo(Server $server, string $snippet, SshClient $ssh): array
    {
        if (! $server->ssh_password) {
            return ['ok' => false, 'output' => '', 'message' => 'No sudo password stored.'];
        }

        // Stage to a tempfile (owned by us) → sudo cp into place. Do nginx -t
        // BEFORE reloading so a bad snippet doesn't take the server down.
        //
        // The conf.d file alone is INERT on SpinupWP boxes: their nginx.conf
        // includes sites-enabled/* (at http context) but never conf.d/*. So
        // we also bridge the snippet into sites-enabled via a 000-prefixed
        // symlink — the prefix sorts it first in the alphabetical glob, so the
        // real_ip directives are established at http scope before any server{}
        // block. conf.d stays the single source of truth; the symlink just
        // makes nginx read it. Without the bridge, fail2ban/LLAR and the
        // traffic visit count all saw Cloudflare edge IPs (clientsite.example,
        // 2026-07-15).
        $script = <<<'BASH'
        #!/usr/bin/env bash
        set -e

        if sudo -n true 2>/dev/null; then
            do_sudo() { sudo "$@"; }
        else
            do_sudo() { printf '%s\n' "$CW_SUDO_PW" | sudo -S -p '' "$@"; }
        fi

        TARGET=/etc/nginx/conf.d/clockwork-cloudflare-real-ip.conf
        BRIDGE=/etc/nginx/sites-enabled/000-clockwork-cloudflare-real-ip.conf
        STAGE=$(mktemp -d)
        trap "rm -rf $STAGE" EXIT

        cat > "$STAGE/snippet.conf" <<'NGINX_EOF'
        __CLOCKWORK_NGINX_SNIPPET__
        NGINX_EOF

        CONTENT_CHANGED=1
        if do_sudo cmp -s "$STAGE/snippet.conf" "$TARGET" 2>/dev/null; then
            CONTENT_CHANGED=0
        fi

        # Bridge is correct only if it's a symlink resolving to $TARGET.
        BRIDGE_OK=0
        if [ -L "$BRIDGE" ] && [ "$(readlink "$BRIDGE" 2>/dev/null)" = "$TARGET" ]; then
            BRIDGE_OK=1
        fi

        # Is real-IP restoration ALREADY active in the running nginx — via our
        # bridge, an older include-style sites-enabled file, or a SpinupWP
        # per-site before/ include with its own inline directives? We match the
        # real_ip_header DIRECTIVE (anchored, so comments don't count), not our
        # file path, because those alternate bridges define the directive
        # themselves rather than including our snippet. Adding a second bridge
        # on top of any of them makes real_ip_header duplicate and fails
        # nginx -t, so when one already works we leave it alone.
        ALREADY_LOADED=0
        if do_sudo nginx -T 2>/dev/null | grep -qE '^[[:space:]]*real_ip_header[[:space:]]'; then
            ALREADY_LOADED=1
        fi

        # Nothing to do: content matches and the snippet is already active
        # (via our bridge or an external one).
        if [ "$CONTENT_CHANGED" = "0" ] && { [ "$BRIDGE_OK" = "1" ] || [ "$ALREADY_LOADED" = "1" ]; }; then
            echo "STATUS: unchanged"
            exit 0
        fi

        # Backup + write content only when it actually changed. Validate
        # BEFORE reloading so a bad snippet never reaches a running nginx.
        # (An external bridge includes $TARGET, so refreshing its content and
        # reloading keeps CF ranges current there too — without a second link.)
        if [ "$CONTENT_CHANGED" = "1" ]; then
            do_sudo cp -n "$TARGET" "$TARGET.bak" 2>/dev/null || true
            do_sudo cp "$STAGE/snippet.conf" "$TARGET"
            do_sudo chmod 644 "$TARGET"
            do_sudo chown root:root "$TARGET"
        fi

        # Add our sites-enabled bridge ONLY when the snippet isn't already
        # loaded — otherwise we'd duplicate real_ip_header. Track whether we
        # created it so we can undo exactly that on a validation failure.
        BRIDGE_ADDED=0
        if [ "$ALREADY_LOADED" = "0" ] && [ "$BRIDGE_OK" = "0" ]; then
            do_sudo ln -sfn "$TARGET" "$BRIDGE"
            BRIDGE_ADDED=1
        fi

        if ! do_sudo nginx -t 2>&1; then
            echo "ERROR: nginx -t failed. Reverting."
            if [ "$CONTENT_CHANGED" = "1" ] && [ -f "$TARGET.bak" ]; then
                do_sudo cp "$TARGET.bak" "$TARGET"
            fi
            if [ "$BRIDGE_ADDED" = "1" ]; then
                do_sudo rm -f "$BRIDGE"
            fi
            exit 1
        fi

        do_sudo systemctl reload nginx
        echo "STATUS: refreshed"
        BASH;

        $script = str_replace('__CLOCKWORK_NGINX_SNIPPET__', $snippet, $script);

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($script),
        );

        try {
            $output = $ssh->exec($server, $cmd);
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'message' => 'SSH connect failed: '.$e->getMessage()];
        }

        $unchanged = str_contains($output, 'STATUS: unchanged');
        $refreshed = str_contains($output, 'STATUS: refreshed');
        $ok = $unchanged || $refreshed;

        return [
            'ok' => $ok,
            'output' => $output,
            'message' => $unchanged ? 'unchanged' : ($refreshed ? 'refreshed' : 'unknown failure'),
        ];
    }

    /** @return Collection<int, Server> */
    private function targetServers()
    {
        $q = Server::query()
            ->monitored()
            ->whereNotNull('clockwork_jail_provisioned_at');

        if ($needle = $this->option('server')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', is_numeric($needle) ? (int) $needle : 0)
                    ->orWhere('name', $needle)
                    ->orWhere('hostname', $needle);
            });
        }

        return $q->orderBy('name')->get();
    }
}
