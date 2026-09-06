<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\WpConfigExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ExtractWpConfigs extends Command
{
    protected $signature = 'clockwork:extract-wp-configs
        {--site= : Limit to a specific site ID or domain}
        {--server= : Limit to sites on a server (name, hostname, or ID)}
        {--force : Re-extract even if db_password is already stored}';

    protected $description = 'Read wp-config.php from each WordPress site over SSH and store DB credentials.';

    public function handle(WpConfigExtractor $extractor): int
    {
        $sites = $this->targetSites();

        if ($sites->isEmpty()) {
            $this->warn('No sites match the given filters.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $ok = 0;
        $skipped = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($sites->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();

        foreach ($sites as $site) {
            $bar->setMessage($site->domain);

            if (! $force && $site->db_password) {
                $skipped++;
                $bar->advance();

                continue;
            }

            try {
                $extractor->extractAndStore($site);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->line("  <fg=red>FAIL</> {$site->domain}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Extracted: {$ok}, skipped: {$skipped}, failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function targetSites(): Collection
    {
        $query = Site::query()
            ->with('server')
            ->where('is_wordpress', true)
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
