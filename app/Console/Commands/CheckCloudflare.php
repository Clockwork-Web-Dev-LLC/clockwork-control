<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Cloudflare\CloudflareDetector;
use Illuminate\Console\Command;

class CheckCloudflare extends Command
{
    protected $signature = 'clockwork:check-cloudflare
        {--site= : Limit to a specific site ID or domain}';

    protected $description = 'Resolve each site domain and classify its Cloudflare usage (proxied / dns_only / not_using).';

    public function handle(CloudflareDetector $detector): int
    {
        $query = Site::query()
            ->whereHas('server', fn ($q) => $q->monitored());

        if ($filter = $this->option('site')) {
            $query->where(function ($q) use ($filter) {
                $q->where('id', $filter)->orWhere('domain', $filter);
            });
        }

        $sites = $query->orderBy('domain')->get();

        if ($sites->isEmpty()) {
            $this->warn('No sites match the filter.');

            return self::SUCCESS;
        }

        $stats = [
            Site::CF_PROXIED => 0,
            Site::CF_DNS_ONLY => 0,
            Site::CF_NOT_USING => 0,
            Site::CF_UNKNOWN => 0,
        ];

        $bar = $this->output->createProgressBar($sites->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();

        foreach ($sites as $site) {
            $bar->setMessage($site->domain);

            $result = $detector->detect($site->domain);

            $site->update([
                'cloudflare_state' => $result['state'],
                'cloudflare_checked_at' => now(),
                'resolved_a_record' => $result['a_record'],
                'resolved_ns_record' => $result['ns_record'],
            ]);

            $stats[$result['state']]++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Proxied: %d · DNS-only: %d · Not using: %d · Unknown: %d',
            $stats[Site::CF_PROXIED],
            $stats[Site::CF_DNS_ONLY],
            $stats[Site::CF_NOT_USING],
            $stats[Site::CF_UNKNOWN],
        ));

        return self::SUCCESS;
    }
}
