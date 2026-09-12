<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\ScheduledJobRun;
use App\Services\ActionLog\ActionLogger;
use App\Services\Process\BackgroundArtisan;
use App\Support\ScheduledCommandName;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * /settings/scheduled-jobs — lists every entry in the live schedule
 * (routes/console.php plus module-registered tasks, read straight off the
 * Schedule container so this can never drift from what actually runs),
 * cross-referenced with the run history RecordScheduledTaskResult writes,
 * plus a manual "Run now" trigger.
 */
class ScheduledJobsController extends Controller
{
    /**
     * Known platform-level toggles that make a job's ->when()/->skip() gate
     * fail. Not exhaustive — filtersPass() detects a currently-skipped job
     * generically; this map only adds an actionable settings link when we
     * happen to recognize the command.
     *
     * @var array<string, array{route: string, label: string}>
     */
    private const KNOWN_GATES = [
        'clockwork:sync-bill-customers' => ['route' => 'settings.bill-com.index', 'label' => 'Bill.com Sync settings'],
        'clockwork:sync-bill-care-plans' => ['route' => 'settings.bill-com.index', 'label' => 'Bill.com Sync settings'],
        'clockwork:send-telemetry' => ['route' => 'settings.maintenance.index', 'label' => 'Database Maintenance settings'],
    ];

    public function index(Schedule $schedule): View
    {
        $latestRuns = ScheduledJobRun::latestPerCommand();

        $jobs = collect($schedule->events())
            ->map(function ($event) use ($latestRuns) {
                $command = ScheduledCommandName::normalize($event->command);
                $latest = $latestRuns->get($command);

                $willRunNow = true;
                try {
                    $willRunNow = $event->filtersPass(app());
                } catch (Throwable) {
                    // A gate closure throwing shouldn't take the dashboard down —
                    // treat as "unknown," not a false positive either way.
                    $willRunNow = true;
                }

                $gate = self::KNOWN_GATES[$command] ?? null;

                return [
                    'command' => $command,
                    'expression' => $event->getExpression(),
                    'description' => is_string($event->description) ? $event->description : null,
                    'next_due' => rescue(fn () => $event->nextRunDate()->diffForHumans(), null, report: false),
                    'runs_in_background' => (bool) $event->runInBackground,
                    'without_overlapping' => $event->withoutOverlapping,
                    'currently_gated' => ! $willRunNow,
                    'gate_hint' => $gate,
                    'last_status' => $latest?->status,
                    'last_ran_at' => $latest?->created_at,
                    'last_duration_ms' => $latest?->duration_ms,
                    'last_output' => $latest?->output,
                ];
            })
            ->sortBy('command')
            ->values();

        return view('settings.scheduled-jobs', [
            'jobs' => $jobs,
            'retentionDays' => (int) config('clockwork.scheduled_jobs.retention_days', 30),
        ]);
    }

    public function run(Request $request, Schedule $schedule, ActionLogger $logger): RedirectResponse
    {
        $requested = (string) $request->input('command', '');

        // Never trust the client-supplied string directly — only accept it if
        // it exactly matches a command currently in the live schedule.
        $known = collect($schedule->events())
            ->map(fn ($event) => ScheduledCommandName::normalize($event->command))
            ->all();

        if (! in_array($requested, $known, true)) {
            return back()->with('queue_error', "Unknown scheduled command '{$requested}'.");
        }

        // Strip the artisan signature down to just the command word for
        // BackgroundArtisan, which prepends its own php/artisan invocation —
        // passing the full "clockwork:x --flag=y" string through is fine, it
        // accepts any artisan argument string.
        $result = app(BackgroundArtisan::class)->start(
            'scheduled_jobs.run.'.md5($requested),
            [$requested],
            600,
            'scheduled-job-'.md5($requested),
        );

        if ($result->alreadyRunning()) {
            return back()->with('status', "{$requested} is already running.");
        }

        if ($result->failed()) {
            return back()->with('queue_error', $result->error ?? "Could not start {$requested}.");
        }

        $logger->record(
            actionType: ActionLog::TYPE_SCHEDULED_JOB_RUN_NOW,
            summary: "Manually triggered {$requested} from the Scheduled Jobs dashboard.",
            target: $requested,
            actor: (string) (auth()->user()->email ?? 'operator'),
        );

        return back()->with('status', "{$requested} started in the background — refresh in a moment to see the result.");
    }
}
