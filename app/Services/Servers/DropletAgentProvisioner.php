<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\Ssh\SshClient;

/**
 * Ensures DigitalOcean's `droplet-agent` is installed and running on a droplet.
 *
 * The DO Web Console (cloud.digitalocean.com/droplets/<id>/terminal/ui/) speaks
 * to this daemon: it generates a short-lived keypair, writes the public key to
 * root's authorized_keys, then SSHes in. Without the agent, the console fails
 * with "All configured authentication methods failed". SpinupWP-provisioned
 * droplets do not install the agent by default.
 */
class DropletAgentProvisioner
{
    public function __construct(protected SshClient $ssh) {}

    /**
     * Restart the agent — useful when the daemon is wedged in a stale state
     * (e.g. console session hangs at "Registering SSH Keys") and a clean
     * restart re-establishes its DO control-plane connection.
     *
     * @return array{ok: bool, status: string, output: string, message: string}
     */
    public function restart(Server $server): array
    {
        try {
            $session = $this->ssh->connect($server);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 'ssh_failed',
                'output' => '',
                'message' => 'SSH connect failed: '.$e->getMessage(),
            ];
        }

        $session->setTimeout(60);

        $script = <<<'BASH'
        #!/usr/bin/env bash
        set -e
        if sudo -n true 2>/dev/null; then
            SUDO_NEEDS_PASSWORD=0
        else
            SUDO_NEEDS_PASSWORD=1
        fi
        do_sudo() {
            if [ "$SUDO_NEEDS_PASSWORD" = "1" ]; then
                printf '%s\n' "$CW_SUDO_PW" | sudo -S -p '' "$@"
            else
                sudo "$@"
            fi
        }
        if ! do_sudo -n true 2>/dev/null && ! do_sudo true; then
            echo "[droplet-agent] ERROR: sudo authentication failed."
            exit 1
        fi
        do_sudo systemctl restart droplet-agent 2>&1
        sleep 2
        if do_sudo systemctl is-active --quiet droplet-agent; then
            echo "[droplet-agent] STATUS: restarted"
            exit 0
        fi
        echo "[droplet-agent] STATUS: restart-failed"
        exit 1
        BASH;

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($script),
        );

        $output = (string) $session->exec($cmd);
        $session->disconnect();

        $output = preg_replace('/\[sudo\] password for [^:]+:\s*/', '', $output) ?? $output;

        if (str_contains($output, 'STATUS: restarted')) {
            return ['ok' => true, 'status' => 'restarted', 'output' => $output, 'message' => 'droplet-agent restarted.'];
        }

        return ['ok' => false, 'status' => 'restart_failed', 'output' => $output, 'message' => 'restart failed.'];
    }

    /**
     * @return array{ok: bool, status: string, output: string, message: string}
     */
    public function provision(Server $server): array
    {
        try {
            $session = $this->ssh->connect($server);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 'ssh_failed',
                'output' => '',
                'message' => 'SSH connect failed: '.$e->getMessage(),
            ];
        }

        $session->setTimeout(120);

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($this->buildScript()),
        );

        $output = (string) $session->exec($cmd);
        $session->disconnect();

        $output = preg_replace('/\[sudo\] password for [^:]+:\s*/', '', $output) ?? $output;

        if (str_contains($output, 'STATUS: already-active')) {
            $status = 'already_active';
            $ok = true;
        } elseif (str_contains($output, 'STATUS: provisioned')) {
            $status = 'provisioned';
            $ok = true;
        } elseif (str_contains($output, 'STATUS: ssh-restart-required')) {
            $status = 'ssh_restart_required';
            $ok = true;
        } else {
            $status = 'failed';
            $ok = false;
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'output' => $output,
            'message' => match ($status) {
                'already_active' => 'droplet-agent already installed and active.',
                'provisioned' => 'droplet-agent installed and active.',
                'ssh_restart_required' => 'Installed; sshd restart needed (manual).',
                default => 'Provisioning failed. See output for details.',
            },
        ];
    }

    protected function buildScript(): string
    {
        return <<<'BASH'
        #!/usr/bin/env bash
        set -e

        echo "[droplet-agent] starting on $(hostname)"

        if sudo -n true 2>/dev/null; then
            SUDO_NEEDS_PASSWORD=0
        else
            SUDO_NEEDS_PASSWORD=1
        fi

        do_sudo() {
            if [ "$SUDO_NEEDS_PASSWORD" = "1" ]; then
                printf '%s\n' "$CW_SUDO_PW" | sudo -S -p '' "$@"
            else
                sudo "$@"
            fi
        }

        if ! do_sudo -n true 2>/dev/null && ! do_sudo true; then
            echo "[droplet-agent] ERROR: sudo authentication failed."
            exit 1
        fi

        # Fast path: service already active.
        if do_sudo systemctl is-active --quiet droplet-agent 2>/dev/null; then
            echo "[droplet-agent] service is active"
            do_sudo systemctl status droplet-agent --no-pager 2>&1 | head -3 || true
            echo "[droplet-agent] STATUS: already-active"
            exit 0
        fi

        # If the binary is present but the service is inactive, just enable+start.
        if [ -x /opt/digitalocean/bin/droplet-agent ] && do_sudo systemctl cat droplet-agent >/dev/null 2>&1; then
            echo "[droplet-agent] binary + unit present, enabling and starting"
            do_sudo systemctl enable droplet-agent >/dev/null 2>&1 || true
            do_sudo systemctl start droplet-agent
            sleep 2
            if do_sudo systemctl is-active --quiet droplet-agent; then
                echo "[droplet-agent] service started successfully"
                echo "[droplet-agent] STATUS: provisioned"
                exit 0
            fi
            echo "[droplet-agent] start failed; falling through to reinstall"
        fi

        # Install via DO's official installer.
        echo "[droplet-agent] installing via DO installer"
        export DEBIAN_FRONTEND=noninteractive

        # Make sure curl/wget exists.
        if ! command -v wget >/dev/null 2>&1 && ! command -v curl >/dev/null 2>&1; then
            do_sudo env DEBIAN_FRONTEND=noninteractive apt-get update -y >/dev/null
            do_sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y wget >/dev/null
        fi

        STAGE=$(mktemp -d)
        trap "rm -rf $STAGE" EXIT
        if command -v wget >/dev/null 2>&1; then
            wget -qO "$STAGE/install.sh" https://repos-droplet.digitalocean.com/install.sh
        else
            curl -fsSL -o "$STAGE/install.sh" https://repos-droplet.digitalocean.com/install.sh
        fi

        if [ ! -s "$STAGE/install.sh" ]; then
            echo "[droplet-agent] ERROR: failed to download installer"
            exit 1
        fi

        do_sudo bash "$STAGE/install.sh" 2>&1 | tail -30 || true

        do_sudo systemctl enable droplet-agent >/dev/null 2>&1 || true
        do_sudo systemctl start droplet-agent >/dev/null 2>&1 || true
        sleep 2

        if do_sudo systemctl is-active --quiet droplet-agent; then
            echo "[droplet-agent] service is active"
            echo "[droplet-agent] STATUS: provisioned"
            exit 0
        fi

        echo "[droplet-agent] ERROR: service did not start"
        do_sudo systemctl status droplet-agent --no-pager 2>&1 | tail -20 || true
        exit 1
        BASH;
    }
}
