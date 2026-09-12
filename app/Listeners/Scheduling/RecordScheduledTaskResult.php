<?php

namespace App\Listeners\Scheduling;

use App\Models\ScheduledJobRun;
use App\Support\ScheduledCommandName;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Powers the /settings/scheduled-jobs dashboard. Subscribes to Laravel's
 * built-in scheduler events, which fire for every entry in the schedule
 * (routes/console.php plus module-registered tasks) regardless of how it
 * was registered — no per-command wiring needed.
 *
 * Finished always fires first (even on a non-zero exit code); Failed fires
 * right after for foreground jobs that threw. We update the row Finished
 * just wrote rather than inserting a second one for the same tick.
 *
 * Never let a recording failure break the actual scheduled job — every
 * handler is wrapped and only logged on error.
 */
class RecordScheduledTaskResult
{
    public function handleFinished(ScheduledTaskFinished $event): void
    {
        try {
            ScheduledJobRun::create([
                'command' => ScheduledCommandName::normalize($event->task->command),
                'status' => $event->task->exitCode === 0 ? ScheduledJobRun::STATUS_SUCCESS : ScheduledJobRun::STATUS_FAILED,
                'duration_ms' => (int) round($event->runtime * 1000),
                'exit_code' => $event->task->exitCode,
            ]);
        } catch (Throwable $e) {
            Log::warning('scheduled_job_run.record_finished_failed', ['error' => $e->getMessage()]);
        }
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        try {
            $command = ScheduledCommandName::normalize($event->task->command);

            $updated = ScheduledJobRun::query()
                ->forCommand($command)
                ->latest('id')
                ->limit(1)
                ->update([
                    'status' => ScheduledJobRun::STATUS_FAILED,
                    'output' => substr($event->exception->getMessage(), 0, 2000),
                ]);

            if ($updated === 0) {
                // Backgrounded/async events never reach handleFinished with a
                // useful exit code — record what we can here instead of losing it.
                ScheduledJobRun::create([
                    'command' => $command,
                    'status' => ScheduledJobRun::STATUS_FAILED,
                    'exit_code' => $event->task->exitCode,
                    'output' => substr($event->exception->getMessage(), 0, 2000),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('scheduled_job_run.record_failed_failed', ['error' => $e->getMessage()]);
        }
    }

    public function handleSkipped(ScheduledTaskSkipped $event): void
    {
        try {
            ScheduledJobRun::create([
                'command' => ScheduledCommandName::normalize($event->task->command),
                'status' => ScheduledJobRun::STATUS_SKIPPED,
            ]);
        } catch (Throwable $e) {
            Log::warning('scheduled_job_run.record_skipped_failed', ['error' => $e->getMessage()]);
        }
    }
}
