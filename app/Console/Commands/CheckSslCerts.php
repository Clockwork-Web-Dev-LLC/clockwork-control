<?php

namespace App\Console\Commands;

use App\Services\Ssl\SslChecker;
use Illuminate\Console\Command;

class CheckSslCerts extends Command
{
    protected $signature = 'clockwork:check-ssl-certs
        {--silent : Persist state but skip Mattermost notifications (use for first-time backfill)}';

    protected $description = 'Re-evaluate SSL cert state per site, post Mattermost on transitions only.';

    public function handle(SslChecker $checker): int
    {
        $silent = (bool) $this->option('silent');

        $result = $checker->run($silent);

        $this->info(sprintf(
            'Checked %d sites, %d refreshed from SpinupWP, %d transitions, %d Mattermost notifications.%s',
            $result['checked'],
            $result['refreshed'],
            $result['transitioned'],
            $result['notified'],
            $silent ? ' (silent mode)' : '',
        ));

        return self::SUCCESS;
    }
}
