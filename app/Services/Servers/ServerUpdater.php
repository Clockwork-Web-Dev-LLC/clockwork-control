<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\Ssh\SshClient;

/**
 * Run `apt-get update && upgrade && autoremove` over SSH, optionally schedule a reboot.
 *
 * Mirrors the Fail2banProvisioner pattern: a self-contained bash script staged on the
 * remote host, with sudo password supplied via an env var so it never appears on the
 * command line or inside heredocs.
 *
 * Long-running by design — apt-get upgrade can take 1–3 minutes depending on the package
 * set. Caller should run this off the request path (e.g. via clockwork:process-server-updates).
 */
class ServerUpdater
{
    public function __construct(protected SshClient $ssh) {}

    /**
     * @return array{
     *     ok: bool,
     *     output: string,
     *     reboot_required_after: bool,
     *     reboot_scheduled: bool,
     *     nginx_state_after: string,
     *     message: string,
     * }
     */
    public function update(Server $server, ?string $rebootAtHHMM = null, bool $alwaysReboot = false): array
    {
        try {
            $session = $this->ssh->connect($server);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'output' => '',
                'reboot_required_after' => false,
                'reboot_scheduled' => false,
                'nginx_state_after' => 'unknown',
                'message' => 'SSH connect failed: '.$e->getMessage(),
            ];
        }

        // apt-get upgrade can take a few minutes on a busy mirror day. Bump the timeout
        // way up so we don't kill it mid-install (which would leave dpkg in a bad state).
        $session->setTimeout(600);

        $rebootArg = '';
        if ($rebootAtHHMM !== null && preg_match('/^\d{1,2}:\d{2}$/', $rebootAtHHMM)) {
            $rebootArg = $rebootAtHHMM;
        }

        $script = $this->buildScript();
        $cmd = sprintf(
            'CW_SUDO_PW=%s CW_REBOOT_AT=%s CW_ALWAYS_REBOOT=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($rebootArg),
            $alwaysReboot ? '1' : '0',
            escapeshellarg($script),
        );

        $output = (string) $session->exec($cmd);
        $session->disconnect();

        $aptOk = str_contains($output, 'STATUS: update-complete');
        $rebootRequiredAfter = str_contains($output, 'REBOOT_REQUIRED: yes');
        $rebootScheduled = str_contains($output, 'REBOOT_SCHEDULED: yes');

        // NGINX_POST_UPGRADE sentinel surfaces whether nginx survived the upgrade.
        // The nginx postinst can swallow start failures on major-version jumps
        // (e.g. 1.28 → 1.30), so apt-success ≠ nginx-running. We treat a failed
        // post-upgrade nginx state as a hard failure even when apt itself succeeded.
        $nginxState = match (true) {
            str_contains($output, 'NGINX_POST_UPGRADE: active') => 'active',
            str_contains($output, 'NGINX_POST_UPGRADE: recovered') => 'recovered',
            str_contains($output, 'NGINX_POST_UPGRADE: failed') => 'failed',
            str_contains($output, 'NGINX_POST_UPGRADE: absent') => 'absent',
            default => 'unknown',
        };

        $ok = $aptOk && $nginxState !== 'failed';

        $message = match (true) {
            ! $aptOk => 'Update failed. See log for details.',
            $nginxState === 'failed' => 'Update completed but nginx is not running. See log for details.',
            $nginxState === 'recovered' => 'Update complete (nginx had to be restarted after the upgrade).',
            $rebootScheduled => "Update complete. Reboot scheduled for {$rebootArg} server-local time.",
            $rebootRequiredAfter => 'Update complete. Reboot still pending.',
            default => 'Update complete.',
        };

        return [
            'ok' => $ok,
            'output' => $output,
            'reboot_required_after' => $rebootRequiredAfter,
            'reboot_scheduled' => $rebootScheduled,
            'nginx_state_after' => $nginxState,
            'message' => $message,
        ];
    }

    protected function buildScript(): string
    {
        // CW_SUDO_PW + CW_REBOOT_AT are passed via env. Sentinel strings (STATUS:, REBOOT_REQUIRED:,
        // REBOOT_SCHEDULED:) tell the PHP side what happened — easier than exit-code parsing
        // when there are multiple post-conditions to convey.
        return <<<'BASH'
        #!/usr/bin/env bash
        set -e

        echo "[clockwork-update] Starting on $(hostname) as $(whoami)"

        if sudo -n true 2>/dev/null; then
            SUDO_PREFIX="sudo"
            echo "[clockwork-update] sudo is NOPASSWD"
        else
            if [ -z "${CW_SUDO_PW:-}" ]; then
                echo "[clockwork-update] sudo requires a password but CW_SUDO_PW is empty — aborting"
                exit 1
            fi
            SUDO_PREFIX='sudo -S'
            echo "[clockwork-update] sudo will be invoked with stored password"
        fi

        # Wrapper so we never embed the password literal into the script body.
        do_sudo() {
            if [ "$SUDO_PREFIX" = "sudo" ]; then
                sudo -E "$@"
            else
                printf '%s\n' "$CW_SUDO_PW" | sudo -SE "$@"
            fi
        }

        export DEBIAN_FRONTEND=noninteractive

        echo "[clockwork-update] apt-get update"
        do_sudo apt-get update -qq

        echo "[clockwork-update] apt-get dist-upgrade"
        do_sudo apt-get -y -o Dpkg::Options::="--force-confold" -o Dpkg::Options::="--force-confdef" dist-upgrade

        echo "[clockwork-update] apt-get autoremove"
        do_sudo apt-get -y autoremove

        # nginx postinst can return success even when the new daemon failed to
        # start — major version jumps (e.g. 1.28 → 1.30) bit the whole fleet on
        # 2026-05-16. Self-heal by attempting a single restart, then surface
        # the truth to the PHP caller via the NGINX_POST_UPGRADE sentinel.
        if systemctl list-unit-files nginx.service >/dev/null 2>&1; then
            NGINX_STATE="$(systemctl is-active nginx 2>&1 || true)"
            if [ "$NGINX_STATE" = "active" ]; then
                echo "NGINX_POST_UPGRADE: active"
            else
                echo "[clockwork-update] nginx is '$NGINX_STATE' after upgrade — attempting reset-failed + start"
                do_sudo systemctl reset-failed nginx >/dev/null 2>&1 || true
                if do_sudo systemctl start nginx; then
                    NGINX_STATE="$(systemctl is-active nginx 2>&1 || true)"
                    if [ "$NGINX_STATE" = "active" ]; then
                        echo "NGINX_POST_UPGRADE: recovered"
                    else
                        echo "NGINX_POST_UPGRADE: failed (still $NGINX_STATE after start)"
                    fi
                else
                    echo "NGINX_POST_UPGRADE: failed (start command exited non-zero)"
                fi
            fi
        else
            echo "NGINX_POST_UPGRADE: absent"
        fi

        if [ "${CW_ALWAYS_REBOOT:-0}" = "1" ] || [ -f /var/run/reboot-required ]; then
            if [ -f /var/run/reboot-required ]; then
                echo "REBOOT_REQUIRED: yes"
            else
                echo "REBOOT_REQUIRED: no"
            fi

            if [ -n "${CW_REBOOT_AT:-}" ]; then
                echo "[clockwork-update] scheduling reboot for ${CW_REBOOT_AT} server-local time"
                # 'shutdown -r HH:MM' interprets a time in the past as "tomorrow" — that's
                # the OS behavior; we surface the chosen time to the user upstream.
                do_sudo shutdown -r "${CW_REBOOT_AT}" "Clockwork-scheduled reboot for kernel/security update" || true
                echo "REBOOT_SCHEDULED: yes"
            else
                # No explicit time set — default to "reboot immediately."
                # +1 (one-minute delay) instead of "now" so the SSH session has time
                # to flush output and disconnect cleanly before the box drops; without
                # the grace window the upstream PHP runner can lose the tail of the
                # apt log mid-read. Empty CW_REBOOT_AT means "bounce it" — set CW_REBOOT_AT explicitly
                # if you want a scheduled window instead.
                echo "[clockwork-update] rebooting in +1 minute"
                do_sudo shutdown -r +1 "Clockwork auto-reboot after updates" || true
                echo "REBOOT_SCHEDULED: yes"
            fi
        else
            echo "REBOOT_REQUIRED: no"
            echo "REBOOT_SCHEDULED: no"
        fi

        echo "STATUS: update-complete"
        BASH;
    }

    /**
     * Schedule (or trigger) a reboot. Decoupled from the apt-get path — caller may want
     * to reboot a box that's already up-to-date but has /var/run/reboot-required from a
     * prior unattended-upgrade.
     *
     * $atHHMM = null   → reboot in ~1 minute (`shutdown -r +1`). The +1 gives SSH time
     *                    to return cleanly before the box drops.
     * $atHHMM = "HH:MM" → schedule for that server-local clock time. shutdown(8) treats
     *                    a past time as "tomorrow" — same behaviour as the update path.
     *
     * @return array{ok: bool, output: string, message: string, scheduled_for: ?string}
     */
    public function reboot(Server $server, ?string $atHHMM = null): array
    {
        if ($atHHMM !== null && ! preg_match('/^\d{1,2}:\d{2}$/', $atHHMM)) {
            return [
                'ok' => false,
                'output' => '',
                'message' => "Invalid time format: {$atHHMM} (expected HH:MM)",
                'scheduled_for' => null,
            ];
        }

        try {
            $session = $this->ssh->connect($server);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'output' => '',
                'message' => 'SSH connect failed: '.$e->getMessage(),
                'scheduled_for' => null,
            ];
        }

        $session->setTimeout(30);

        $when = $atHHMM ?? '+1';
        $script = $this->buildRebootScript();
        $cmd = sprintf(
            'CW_SUDO_PW=%s CW_REBOOT_WHEN=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($when),
            escapeshellarg($script),
        );

        $output = (string) $session->exec($cmd);
        $session->disconnect();

        $ok = str_contains($output, 'STATUS: reboot-scheduled');

        return [
            'ok' => $ok,
            'output' => $output,
            'message' => $ok
                ? ($atHHMM === null
                    ? "Reboot starting in ~1 minute on {$server->name}."
                    : "Reboot scheduled for {$atHHMM} server-local on {$server->name}.")
                : "Reboot command failed on {$server->name}.",
            'scheduled_for' => $when,
        ];
    }

    /**
     * @return array{ok: bool, output: string, message: string}
     */
    public function cancelReboot(Server $server): array
    {
        try {
            $session = $this->ssh->connect($server);
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'message' => 'SSH connect failed: '.$e->getMessage()];
        }

        $session->setTimeout(15);

        $script = <<<'BASH'
        #!/usr/bin/env bash
        if sudo -n true 2>/dev/null; then
            sudo shutdown -c 2>&1 || true
        else
            printf '%s\n' "$CW_SUDO_PW" | sudo -S shutdown -c 2>&1 || true
        fi
        echo "STATUS: cancel-issued"
        BASH;

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($script),
        );

        $output = (string) $session->exec($cmd);
        $session->disconnect();

        return [
            'ok' => str_contains($output, 'STATUS: cancel-issued'),
            'output' => $output,
            'message' => "Cancel issued on {$server->name}.",
        ];
    }

    protected function buildRebootScript(): string
    {
        return <<<'BASH'
        #!/usr/bin/env bash
        set -e

        if sudo -n true 2>/dev/null; then
            SUDO_PREFIX="sudo"
        else
            if [ -z "${CW_SUDO_PW:-}" ]; then
                echo "[clockwork-reboot] sudo requires a password but CW_SUDO_PW is empty — aborting"
                exit 1
            fi
            SUDO_PREFIX='sudo -S'
        fi

        do_sudo() {
            if [ "$SUDO_PREFIX" = "sudo" ]; then
                sudo "$@"
            else
                printf '%s\n' "$CW_SUDO_PW" | sudo -S "$@"
            fi
        }

        if [ -z "${CW_REBOOT_WHEN:-}" ]; then
            echo "[clockwork-reboot] no time supplied — aborting"
            exit 1
        fi

        echo "[clockwork-reboot] scheduling reboot for ${CW_REBOOT_WHEN} on $(hostname)"
        do_sudo shutdown -r "${CW_REBOOT_WHEN}" "Clockwork-initiated reboot" || {
            echo "[clockwork-reboot] shutdown command failed"
            exit 1
        }
        echo "STATUS: reboot-scheduled"
        BASH;
    }

    public function checkRebootRequired(Server $server): ?bool
    {
        $state = $this->probeRebootState($server);

        return $state === null ? null : $state['required'];
    }

    /**
     * Single round-trip probe: checks /var/run/reboot-required AND uptime.
     *
     * /var/run is tmpfs (memory-only) and is wiped on reboot — so the file only exists
     * when a kernel/libc has been installed *since* the last boot. Combined with uptime
     * we can give the user the right narrative: "rebooted X ago, all clean" vs "just
     * rebooted but another kernel is already pending — second reboot needed".
     *
     * @return ?array{required: bool, uptime_seconds: int}
     */
    public function probeRebootState(Server $server): ?array
    {
        try {
            $output = $this->ssh->exec(
                $server,
                '(test -f /var/run/reboot-required && echo yes || echo no); awk \'{print $1}\' /proc/uptime',
            );
        } catch (\Throwable) {
            return null;
        }

        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        if (count($lines) < 2) {
            return null;
        }

        return [
            'required' => trim($lines[0]) === 'yes',
            'uptime_seconds' => (int) round((float) trim($lines[1])),
        ];
    }
}
