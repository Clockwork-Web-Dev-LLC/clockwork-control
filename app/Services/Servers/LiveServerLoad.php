<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\Ssh\SshClient;
use Illuminate\Support\Facades\Cache;

/**
 * Single-shot diagnostic snapshot for a server: who is actually using the CPU/memory
 * right now. Answers the "the box is hot but every site shows 0% — what's going on?"
 * question by reaching past PHP-FPM into mysqld, nginx, backups, kernel tasks, etc.
 *
 * Returns:
 *   [
 *     'loadavg'  => [1m, 5m, 15m] | null,
 *     'cores'    => int,
 *     'top'      => [ ['user', 'pid', 'cpu', 'mem', 'etime', 'category', 'command', 'site_id?'], ... ],
 *     'mysql'    => [ ['id', 'user', 'host', 'db', 'time', 'state', 'query'], ... ],
 *     'mysql_ok' => bool,
 *     'error'    => ?string,
 *   ]
 *
 * Cached 30 seconds — same TTL as PhpFpmPoolStats so the page renders both from the same
 * 30-sec window without duplicate SSH cost.
 */
class LiveServerLoad
{
    public function __construct(private readonly SshClient $ssh) {}

    /**
     * @return array{
     *     loadavg: ?array{0: float, 1: float, 2: float},
     *     cores: int,
     *     top: array<int, array{user: string, pid: int, cpu: float, mem: float, etime: string, category: string, command: string}>,
     *     mysql: array<int, array{id: string, user: string, host: string, db: string, time: int, state: string, query: string}>,
     *     mysql_ok: bool,
     *     error: ?string,
     * }
     */
    public function snapshot(Server $server, int $cacheSeconds = 30): array
    {
        return Cache::remember(
            "live_server_load.{$server->id}",
            now()->addSeconds($cacheSeconds),
            fn () => $this->probe($server),
        );
    }

    private function probe(Server $server): array
    {
        $empty = [
            'loadavg' => null,
            'cores' => 1,
            'top' => [],
            'mysql' => [],
            'mysql_ok' => false,
            'error' => null,
        ];

        try {
            $session = $this->ssh->connect($server);
        } catch (\Throwable $e) {
            return [...$empty, 'error' => $e->getMessage()];
        }

        $session->setTimeout(20);

        $script = $this->buildScript();
        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s 2>&1',
            escapeshellarg((string) $server->ssh_password),
            escapeshellarg($script),
        );

        try {
            $output = (string) $session->exec($cmd);
        } catch (\Throwable $e) {
            $session->disconnect();

            return [...$empty, 'error' => $e->getMessage()];
        }
        $session->disconnect();

        return $this->parse($output);
    }

    /**
     * One round-trip script. Sections separated by sentinel lines so parsing is
     * tolerant of error/permissions noise inside any section.
     */
    private function buildScript(): string
    {
        return <<<'BASH'
        #!/usr/bin/env bash

        echo "===CORES==="
        nproc 2>/dev/null || echo 1

        echo "===LOADAVG==="
        cat /proc/loadavg 2>/dev/null || echo ""

        echo "===TOP==="
        # user:32 to keep long site_user names intact (otherwise they're clipped to 8 chars
        # with a trailing '+'). args = full command line, comm = short binary name.
        ps -eo user:32,pid,pcpu,pmem,etime,comm,args --sort=-pcpu --no-headers 2>/dev/null \
            | awk 'NR<=20'

        echo "===MYSQL==="
        # SpinupWP boxes use unix-socket auth for root mysql/mariadb — `sudo mysql` works
        # without a password once we have sudo. Capture stderr too so we can surface the
        # actual reason if the probe fails (auth wall, mysql binary missing, etc.).
        if sudo -n mysql --version >/dev/null 2>&1; then
            sudo mysql -B --skip-column-names -e "SHOW FULL PROCESSLIST" 2>&1
        elif [ -n "${CW_SUDO_PW:-}" ]; then
            printf '%s\n' "$CW_SUDO_PW" | sudo -SE mysql -B --skip-column-names -e "SHOW FULL PROCESSLIST" 2>&1
        else
            echo "(sudo not available for mysql)"
        fi

        echo "===END==="
        BASH;
    }

    /**
     * @return array{
     *     loadavg: ?array{0: float, 1: float, 2: float},
     *     cores: int,
     *     top: array<int, array<string, mixed>>,
     *     mysql: array<int, array<string, mixed>>,
     *     mysql_ok: bool,
     *     error: ?string,
     * }
     */
    private function parse(string $output): array
    {
        $sections = $this->splitSections($output);

        $cores = max(1, (int) trim($sections['CORES'] ?? '1'));

        $loadavg = null;
        if (! empty($sections['LOADAVG'])) {
            $parts = preg_split('/\s+/', trim($sections['LOADAVG'])) ?: [];
            if (count($parts) >= 3) {
                $loadavg = [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
            }
        }

        $top = $this->parseTop($sections['TOP'] ?? '', $cores);
        [$mysqlOk, $mysql] = $this->parseMysql($sections['MYSQL'] ?? '');

        return [
            'loadavg' => $loadavg,
            'cores' => $cores,
            'top' => $top,
            'mysql' => $mysql,
            'mysql_ok' => $mysqlOk,
            'error' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^===([A-Z]+)===$/', $line, $m)) {
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
     * @return array<int, array{user: string, pid: int, cpu: float, mem: float, etime: string, category: string, command: string}>
     */
    private function parseTop(string $section, int $cores): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($section)) ?: [] as $line) {
            if (! preg_match('/^\s*(\S+)\s+(\d+)\s+([\d.]+)\s+([\d.]+)\s+(\S+)\s+(\S+)\s+(.*)$/', $line, $m)) {
                continue;
            }
            [, $user, $pid, $cpu, $mem, $etime, $comm, $args] = $m;

            // Drop kernel threads (their args is bracketed: [rcu_sched], [kworker/...]). They
            // can't be acted on and just clutter the list.
            if (str_starts_with(trim($args), '[') && str_ends_with(trim($args), ']')) {
                continue;
            }

            // Drop our own probe: ps/awk/bash/sshd at low CPU is just the SSH session reading
            // its own state. Keep them if they're > 5% in case something *else* spawned bash.
            if (in_array($comm, ['ps', 'awk', 'sshd', 'bash', 'sh'], true) && (float) $cpu < 5) {
                continue;
            }

            // Drop systemd-udevd duplicates and other system services at near-zero CPU/mem.
            // Threshold has to be high enough to drop the udevd workers (typically 0.5%) but
            // low enough to keep small but real PHP-FPM activity.
            if ((float) $cpu < 1.0 && (float) $mem < 1.0) {
                continue;
            }

            $rows[] = [
                'user' => $user,
                'pid' => (int) $pid,
                // CPU% normalised by core count, same trick as PhpFpmPoolStats — without this,
                // a 4-core box running mysqld at "200%" looks worse than it is.
                'cpu' => round(((float) $cpu) / $cores, 1),
                'mem' => round((float) $mem, 1),
                'etime' => $etime,
                'category' => $this->categorize($comm, $args),
                'command' => $this->shortenCommand($args),
            ];

            if (count($rows) >= 12) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return array{0: bool, 1: array<int, array{id: string, user: string, host: string, db: string, time: int, state: string, query: string}>}
     */
    private function parseMysql(string $section): array
    {
        $section = trim($section);
        if ($section === '' || str_starts_with($section, '(no sudo)') || str_starts_with($section, '(mysql probe failed)')) {
            return [false, []];
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', $section) ?: [] as $line) {
            $cols = explode("\t", $line);
            if (count($cols) < 8) {
                continue;
            }
            [$id, $user, $host, $db, $command, $time, $state, $info] = array_pad($cols, 8, '');

            // Skip idle-Sleep entries and very short queries — we want what's *blocking* now.
            if ($command === 'Sleep') {
                continue;
            }
            if ((int) $time < 1) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'user' => $user,
                'host' => $host,
                'db' => $db === 'NULL' ? '—' : $db,
                'time' => (int) $time,
                'state' => $state ?: '—',
                'query' => $this->shortenQuery($info),
            ];
        }

        usort($rows, fn ($a, $b) => $b['time'] <=> $a['time']);

        return [true, array_slice($rows, 0, 15)];
    }

    private function categorize(string $comm, string $args): string
    {
        // Both MySQL and MariaDB bucket as 'mysql' for the user — the distinction doesn't
        // matter at this view's level (it's "the database is hot").
        if (in_array($comm, ['mysqld', 'mariadbd'], true)
            || str_contains($args, '/usr/sbin/mysqld')
            || str_contains($args, '/usr/sbin/mariadbd')) {
            return 'mysql';
        }
        if (str_contains($args, 'php-fpm: pool ')) {
            return 'php-fpm';
        }
        if (preg_match('/(\/wp\s+cron|wp-cron\.php)/', $args)) {
            return 'wp-cron';
        }
        if ($comm === 'nginx') {
            return 'nginx';
        }
        if ($comm === 'redis-server') {
            return 'redis';
        }
        if (str_contains($args, 'apt') || str_contains($comm, 'apt')) {
            return 'apt';
        }
        if (str_contains($comm, 'fail2ban')) {
            return 'fail2ban';
        }
        if (str_contains($comm, 'borg') || str_contains($comm, 'restic') || str_contains($args, 'mysqldump')) {
            return 'backup';
        }

        return 'other';
    }

    private function shortenCommand(string $cmd): string
    {
        // Pull the pool name out for php-fpm workers — much more useful than the full args.
        if (preg_match('/php-fpm:\s+pool\s+(\S+)/', $cmd, $m)) {
            return "php-fpm: pool {$m[1]}";
        }
        if (preg_match('/(php-fpm: master process \([^)]+\))/', $cmd, $m)) {
            return $m[1];
        }

        // Otherwise truncate, keeping the first 120 chars (tooltip can show the rest).
        return mb_strlen($cmd) > 120 ? mb_substr($cmd, 0, 117).'…' : $cmd;
    }

    private function shortenQuery(string $q): string
    {
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;

        return mb_strlen($q) > 200 ? mb_substr($q, 0, 197).'…' : $q;
    }
}
