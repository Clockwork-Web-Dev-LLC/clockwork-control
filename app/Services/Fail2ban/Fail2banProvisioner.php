<?php

namespace App\Services\Fail2ban;

use App\Models\Server;
use App\Services\Ssh\SshClient;
use Illuminate\Support\Carbon;

class Fail2banProvisioner
{
    public function __construct(
        protected SshClient $ssh,
        protected IgnoreIpListBuilder $ignoreIpList,
    ) {}

    /**
     * Install fail2ban (if missing) and the clockwork jail + sudoers entry on a server.
     * Idempotent: subsequent runs detect existing setup and only re-verify.
     *
     * @return array{ok: bool, output: string, already_provisioned: bool, message: string}
     */
    public function provision(Server $server): array
    {
        try {
            $session = $this->ssh->connect($server);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'output' => '',
                'already_provisioned' => false,
                'message' => 'SSH connect failed: '.$e->getMessage(),
            ];
        }

        $session->setTimeout(120);

        // Inject the live whitelist (CF ranges + our own server IPs) into the jail
        // template via placeholder so the nowdoc below stays literal-safe.
        $script = str_replace(
            '__CLOCKWORK_IGNOREIP_LIST__',
            $this->ignoreIpList->render(),
            $this->buildScript(),
        );
        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($script),
        );

        $output = (string) $session->exec($cmd);
        $session->disconnect();

        $alreadyProvisioned = str_contains($output, 'STATUS: already-provisioned');
        $provisioned = str_contains($output, 'STATUS: provisioned') || $alreadyProvisioned;

        if ($provisioned) {
            $server->clockwork_jail_provisioned_at = Carbon::now();
        }
        $server->last_provision_log = $output;
        $server->save();

        return [
            'ok' => $provisioned,
            'output' => $output,
            'already_provisioned' => $alreadyProvisioned,
            'message' => $provisioned
                ? ($alreadyProvisioned ? 'Already provisioned. Verified jail is active.' : 'Provisioned successfully.')
                : 'Provisioning failed. See log output for details.',
        ];
    }

    protected function buildScript(): string
    {
        // The CW_SUDO_PW env var is supplied by the PHP caller via the SSH command line.
        // KEY DESIGN POINT: we never put a heredoc directly on a do_sudo command.
        // do_sudo internally pipes the password to sudo via stdin, so any heredoc
        // attached to the outer call gets discarded and replaced by the password.
        // Always: 1) write content to a tempfile (no sudo needed), 2) sudo cp it in.
        return <<<'BASH'
        #!/usr/bin/env bash
        set -e

        echo "[clockwork] Starting provisioning on $(hostname) as $(whoami)"

        if sudo -n true 2>/dev/null; then
            SUDO_NEEDS_PASSWORD=0
            echo "[clockwork] sudo is NOPASSWD"
        else
            SUDO_NEEDS_PASSWORD=1
            echo "[clockwork] sudo requires password - using stored SSH password"
        fi

        do_sudo() {
            if [ "$SUDO_NEEDS_PASSWORD" = "1" ]; then
                printf '%s\n' "$CW_SUDO_PW" | sudo -S -p '' "$@"
            else
                sudo "$@"
            fi
        }

        if ! do_sudo -n true 2>/dev/null && ! do_sudo true; then
            echo "[clockwork] ERROR: sudo authentication failed."
            exit 1
        fi

        # Quick path: already provisioned.
        if [ -f /etc/fail2ban/jail.d/clockwork.local ] && [ -f /etc/sudoers.d/clockwork ]; then
            echo "[clockwork] Existing config detected. Verifying jail is active..."
            if do_sudo fail2ban-client status clockwork > /dev/null 2>&1; then
                echo "[clockwork] Jail 'clockwork' is loaded:"
                do_sudo fail2ban-client status clockwork
                echo "[clockwork] STATUS: already-provisioned"
                exit 0
            fi
            echo "[clockwork] Jail config exists but not active - re-applying."
        fi

        # 1) Install fail2ban if missing.
        if ! command -v fail2ban-client > /dev/null 2>&1; then
            echo "[clockwork] Installing fail2ban via apt-get..."
            do_sudo env DEBIAN_FRONTEND=noninteractive apt-get update -y >/dev/null
            do_sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y fail2ban >/dev/null
        else
            echo "[clockwork] fail2ban already installed: $(fail2ban-client --version 2>/dev/null | head -1)"
        fi

        # Stage all configs in tempfiles owned by us, then sudo cp into place.
        STAGE=$(mktemp -d)
        trap "rm -rf $STAGE" EXIT

        # 2) Filter that never matches.
        # fail2ban >=1.0 requires every failregex to include a <HOST> capture group,
        # even if the regex is intended to never match. The literal prefix ensures
        # nothing in /dev/null could ever satisfy this pattern.
        cat > "$STAGE/clockwork.conf" <<'FILTER_EOF'
        [Definition]
        failregex = ^__CLOCKWORK_NEVER_MATCHES__ <HOST>$
        ignoreregex =
        FILTER_EOF
        echo "[clockwork] Installing filter.d/clockwork.conf"
        do_sudo cp "$STAGE/clockwork.conf" /etc/fail2ban/filter.d/clockwork.conf
        do_sudo chmod 644 /etc/fail2ban/filter.d/clockwork.conf
        do_sudo chown root:root /etc/fail2ban/filter.d/clockwork.conf

        # 3) Jail definition.
        # ignoreip is injected by Clockwork — Cloudflare edge ranges + every
        # server in the fleet's own public IP. Without it, LLAR lockouts that
        # appear to come from CF edges (because that's what nginx sees as
        # REMOTE_ADDR on a CF-proxied site) will ban CF itself, killing the
        # site behind 521s. Refreshed by clockwork:refresh-fail2ban-ignoreip.
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
        echo "[clockwork] Installing jail.d/clockwork.local"
        do_sudo cp "$STAGE/clockwork.local" /etc/fail2ban/jail.d/clockwork.local
        do_sudo chmod 644 /etc/fail2ban/jail.d/clockwork.local
        do_sudo chown root:root /etc/fail2ban/jail.d/clockwork.local

        # 4) Sudoers — write to tempfile, validate with visudo, then install.
        # ASCII-only content; no fancy punctuation that older sudoers parsers may reject.
        ME=$(whoami)
        cat > "$STAGE/clockwork-sudoers" <<SUDOERS_EOF
        # Managed by Clockwork - do not edit
        $ME ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client
        SUDOERS_EOF

        if ! do_sudo visudo -cf "$STAGE/clockwork-sudoers" >/dev/null; then
            echo "[clockwork] ERROR: generated sudoers file failed visudo validation. Aborting."
            echo "[clockwork] --- staged file ---"
            cat "$STAGE/clockwork-sudoers"
            echo "[clockwork] --- end ---"
            exit 1
        fi

        echo "[clockwork] Installing sudoers.d/clockwork for user '$ME'"
        do_sudo cp "$STAGE/clockwork-sudoers" /etc/sudoers.d/clockwork
        do_sudo chmod 440 /etc/sudoers.d/clockwork
        do_sudo chown root:root /etc/sudoers.d/clockwork

        # 5) Restart fail2ban and verify.
        echo "[clockwork] Restarting fail2ban service"
        do_sudo systemctl restart fail2ban
        sleep 2

        echo "[clockwork] Verifying jail is loaded:"
        do_sudo fail2ban-client status clockwork

        echo "[clockwork] STATUS: provisioned"
        BASH;
    }
}
