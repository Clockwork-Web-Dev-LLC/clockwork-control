<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\Ssh\SshClient;

/**
 * Drops a Match-loopback sshd config so DigitalOcean's Web Console can log in
 * as root via the droplet-agent on SpinupWP-hardened boxes.
 *
 * SpinupWP installs /etc/ssh/sshd_config.d/00-spinupwp.conf with
 * `PermitRootLogin no`, which blocks the agent's root SSH key injection. We
 * add /etc/ssh/sshd_config.d/99-droplet-console.conf with a Match block that
 * re-enables root key auth ONLY for connections from 127.0.0.1/::1 — the
 * loopback path the droplet-agent uses. Public root login stays disabled.
 *
 * Safety: validate via `sshd -t` BEFORE reload, use `reload` (SIGHUP) not
 * `restart`, verify post-reload state, auto-rollback on any failure.
 */
class ConsoleAccessProvisioner
{
    public function __construct(protected SshClient $ssh) {}

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

        $session->setTimeout(60);

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($this->buildScript()),
        );

        $output = (string) $session->exec($cmd);
        $session->disconnect();

        $output = preg_replace('/\[sudo\] password for [^:]+:\s*/', '', $output) ?? $output;

        if (str_contains($output, 'STATUS: already-active')) {
            return [
                'ok' => true,
                'status' => 'already_active',
                'output' => $output,
                'message' => 'Console-access drop-in already in place; loopback root login confirmed.',
            ];
        }

        if (str_contains($output, 'STATUS: provisioned')) {
            return [
                'ok' => true,
                'status' => 'provisioned',
                'output' => $output,
                'message' => 'Drop-in installed, sshd reloaded, loopback root login confirmed.',
            ];
        }

        if (str_contains($output, 'STATUS: rolled-back')) {
            return [
                'ok' => false,
                'status' => 'rolled_back',
                'output' => $output,
                'message' => 'Provisioning failed; auto-rollback succeeded — server unchanged.',
            ];
        }

        return [
            'ok' => false,
            'status' => 'failed',
            'output' => $output,
            'message' => 'Provisioning failed. Inspect output before retrying — server may be in an unknown state.',
        ];
    }

    protected function buildScript(): string
    {
        return <<<'BASH'
        #!/usr/bin/env bash
        set -u

        DROP_IN="/etc/ssh/sshd_config.d/99-droplet-console.conf"
        # Match User root (not Match Address loopback): the DO Web Console
        # mediates SSH via droplet-agent's port-knocking flow, which means
        # the actual SSH connection arrives from PUBLIC DigitalOcean IPs
        # (e.g. 162.243.0.0/16, 138.197.0.0/16), NOT from loopback. A
        # loopback-only Match block silently rejects those connections.
        #
        # The Match-User-root override allows root login by KEY ONLY
        # (prohibit-password — equivalent to without-password) for the
        # root user from any source. PasswordAuthentication stays off
        # (set globally by 00-spinupwp.conf), so this is key-only auth
        # — the same effective stance as a stock cloud-image droplet.
        #
        # Brute-force protection comes from fail2ban's sshd jail (already
        # provisioned fleet-wide) and per-server auto_ban toggles.
        WANTED=$'# Managed by clockwork:provision-console-access\n# Allows DO Web Console (root key-auth via droplet-agent port-knocking)\n# to override SpinupWP\47s PermitRootLogin no. Key auth only —\n# PasswordAuthentication stays off via the global setting.\nMatch User root\n    PermitRootLogin prohibit-password\n'

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
            echo "[console-access] ERROR: sudo authentication failed."
            exit 1
        fi

        # Detect sshd unit name (Debian/Ubuntu use "ssh"; some installs use "sshd").
        SSHD_UNIT=""
        if do_sudo systemctl cat ssh >/dev/null 2>&1; then SSHD_UNIT=ssh; fi
        if [ -z "$SSHD_UNIT" ] && do_sudo systemctl cat sshd >/dev/null 2>&1; then SSHD_UNIT=sshd; fi
        if [ -z "$SSHD_UNIT" ]; then
            echo "[console-access] ERROR: cannot find sshd systemd unit (tried ssh, sshd)"
            exit 1
        fi
        echo "[console-access] sshd unit: $SSHD_UNIT"

        # Pre-flight: sshd must currently be active.
        if ! do_sudo systemctl is-active --quiet "$SSHD_UNIT"; then
            echo "[console-access] ERROR: $SSHD_UNIT not active before changes — aborting."
            exit 1
        fi

        # Idempotency check.
        if [ -f "$DROP_IN" ]; then
            CURRENT=$(do_sudo cat "$DROP_IN" 2>/dev/null || true)
            if [ "$CURRENT" = "$WANTED" ]; then
                # Verify the Match block is effective for a public root SSH attempt.
                EFF=$(do_sudo sshd -T -C "addr=8.8.8.8,user=root,host=public.example,laddr=127.0.0.1,lport=22" 2>/dev/null | awk '$1=="permitrootlogin"{print $2}')
                if [ "$EFF" = "prohibit-password" ] || [ "$EFF" = "without-password" ]; then
                    echo "[console-access] drop-in already correct; effective PermitRootLogin (public,root) = $EFF"
                    echo "[console-access] STATUS: already-active"
                    exit 0
                fi
                echo "[console-access] drop-in present but not effective (got '$EFF'); will rewrite + reload."
            fi
        fi

        # Capture BEFORE state. We're enabling Match User root → prohibit-password,
        # so the public root-login result is EXPECTED to widen from 'no' to
        # 'prohibit-password' on hardened boxes. The safety bar is: never
        # allow password auth (no 'yes'), never allow non-root user changes.
        permit_score() {
            case "$1" in
                no) echo 0 ;;
                forced-commands-only) echo 1 ;;
                prohibit-password|without-password) echo 2 ;;
                yes) echo 3 ;;
                *) echo 99 ;;
            esac
        }
        EFF_ROOT_BEFORE=$(do_sudo sshd -T -C "addr=8.8.8.8,user=root,host=public.example,laddr=127.0.0.1,lport=22" 2>/dev/null | awk '$1=="permitrootlogin"{print $2}')
        # Capture global PasswordAuthentication too — must stay 'no' after our change.
        PWD_AUTH_BEFORE=$(do_sudo sshd -T 2>/dev/null | awk '$1=="passwordauthentication"{print $2}')
        echo "[console-access] BEFORE root-login(public)=$EFF_ROOT_BEFORE passwordauth=$PWD_AUTH_BEFORE"

        # Snapshot any existing file so we can restore byte-for-byte.
        BACKUP=""
        if [ -f "$DROP_IN" ]; then
            BACKUP="${DROP_IN}.bak.$$"
            do_sudo cp -a "$DROP_IN" "$BACKUP"
            echo "[console-access] backed up existing drop-in to $BACKUP"
        fi

        rollback() {
            echo "[console-access] rolling back..."
            do_sudo rm -f "$DROP_IN"
            if [ -n "$BACKUP" ] && [ -f "$BACKUP" ]; then
                do_sudo mv "$BACKUP" "$DROP_IN"
                echo "[console-access] restored prior $DROP_IN from $BACKUP"
            else
                echo "[console-access] no prior file existed; removed our addition"
            fi
            # Validate before reloading the rolled-back state.
            if do_sudo sshd -t 2>&1; then
                do_sudo systemctl reload "$SSHD_UNIT" 2>&1 || true
            else
                echo "[console-access] WARNING: sshd -t fails on rolled-back state too — not reloading."
            fi
            echo "[console-access] STATUS: rolled-back"
        }

        # Write the drop-in via stage file. We can't pipe content through
        # `do_sudo tee` because do_sudo's `printf %s "$CW_SUDO_PW" | sudo -S`
        # consumes the upstream stdin pipe to feed sudo its password — the
        # actual content never reaches tee. Stage as clockwork-deploy (no sudo
        # needed for /tmp), then sudo cp into place.
        STAGE=$(mktemp /tmp/droplet-console.XXXXXX.conf)
        printf '%s' "$WANTED" > "$STAGE"
        SIZE=$(stat -c %s "$STAGE")
        echo "[console-access] staged content: $STAGE ($SIZE bytes)"
        if [ "$SIZE" -lt 50 ]; then
            echo "[console-access] ERROR: stage file is too small — content not written correctly. Aborting before touching $DROP_IN."
            rm -f "$STAGE"
            exit 1
        fi
        do_sudo cp "$STAGE" "$DROP_IN"
        do_sudo chmod 644 "$DROP_IN"
        do_sudo chown root:root "$DROP_IN"
        rm -f "$STAGE"

        # Validate the merged config BEFORE reloading the daemon.
        if ! do_sudo sshd -t 2>&1; then
            echo "[console-access] ERROR: sshd -t failed — config would be invalid."
            rollback
            exit 1
        fi
        echo "[console-access] sshd -t: OK"

        # Reload (SIGHUP) — never restart. Existing sessions survive even if
        # the new config were bad (we already validated, so it isn't).
        if ! do_sudo systemctl reload "$SSHD_UNIT" 2>&1; then
            echo "[console-access] ERROR: systemctl reload $SSHD_UNIT failed."
            rollback
            exit 1
        fi
        echo "[console-access] reloaded $SSHD_UNIT"

        # Confirm sshd is still active.
        if ! do_sudo systemctl is-active --quiet "$SSHD_UNIT"; then
            echo "[console-access] ERROR: $SSHD_UNIT no longer active after reload."
            rollback
            exit 1
        fi

        # Verify the Match block took effect: root login (from any source)
        # must now be prohibit-password or without-password (key-only auth).
        # NOT 'yes' (would allow password too) and not 'no' (Match block inert).
        EFF_ROOT_AFTER=$(do_sudo sshd -T -C "addr=8.8.8.8,user=root,host=public.example,laddr=127.0.0.1,lport=22" 2>/dev/null | awk '$1=="permitrootlogin"{print $2}')
        PWD_AUTH_AFTER=$(do_sudo sshd -T 2>/dev/null | awk '$1=="passwordauthentication"{print $2}')

        echo "[console-access] AFTER root-login(public)=$EFF_ROOT_AFTER passwordauth=$PWD_AUTH_AFTER"

        # Match block must have widened root login to key-auth.
        if [ "$EFF_ROOT_AFTER" != "prohibit-password" ] && [ "$EFF_ROOT_AFTER" != "without-password" ]; then
            echo "[console-access] ERROR: root login is '$EFF_ROOT_AFTER' — expected prohibit-password (Match block inert or wrong config)."
            rollback
            exit 1
        fi

        # PasswordAuthentication MUST still be off — that's the security floor.
        # If our drop-in somehow flipped it on, refuse.
        if [ "$PWD_AUTH_AFTER" != "no" ]; then
            echo "[console-access] ERROR: PasswordAuthentication is '$PWD_AUTH_AFTER' — expected 'no'. Refusing to leave server with password auth enabled."
            rollback
            exit 1
        fi

        # Sanity: refuse if we somehow ended up at 'yes' (would allow password root login).
        if [ "$EFF_ROOT_AFTER" = "yes" ]; then
            echo "[console-access] ERROR: root login set to 'yes' — would allow password auth. Refusing."
            rollback
            exit 1
        fi

        echo "[console-access] root key-auth enabled, password auth still disabled — DO Web Console should now work."

        # Success — clean up backup.
        if [ -n "$BACKUP" ] && [ -f "$BACKUP" ]; then
            do_sudo rm -f "$BACKUP"
        fi

        echo "[console-access] STATUS: provisioned"
        BASH;
    }
}
