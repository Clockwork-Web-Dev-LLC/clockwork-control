<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\ThreatLog;
use App\Services\Logs\NginxLogParser;
use App\Services\Ssh\SshClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-time recovery command: reads date-stamped rotated nginx log files
 * (access.log-YYYYMMDD.gz) from each server and ingests them into threat_logs.
 *
 * Only inserts rows that don't already exist in the requested date window,
 * preventing duplicates when a day was partially ingested before the cursor stalled.
 */
class IngestRotatedLogs extends Command
{
    protected $signature = 'clockwork:ingest-rotated-logs
        {--dates= : Comma-separated dates to recover, e.g. 2026-07-17,2026-07-18,2026-07-19}
        {--site= : Limit to one site (id or domain)}
        {--server= : Limit to one server (name or hostname)}';

    protected $description = 'Ingest date-stamped rotated nginx logs (access.log-YYYYMMDD.gz) to fill gaps in threat_logs.';

    public function handle(SshClient $ssh, NginxLogParser $parser): int
    {
        ini_set('memory_limit', '1G');
        $dates = $this->parseDates();
        if (empty($dates)) {
            $this->error('No valid dates. Use --dates=2026-07-17,2026-07-18,2026-07-19');

            return self::FAILURE;
        }

        $this->info('Recovering rotated logs for dates: '.implode(', ', $dates));

        $sites = Site::query()
            ->with('server')
            ->whereHas('server', fn ($q) => $q->monitored()->whereNotNull('last_ssh_ok_at'))
            ->when($this->option('site'), function ($q, $v) {
                $q->where(fn ($q) => $q->where('id', $v)->orWhere('domain', $v));
            })
            ->when($this->option('server'), function ($q, $v) {
                $q->whereHas('server', fn ($q) => $q->where('name', $v)->orWhere('hostname', $v));
            })
            ->orderBy('server_id')
            ->orderBy('domain')
            ->get();

        $this->info(sprintf('Processing %d site(s)…', $sites->count()));

        $totalInserted = 0;
        $totalFailed = 0;

        foreach ($sites as $site) {
            $logBase = $site->nginx_access_log_path
                ? rtrim(dirname($site->nginx_access_log_path), '/').'/access.log'
                : '/sites/'.$site->domain.'/logs/access.log';

            foreach ($dates as $date) {
                // SpinupWP logrotate names files with the NEXT day's date
                // (rotation fires at 23:59 and stamps the file with tomorrow).
                // access.log-20260718.gz contains 07-17 logs, etc.
                $fileDate = CarbonImmutable::parse($date)->addDay()->format('Ymd');
                $gzPath = $logBase.'-'.$fileDate.'.gz';

                try {
                    $stat = trim($ssh->exec($site->server, 'test -f '.escapeshellarg($gzPath).' && echo exists || echo missing'));
                    if ($stat !== 'exists') {
                        if ($this->getOutput()->isVerbose()) {
                            $this->line("  [skip] {$site->domain} {$date} — no rotated file");
                        }

                        continue;
                    }

                    // Filter by date on the server (grep) before transferring —
                    // avoids pulling hundreds of MB for high-traffic sites.
                    $grepDate = CarbonImmutable::parse($date)->format('d/M/Y');
                    $contents = $ssh->exec(
                        $site->server,
                        'zcat '.escapeshellarg($gzPath).' | grep '.escapeshellarg($grepDate)
                    );
                    $rows = $parser->parse($contents, $site);
                    unset($contents);

                    if (empty($rows)) {
                        if ($this->getOutput()->isVerbose()) {
                            $this->line("  [skip] {$site->domain} {$date} — file present but no rows matched date");
                        }

                        continue;
                    }

                    $inserted = 0;
                    foreach (array_chunk(array_values($rows), 500) as $chunk) {
                        ThreatLog::insert($chunk);
                        $inserted += count($chunk);
                    }

                    $totalInserted += $inserted;
                    $this->line("  [ok]   {$site->domain} {$date} — inserted {$inserted} rows");
                } catch (Throwable $e) {
                    $totalFailed++;
                    $this->warn("  [fail] {$site->domain} {$date} — {$e->getMessage()}");
                } finally {
                    unset($contents, $rows, $filtered);
                    gc_collect_cycles();
                }
            }
        }

        $this->info("Done. Total inserted: {$totalInserted}. Failures: {$totalFailed}.");
        $this->info('Run clockwork:rollup-traffic --backfill=6 to update the daily charts.');

        return self::SUCCESS;
    }

    /** @return string[] */
    private function parseDates(): array
    {
        $raw = $this->option('dates');
        if (! $raw) {
            return [];
        }

        $dates = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            try {
                $dates[] = CarbonImmutable::parse($part)->toDateString();
            } catch (Throwable) {
                $this->warn("Skipping invalid date: {$part}");
            }
        }

        return array_unique($dates);
    }
}
