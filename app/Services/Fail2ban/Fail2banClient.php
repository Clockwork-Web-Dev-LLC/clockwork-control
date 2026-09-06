<?php

namespace App\Services\Fail2ban;

use App\Models\Server;
use App\Services\Ssh\SshClient;
use RuntimeException;

class Fail2banClient
{
    public function __construct(
        protected SshClient $ssh,
        protected IgnoreIpMatcher $ignoreMatcher,
    ) {}

    /**
     * Ban an IP on the server's clockwork jail.
     * Assumes the jail is provisioned (sudoers grants NOPASSWD on fail2ban-client).
     *
     * Refuses if the IP is on the fail2ban ignoreip whitelist (Cloudflare edges,
     * fleet IPs, loopback). fail2ban would silently no-op anyway, but writing a
     * phantom "banned" row to our DB makes the UI lie. Refusing here keeps local
     * state honest across every call site.
     *
     * @return array{ok: bool, output: string, message: string}
     */
    public function banIp(Server $server, string $ip): array
    {
        if ($reason = $this->ignoreMatcher->reason($ip)) {
            return [
                'ok' => false,
                'output' => '',
                'message' => "Refused to ban {$ip} on {$server->name} — protected IP ({$reason}).",
            ];
        }

        return $this->command(
            $server,
            'banip',
            $ip,
            "Ban {$ip} on {$server->name}",
        );
    }

    /**
     * @return array{ok: bool, output: string, message: string}
     */
    public function unbanIp(Server $server, string $ip): array
    {
        return $this->command(
            $server,
            'unbanip',
            $ip,
            "Unban {$ip} on {$server->name}",
        );
    }

    /**
     * Unban multiple IPs in a single SSH session. Faster than N separate connects
     * when bulk-unbanning a noisy site (50+ IPs).
     *
     * @param  array<int, string>  $ips
     * @return array{ok: bool, results: array<string, array{ok: bool, output: string}>, message: string}
     */
    public function unbanIps(Server $server, array $ips): array
    {
        $valid = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP)));

        if ($valid === []) {
            return ['ok' => false, 'results' => [], 'message' => 'No valid IPs supplied.'];
        }

        if ($server->clockwork_jail_provisioned_at === null) {
            return ['ok' => false, 'results' => [], 'message' => "Server {$server->name} has not been provisioned yet."];
        }

        try {
            $session = $this->ssh->connect($server);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'results' => [], 'message' => 'SSH connect failed: '.$e->getMessage()];
        }

        $results = [];
        $okCount = 0;

        foreach ($valid as $ip) {
            $cmd = sprintf('sudo -n fail2ban-client set clockwork unbanip %s 2>&1', escapeshellarg($ip));
            $output = (string) $session->exec($cmd);
            // Read the LAST non-empty line, not the whole buffer — sudo
            // banners ("[sudo] password for X:", env_reset notices) merged
            // via 2>&1 can prepend noise that makes ctype_digit() on the
            // full trim return false on legitimate successes.
            $ok = $this->parseOk($output, 'unbanip');

            $results[$ip] = ['ok' => $ok, 'output' => $output];
            if ($ok) {
                $okCount++;
            }
        }

        $session->disconnect();

        return [
            'ok' => $okCount === count($valid),
            'results' => $results,
            'message' => sprintf('Unbanned %d of %d on %s.', $okCount, count($valid), $server->name),
        ];
    }

    /**
     * @return array{ok: bool, output: string, ips: array<int, string>, message: string}
     */
    public function status(Server $server): array
    {
        try {
            $session = $this->ssh->connect($server);
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'ips' => [], 'message' => $e->getMessage()];
        }

        $output = (string) $session->exec('sudo -n fail2ban-client status clockwork 2>&1');
        $session->disconnect();

        $ips = [];
        if (preg_match('/Banned IP list:\s*(.*)$/m', $output, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $ip) {
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }

        return [
            'ok' => str_contains($output, 'Status for the jail'),
            'output' => $output,
            'ips' => $ips,
            'message' => 'OK',
        ];
    }

    /**
     * @return array{ok: bool, output: string, message: string}
     */
    protected function command(Server $server, string $action, string $ip, string $label): array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'output' => '', 'message' => "Invalid IP address: {$ip}"];
        }

        if ($server->clockwork_jail_provisioned_at === null) {
            return ['ok' => false, 'output' => '', 'message' => "Server {$server->name} has not been provisioned yet."];
        }

        try {
            $session = $this->ssh->connect($server);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'output' => '', 'message' => 'SSH connect failed: '.$e->getMessage()];
        }

        $cmd = sprintf('sudo -n fail2ban-client set clockwork %s %s 2>&1', $action, escapeshellarg($ip));
        $output = (string) $session->exec($cmd);
        $session->disconnect();

        // fail2ban-client returns the new count on success (e.g. "1\n") and a message starting "ERROR" or similar on failure.
        // Parse the LAST non-empty line so sudo banner contamination on stderr (merged via 2>&1) can't false-fail the success check.
        $ok = $this->parseOk($output, $action);

        $trimmed = trim($output);

        return [
            'ok' => $ok,
            'output' => $output,
            'message' => $ok ? "{$label}: ".($trimmed === '' ? 'done' : $trimmed) : "{$label} failed",
        ];
    }

    /**
     * Decide whether a fail2ban-client invocation succeeded by inspecting
     * the LAST non-empty line of the captured output. This rejects sudo
     * banner noise (env_reset notices, password prompts merged on stderr)
     * that would otherwise prepend the real result and break a naive
     * ctype_digit() on the whole buffer.
     */
    private function parseOk(string $output, string $action): bool
    {
        // Whole-output substring tests are fine for the explicit error tags
        // since they never overlap with sudo banner text.
        if ($action === 'banip' && str_contains($output, 'already banned')) {
            return true;
        }
        if ($action === 'unbanip' && str_contains($output, 'is not banned')) {
            return true;
        }

        $lines = array_filter(
            preg_split('/\r?\n/', trim($output)) ?: [],
            fn ($l) => trim($l) !== ''
        );
        $lastLine = trim(array_pop($lines) ?? '');

        // fail2ban-client emits the new ban-list count on success (e.g. "1").
        return ctype_digit($lastLine);
    }
}
