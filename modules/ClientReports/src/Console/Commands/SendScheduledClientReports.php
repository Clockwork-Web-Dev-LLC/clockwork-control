<?php

namespace Modules\ClientReports\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Throwable;

class SendScheduledClientReports extends Command
{
    protected $signature = 'clockwork:send-client-reports
        {--site= : Limit run to a specific site id or domain}
        {--force : Force generation even if not yet due}';

    protected $description = 'Generate and email scheduled client reports for care plan websites.';

    public function handle(ScheduledReportRunner $runner): int
    {
        $isForce = (bool) $this->option('force');

        $query = ClientReportSchedule::query()
            ->where('is_enabled', true)
            ->with(['site', 'template']);

        if ($target = $this->option('site')) {
            $query->whereHas('site', function ($q) use ($target) {
                if (is_numeric($target)) {
                    $q->where('id', (int) $target);
                } else {
                    $q->where('domain', $target);
                }
            });
        }

        $schedules = $query->get();
        if ($schedules->isEmpty()) {
            $this->info('No active client report schedules found.');

            return self::SUCCESS;
        }

        $processed = 0;
        $crashed = 0;
        $undelivered = 0;

        foreach ($schedules as $schedule) {
            $site = $schedule->site;
            if (! $site || $site->is_inactive) {
                continue;
            }

            // Check due date unless forced
            if (! $isForce && ! $schedule->isDue()) {
                $dueDate = $schedule->next_run_at ? $schedule->next_run_at->toFormattedDateString() : 'unknown';
                $this->line("  - {$site->domain}: skipped (not due until {$dueDate})");

                continue;
            }

            // Reports are for care-plan customers. A due schedule still moves on
            // to next month, so turning the care plan back on later doesn't send
            // a report for a month the site had none.
            if (! ScheduledReportRunner::siteIsEligible($site)) {
                $schedule->advanceNextRun();
                $this->line("  - {$site->domain}: skipped this month (care plan is off; reports are for care-plan sites)");

                continue;
            }

            $recipients = array_values(array_filter((array) $schedule->recipients));
            $isAuto = $schedule->delivery_mode !== ClientReportSchedule::MODE_DRAFT;

            if ($isAuto && empty($recipients)) {
                $this->line("  - {$site->domain}: skipped (auto delivery mode requires at least one recipient)");

                continue;
            }

            try {
                $run = $runner->run($schedule);

                if ($run->held()) {
                    $this->warn("  ! {$site->domain}: held as a draft for review: ".implode(' ', $run->heldReasons));
                } elseif ($run->isDraft) {
                    $this->line("  ✓ {$site->domain}: generated draft report (awaiting operator review)");
                } elseif ($run->complete() && $run->sentTo === []) {
                    $this->line("  ✓ {$site->domain}: every recipient already had this report; nothing sent");
                } elseif ($run->complete()) {
                    $resumed = $run->resumed ? ' (resumed earlier partial send)' : '';
                    $this->line("  ✓ {$site->domain}: sent report to ".implode(', ', $run->sentTo).$resumed);
                } else {
                    $undelivered++;
                    $this->error("  ✗ {$site->domain}: failed for ".implode(', ', $run->failed)
                        .'; will retry only those recipients on the next run');
                }

                $processed++;
            } catch (Throwable $e) {
                $crashed++;
                $this->error("  ✗ {$site->domain}: {$e->getMessage()}");
                Log::error("Failed sending scheduled client report for {$site->domain}: {$e->getMessage()}", [
                    'site_id' => $site->id,
                    'schedule_id' => $schedule->id,
                    'exception' => $e,
                ]);
            }
        }

        $this->info("Scheduled client reports dispatch completed ({$processed} processed).");

        // A site whose report crashed, or whose email didn't reach everyone
        // (a mail outage is the likely one), stays due and is retried
        // tomorrow; exit as failed so Scheduled Jobs shows it instead of a
        // quiet green run.
        if ($crashed > 0) {
            $this->error("{$crashed} site(s) failed; see laravel.log (\"Failed sending scheduled client report\").");
        }
        if ($undelivered > 0) {
            $this->error("{$undelivered} site(s) have recipients who didn't get the report; see laravel.log (\"failed to send to\"). They're retried tomorrow.");
        }
        if ($crashed > 0 || $undelivered > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
