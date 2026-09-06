<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Services\Logs\NginxLogTailer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TailNginxLogs extends Command
{
    protected $signature = 'clockwork:tail-nginx-logs
        {--site= : Limit to a specific site ID or domain}
        {--server= : Limit to sites on a server (name, hostname, or ID)}';

    protected $description = 'Pull new nginx access-log lines for each site since the last cursor and ingest into threat_logs.';

    public function handle(NginxLogTailer $tailer): int
    {
        $sites = $this->targetSites();

        if ($sites->isEmpty()) {
            $this->warn('No sites match the given filters.');

            return self::SUCCESS;
        }

        $totalBytes = 0;
        $totalParsed = 0;
        $totalInserted = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($sites->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();

        foreach ($sites as $site) {
            $bar->setMessage($site->domain);

            try {
                $r = $tailer->tail($site);
                $totalBytes += $r['bytes'];
                $totalParsed += $r['parsed'];
                $totalInserted += $r['inserted'];
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->line("  <fg=red>FAIL</> {$site->domain}: {$e->getMessage()}");

                // This runs via ->runInBackground() with stdout discarded, so
                // the console line above is invisible outside an interactive
                // run — without this, per-site failures left no trace at all
                // except contributing to the fleet-wide exit code below.
                Log::warning('tail_nginx_logs.site_failed', [
                    'site' => $site->domain,
                    'server' => $site->server?->name,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();

            // Release memory between sites — large logs accumulate refs in phpseclib3.
            unset($r);
            gc_collect_cycles();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf(
            'Read %s bytes, parsed %d lines, inserted %d rows. Failed sites: %d.',
            number_format($totalBytes),
            $totalParsed,
            $totalInserted,
            $failed,
        ));

        // A handful of chronically-flaky/unreachable servers used to make
        // the WHOLE command report FAILURE on every 5-minute tick (25,520
        // logged scheduler errors observed for 3 known-stale servers) —
        // every other site's logs still got tailed fine (per-site try/catch
        // above), so that was a false alarm, not a real outage. Only report
        // FAILURE when NOTHING succeeded — that's the one case genuinely
        // worth an ERROR-level scheduler alert. Individual failures are
        // still visible via the tail_nginx_logs.site_failed warning above.
        $allFailed = $failed > 0 && $failed === $sites->count();

        return $allFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function targetSites(): Collection
    {
        $query = Site::query()
            ->with('server')
            ->whereHas('server', fn ($q) => $q
                ->monitored()
                ->whereNotNull('last_ssh_ok_at'));

        if ($siteFilter = $this->option('site')) {
            $query->where(function ($q) use ($siteFilter) {
                $q->where('id', $siteFilter)->orWhere('domain', $siteFilter);
            });
        }

        if ($serverFilter = $this->option('server')) {
            $serverId = is_numeric($serverFilter)
                ? (int) $serverFilter
                : Server::query()
                    ->where('name', $serverFilter)
                    ->orWhere('hostname', $serverFilter)
                    ->value('id');
            if ($serverId) {
                $query->where('server_id', $serverId);
            } else {
                return collect();
            }
        }

        return $query->orderBy('server_id')->orderBy('domain')->get();
    }
}
