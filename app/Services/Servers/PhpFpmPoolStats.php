<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\Ssh\SshClient;
use Illuminate\Support\Facades\Cache;

class PhpFpmPoolStats
{
    public function __construct(private readonly SshClient $ssh) {}

    /**
     * Live PHP-FPM stats for a server, summarised by pool name.
     *
     * Returns:
     *   [pool_name => ['cpu' => float, 'mem' => float, 'workers' => int]]
     *
     * Cached briefly so the server detail page doesn't SSH on every reload.
     */
    public function snapshot(Server $server, int $cacheSeconds = 30): array
    {
        return Cache::remember(
            "phpfpm.pool.{$server->id}",
            now()->addSeconds($cacheSeconds),
            fn () => $this->collect($server),
        );
    }

    /**
     * @return array<string, array{cpu: float, mem: float, workers: int}>
     */
    private function collect(Server $server): array
    {
        try {
            // One round-trip: print core count first, then the per-process breakdown. We need the
            // core count to normalise pcpu — `ps` reports %CPU as a share of ONE core, so an
            // 8-vCPU box with workers each using half a core can summed-pcpu past 100%. We
            // divide by core count below so the number reflects "% of total system CPU".
            //
            // We catch BOTH php-fpm workers and wp-cron CLI processes — wp-cron runs as the site
            // user (e.g. `siteuser`) and can spike the box during scheduled jobs, but it's
            // not visible to a pool-name grep. Use `ps -eo user:32` to widen the user column so
            // longer site_user values aren't truncated to `intrins+` and lose their identity.
            $output = $this->ssh->exec(
                $server,
                "echo NPROC=\$(nproc 2>/dev/null || echo 1); ps -eo user:32,pcpu,pmem,cmd --no-headers 2>/dev/null | grep -E '(php-fpm: pool |/wp +cron |wp-cron\\.php)' | grep -v grep"
            );
        } catch (\Throwable) {
            return [];
        }

        $cores = 1;
        $byPool = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if (preg_match('/^NPROC=(\d+)/', $line, $m)) {
                $cores = max(1, (int) $m[1]);

                continue;
            }

            if (! preg_match('/^\s*(\S+)\s+([\d.]+)\s+([\d.]+)\s+(.*)$/', $line, $m)) {
                continue;
            }
            [, $user, $cpu, $mem, $cmd] = $m;
            $cpu = (float) $cpu;
            $mem = (float) $mem;

            // Pool name comes from the cmd for php-fpm workers; for wp-cron we attribute by user
            // (which equals the site_user in SpinupWP's layout).
            $pool = null;
            $isCron = false;
            if (preg_match('/php-fpm:\s+pool\s+(\S+)/', $cmd, $pm)) {
                $pool = $pm[1];
            } elseif (preg_match('/(\/wp\s+cron|wp-cron\.php)/', $cmd)) {
                $pool = $user;
                $isCron = true;
            } else {
                continue;
            }

            if (! isset($byPool[$pool])) {
                $byPool[$pool] = ['cpu' => 0.0, 'mem' => 0.0, 'workers' => 0, 'cron_workers' => 0];
            }
            $byPool[$pool]['cpu'] += $cpu;
            $byPool[$pool]['mem'] += $mem;
            if ($isCron) {
                $byPool[$pool]['cron_workers']++;
            } else {
                $byPool[$pool]['workers']++;
            }
        }

        // Normalise CPU% by core count so the value is comparable to system-wide CPU%
        // (matches what `top` shows in "Cpu(s):" line and what our DO poller reports).
        foreach ($byPool as $pool => &$stats) {
            $stats['cpu'] = round($stats['cpu'] / $cores, 1);
        }

        return $byPool;
    }
}
