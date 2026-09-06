<?php

namespace App\Console\Commands;

use App\Services\Domains\DomainExpirationChecker;
use Illuminate\Console\Command;

class CheckDomainExpirations extends Command
{
    protected $signature = 'clockwork:check-domain-expirations
                            {--silent : Skip sending chat alerts (useful for initial backfill)}
                            {--site= : Run check on a specific site ID}
                            {--force : Bypass cadence timing guards}';

    protected $description = 'Check domain registration expiration dates via RDAP and alert on impending expiration.';

    public function handle(DomainExpirationChecker $checker): int
    {
        $silent = (bool) $this->option('silent');
        $siteId = $this->option('site') ? (int) $this->option('site') : null;
        $force = (bool) $this->option('force');

        $this->line('Checking domain registration expirations via RDAP...');

        $stats = $checker->run(silent: $silent, siteId: $siteId, force: $force);

        $this->info("Domain check complete: {$stats['checked']} checked, {$stats['transitioned']} transitioned, {$stats['notified']} notified, {$stats['skipped']} skipped.");

        return Command::SUCCESS;
    }
}
