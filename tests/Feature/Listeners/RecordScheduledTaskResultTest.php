<?php

use App\Listeners\Scheduling\RecordScheduledTaskResult;
use App\Models\ScheduledJobRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;

function makeScheduleEvent(string $command, int $exitCode = 0): Event
{
    $event = new Event(new CacheEventMutex(app('cache')), $command);
    $event->exitCode = $exitCode;

    return $event;
}

test('a finished event records a success row', function () {
    $listener = new RecordScheduledTaskResult;
    $event = makeScheduleEvent("'/usr/bin/php' 'artisan' clockwork:refresh-cisa-kev", 0);

    $listener->handleFinished(new ScheduledTaskFinished($event, 1.23));

    $this->assertDatabaseHas('scheduled_job_runs', [
        'command' => 'clockwork:refresh-cisa-kev',
        'status' => ScheduledJobRun::STATUS_SUCCESS,
        'duration_ms' => 1230,
    ]);
});

test('a finished event with a non-zero exit code records failed', function () {
    $listener = new RecordScheduledTaskResult;
    $event = makeScheduleEvent("'/usr/bin/php' 'artisan' clockwork:refresh-closed-plugins", 1);

    $listener->handleFinished(new ScheduledTaskFinished($event, 0.5));

    $this->assertDatabaseHas('scheduled_job_runs', [
        'command' => 'clockwork:refresh-closed-plugins',
        'status' => ScheduledJobRun::STATUS_FAILED,
    ]);
});

test('a failed event updates the row Finished just wrote with the exception message', function () {
    $listener = new RecordScheduledTaskResult;
    $event = makeScheduleEvent("'/usr/bin/php' 'artisan' clockwork:sync-bill-customers", 1);

    $listener->handleFinished(new ScheduledTaskFinished($event, 0.1));
    $listener->handleFailed(new ScheduledTaskFailed($event, new RuntimeException('Bill.com API unreachable')));

    $this->assertDatabaseHas('scheduled_job_runs', [
        'command' => 'clockwork:sync-bill-customers',
        'status' => ScheduledJobRun::STATUS_FAILED,
        'output' => 'Bill.com API unreachable',
    ]);
    expect(ScheduledJobRun::query()->forCommand('clockwork:sync-bill-customers')->count())->toBe(1);
});

test('a skipped event records a skipped row', function () {
    $listener = new RecordScheduledTaskResult;
    $event = makeScheduleEvent("'/usr/bin/php' 'artisan' clockwork:sync-bill-care-plans");

    $listener->handleSkipped(new ScheduledTaskSkipped($event));

    $this->assertDatabaseHas('scheduled_job_runs', [
        'command' => 'clockwork:sync-bill-care-plans',
        'status' => ScheduledJobRun::STATUS_SKIPPED,
    ]);
});
