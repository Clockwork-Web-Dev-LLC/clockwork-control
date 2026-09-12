<?php

namespace App\Support;

/**
 * Normalizes an Illuminate\Console\Scheduling\Event's raw ->command string
 * (e.g. "'/usr/bin/php' 'artisan' clockwork:refresh-cisa-kev") down to the
 * bare artisan signature ("clockwork:refresh-cisa-kev"), independent of the
 * PHP binary path baked in at schedule-build time.
 *
 * Used on both sides of the scheduled-jobs dashboard so they agree on the
 * same key without either side hardcoding a command list:
 *   - App\Listeners\Scheduling\RecordScheduledTaskResult (writes runs)
 *   - App\Http\Controllers\ScheduledJobsController (enumerates the schedule)
 */
class ScheduledCommandName
{
    public static function normalize(string $rawCommand): string
    {
        return trim(preg_replace("/^.*?'artisan'\s+/", '', $rawCommand));
    }
}
