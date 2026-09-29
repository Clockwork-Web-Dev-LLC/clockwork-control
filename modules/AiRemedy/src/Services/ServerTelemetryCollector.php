<?php

namespace Modules\AiRemedy\Services;

use App\Models\Server;
use App\Services\Ssh\SshClient;
use Throwable;

class ServerTelemetryCollector
{
    public function __construct(protected SshClient $ssh) {}

    /**
     * Gather a comprehensive read-only diagnostic bundle from the target server over SSH.
     *
     * @return array{
     *     ok: bool,
     *     server_id: int,
     *     hostname: string,
     *     uptime?: string,
     *     loadavg?: array{0: float, 1: float, 2: float},
     *     cores?: int,
     *     memory?: array<string, mixed>,
     *     disk?: array<string, mixed>,
     *     top_cpu?: array<int, array<string, string>>,
     *     top_mem?: array<int, array<string, string>>,
     *     services?: array<string, string>,
     *     recent_errors?: string,
     *     raw_output?: string,
     *     error?: string
     * }
     */
    public function collect(Server $server): array
    {
        try {
            $session = $this->ssh->connect($server);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'server_id' => $server->id,
                'hostname' => $server->hostname,
                'error' => 'SSH connection failed: '.$e->getMessage(),
            ];
        }

        $session->setTimeout(12);

        $script = $this->buildDiagnosticScript();

        try {
            $raw = (string) $session->exec("bash -c '{$script}' 2>&1");
        } catch (Throwable $e) {
            $session->disconnect();

            return [
                'ok' => false,
                'server_id' => $server->id,
                'hostname' => $server->hostname,
                'error' => 'SSH exec failed: '.$e->getMessage(),
            ];
        }

        $session->disconnect();

        return $this->parseOutput($server, $raw);
    }

    protected function buildDiagnosticScript(): string
    {
        return <<<'BASH'
echo "===UPTIME==="
uptime 2>/dev/null

echo "===CORES==="
nproc 2>/dev/null || echo 1

echo "===FREE==="
free -m 2>/dev/null

echo "===DF==="
df -h / 2>/dev/null

echo "===TOP_CPU==="
ps aux --sort=-%cpu 2>/dev/null | head -n 12

echo "===TOP_MEM==="
ps aux --sort=-%mem 2>/dev/null | head -n 12

echo "===SERVICES==="
for svc in nginx php8.3-fpm php8.2-fpm php8.1-fpm mysql mariadb redis-server fail2ban; do
    if systemctl list-unit-files "$svc.service" &>/dev/null; then
        st=$(systemctl is-active "$svc" 2>/dev/null)
        echo "$svc:$st"
    fi
done

echo "===NGINX_ERR==="
if [ -f /var/log/nginx/error.log ]; then
    tail -n 25 /var/log/nginx/error.log 2>/dev/null
fi

echo "===END==="
BASH;
    }

    /**
     * @return array{
     *     ok: bool,
     *     server_id: int,
     *     hostname: string,
     *     uptime?: string,
     *     loadavg?: array{0: float, 1: float, 2: float},
     *     cores?: int,
     *     memory?: array<string, mixed>,
     *     disk?: array<string, mixed>,
     *     top_cpu?: array<int, array<string, string>>,
     *     top_mem?: array<int, array<string, string>>,
     *     services?: array<string, string>,
     *     recent_errors?: string,
     *     raw_output?: string,
     *     error?: string
     * }
     */
    protected function parseOutput(Server $server, string $raw): array
    {
        $sections = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/^===([A-Z_]+)===$/', $line, $m)) {
                $current = $m[1];
                $sections[$current] = '';

                continue;
            }
            if ($current !== null) {
                $sections[$current] .= $line."\n";
            }
        }

        $uptimeStr = trim($sections['UPTIME'] ?? '');
        $loadavg = null;
        if (preg_match('/load average:\s*([0-9.]+),\s*([0-9.]+),\s*([0-9.]+)/i', $uptimeStr, $m)) {
            $loadavg = [(float) $m[1], (float) $m[2], (float) $m[3]];
        }

        $cores = (int) trim($sections['CORES'] ?? '1');
        if ($cores < 1) {
            $cores = 1;
        }

        // Parse Memory (free -m)
        $memData = [];
        $freeRaw = trim($sections['FREE'] ?? '');
        foreach (explode("\n", $freeRaw) as $fLine) {
            if (str_starts_with($fLine, 'Mem:')) {
                $parts = preg_split('/\s+/', $fLine) ?: [];
                if (count($parts) >= 7) {
                    $total = (int) $parts[1];
                    $used = (int) $parts[2];
                    $avail = (int) $parts[6];
                    $pct = $total > 0 ? round(($used / $total) * 100, 1) : 0;
                    $memData = [
                        'total_mb' => $total,
                        'used_mb' => $used,
                        'available_mb' => $avail,
                        'used_percent' => $pct,
                    ];
                }
            }
        }

        // Parse Disk (df -h /)
        $diskData = [];
        $dfRaw = trim($sections['DF'] ?? '');
        $dfLines = explode("\n", $dfRaw);
        if (count($dfLines) >= 2) {
            $dfParts = preg_split('/\s+/', $dfLines[1]) ?: [];
            if (count($dfParts) >= 5) {
                $diskData = [
                    'size' => $dfParts[1],
                    'used' => $dfParts[2],
                    'available' => $dfParts[3],
                    'use_pct' => $dfParts[4],
                ];
            }
        }

        // Parse Top CPU & Memory Processes
        $topCpu = $this->parsePs(trim($sections['TOP_CPU'] ?? ''));
        $topMem = $this->parsePs(trim($sections['TOP_MEM'] ?? ''));

        // Parse Services
        $services = [];
        foreach (explode("\n", trim($sections['SERVICES'] ?? '')) as $sLine) {
            if (str_contains($sLine, ':')) {
                [$svc, $st] = explode(':', $sLine, 2);
                $services[trim($svc)] = trim($st);
            }
        }

        $recentErrors = trim($sections['NGINX_ERR'] ?? '');

        return [
            'ok' => true,
            'server_id' => $server->id,
            'hostname' => $server->hostname,
            'uptime' => $uptimeStr,
            'loadavg' => $loadavg,
            'cores' => $cores,
            'memory' => $memData,
            'disk' => $diskData,
            'top_cpu' => $topCpu,
            'top_mem' => $topMem,
            'services' => $services,
            'recent_errors' => $recentErrors !== '' ? mb_strimwidth($recentErrors, 0, 1500, '…') : null,
            'raw_output' => mb_strimwidth($raw, 0, 4000, '…'),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function parsePs(string $psOutput): array
    {
        $lines = explode("\n", $psOutput);
        $results = [];

        // Skip header line
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            $cols = preg_split('/\s+/', $line, 11) ?: [];
            if (count($cols) >= 11) {
                $results[] = [
                    'user' => $cols[0],
                    'pid' => $cols[1],
                    'cpu_pct' => $cols[2],
                    'mem_pct' => $cols[3],
                    'command' => $cols[10],
                ];
            }
        }

        return array_slice($results, 0, 8);
    }
}
