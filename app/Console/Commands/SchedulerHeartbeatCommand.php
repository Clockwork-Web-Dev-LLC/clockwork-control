<?php

namespace App\Console\Commands;

use App\Services\Scheduler\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * Cheap Settings write that proves crontab actually spawned `schedule:run`.
 * Keep this first among every-minute jobs so a hung drainer cannot delay it.
 *
 * Detection of a missing tick lives on web requests — see SchedulerHeartbeat.
 */
class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'clockwork:scheduler-heartbeat';

    protected $description = 'Record that the Laravel scheduler just ran.';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        $heartbeat->record();
        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
