<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Fail2ban\IgnoreIpListBuilder;
use App\Services\Ssh\SshClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class RefreshFail2banIgnoreip extends Command
{
    protected $signature = 'clockwork:refresh-fail2ban-ignoreip
        {--server= : Limit to a specific server (name, hostname, or ID)}
        {--dry-run : Show the list and the targets without touching servers}';

    protected $description = "Push a fresh fail2ban ignoreip list (Cloudflare edge ranges + every server in our fleet's own public IP) to /etc/fail2ban/jail.d/clockwork.local on every provisioned server. Reloads fail2ban so changes take effect without restarting other jails. Scheduled weekly because CF ranges change rarely but do change.";

    public function handle(IgnoreIpListBuilder $builder, SshClient $ssh): int
    {
        $entries = $builder->build();
        $renderedList = $builder->render();
        $this->info('Ignoreip list contains '.count($entries).' entries ('.strlen($renderedList).' chars).');

        $servers = $this->targetServers();
        if ($servers->isEmpty()) {
            $this->warn('No provisioned servers match.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run — showing the list but not touching servers:');
            foreach ($entries as $e) {
                $this->line('  '.$e);
            }
            $this->line('');
            $this->line('Target servers ('.$servers->count().'):');
            foreach ($servers as $s) {
                $this->line("  {$s->name}");
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

            $result = $this->pushTo($server, $renderedList, $ssh);
            if ($result['ok']) {
                $ok++;
            } else {
                $failed++;
                $bar->clear();
                $this->warn("  ✗ {$server->name}: {$result['message']}");
                if ($this->getOutput()->isVerbose()) {
                    foreach (explode("\n", trim($result['output'])) as $line) {
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
     * Build a small bash script that updates the jail file's ignoreip line in
     * place (sed) and reloads only the clockwork jail. Sed-in-place is simpler
     * than rewriting the whole file and avoids racing with any local edits.
     *
     * @return array{ok: bool, output: string, message: string}
     */
    private function pushTo(Server $server, string $renderedList, SshClient $ssh): array
    {
        if (! $server->ssh_password) {
            return ['ok' => false, 'output' => '', 'message' => 'No sudo password stored.'];
        }

        // We rewrite the entire jail file AND the filter file rather than try to
        // sed-in-place. Two reasons: (1) we own the full content so re-rendering
        // is safe, and (2) avoids the do_sudo + outer-pipe footgun that bit the
        // v0 provisioner. Stage to tempfiles, sudo cp them, reload.
        //
        // The filter rewrite is load-bearing on older boxes: any server provisioned
        // before the <HOST> fix was added has a filter that reload() will reject
        // ('No failure-id group'), unloading the jail. Refresh repairs that drift.
        $script = <<<'BASH'
        #!/usr/bin/env bash
        set -e

        if sudo -n true 2>/dev/null; then
            do_sudo() { sudo "$@"; }
        else
            do_sudo() { printf '%s\n' "$CW_SUDO_PW" | sudo -S -p '' "$@"; }
        fi

        JAIL=/etc/fail2ban/jail.d/clockwork.local
        FILTER=/etc/fail2ban/filter.d/clockwork.conf

        STAGE=$(mktemp -d)
        trap "rm -rf $STAGE" EXIT

        # Filter that never matches but satisfies fail2ban's <HOST> requirement.
        cat > "$STAGE/clockwork.conf" <<'FILTER_EOF'
        [Definition]
        failregex = ^__CLOCKWORK_NEVER_MATCHES__ <HOST>$
        ignoreregex =
        FILTER_EOF
        do_sudo cp "$STAGE/clockwork.conf" "$FILTER"
        do_sudo chmod 644 "$FILTER"
        do_sudo chown root:root "$FILTER"

        # Re-render the full jail config with the fresh ignoreip.
        cat > "$STAGE/clockwork.local" <<'JAIL_EOF'
        [clockwork]
        enabled = true
        filter = clockwork
        action = iptables-allports[name=clockwork]
        maxretry = 999999
        findtime = 1
        bantime = 86400
        logpath = /dev/null
        backend = polling
        ignoreip = __CLOCKWORK_IGNOREIP_LIST__
        JAIL_EOF
        do_sudo cp "$STAGE/clockwork.local" "$JAIL"
        do_sudo chmod 644 "$JAIL"
        do_sudo chown root:root "$JAIL"

        # Reload only the clockwork jail. Cheaper than systemctl restart and doesn't
        # disturb the sshd jail or any other jails on the box.
        do_sudo fail2ban-client reload clockwork >/dev/null 2>&1 || true

        # If reload failed (e.g. the jail wasn't loaded — happens on boxes whose
        # last reload errored out and unloaded the jail), bring it back with
        # `add` + `start`. fail2ban-client reload-on-restart-of-fail2ban has
        # always been a footgun.
        if ! do_sudo fail2ban-client status clockwork >/dev/null 2>&1; then
            echo "Jail not active after reload, restarting fail2ban..."
            do_sudo systemctl restart fail2ban
            sleep 2
        fi

        # Verify.
        if ! do_sudo fail2ban-client status clockwork >/dev/null 2>&1; then
            echo "ERROR: clockwork jail still not active after restart."
            do_sudo tail -20 /var/log/fail2ban.log 2>/dev/null || true
            exit 1
        fi

        ACTIVE=$(do_sudo fail2ban-client get clockwork ignoreip 2>/dev/null | tr -d '\r' || true)
        echo "STATUS: refreshed"
        echo "active ignoreip preview: $(echo "$ACTIVE" | head -c 200)"
        BASH;

        $script = str_replace('__CLOCKWORK_IGNOREIP_LIST__', $renderedList, $script);

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

        $ok = str_contains($output, 'STATUS: refreshed');

        return [
            'ok' => $ok,
            'output' => $output,
            'message' => $ok ? 'refreshed' : 'refresh did not report success',
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
