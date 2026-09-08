<?php

namespace Modules\ClientReports\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Services\ClientReportCompiler;
use Throwable;

class SendScheduledClientReports extends Command
{
    protected $signature = 'clockwork:send-client-reports
        {--site= : Limit run to a specific site id or domain}
        {--force : Force generation even if not yet due}';

    protected $description = 'Generate and email scheduled client reports for care plan websites.';

    public function handle(ClientReportCompiler $compiler): int
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

        $now = now();
        $start = $now->copy()->subMonth()->startOfMonth();
        $end = $now->copy()->subMonth()->endOfMonth();

        $processed = 0;

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

            $recipients = array_values(array_filter((array) $schedule->recipients));
            $isAuto = $schedule->delivery_mode !== ClientReportSchedule::MODE_DRAFT;

            if ($isAuto && empty($recipients)) {
                $this->line("  - {$site->domain}: skipped (auto delivery mode requires at least one recipient)");

                continue;
            }

            try {
                $sections = $schedule->template ? $schedule->template->sections : null;

                $sectionsData = $compiler->compile(
                    site: $site,
                    periodStart: $start,
                    periodEnd: $end,
                    sections: $sections
                );

                $title = "Maintenance Report: {$site->domain} ({$start->format('M Y')})";

                $report = ClientReport::create([
                    'site_id' => $site->id,
                    'template_id' => $schedule->template_id,
                    'title' => $title,
                    'period_start' => $start,
                    'period_end' => $end,
                    'sections_data' => $sectionsData,
                    'client_name' => $site->domain,
                    'client_email' => ! empty($recipients) ? implode(', ', $recipients) : null,
                    'status' => 'generated',
                ]);

                if ($isAuto) {
                    $reportUrl = route('client-reports.public', ['token' => $report->public_token]);

                    foreach ($recipients as $email) {
                        Mail::to($email)->send(new ClientReportMail($report, $reportUrl));
                    }

                    $report->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                    ]);

                    $schedule->update([
                        'last_sent_at' => now(),
                    ]);

                    $this->line("  ✓ {$site->domain}: sent report to ".implode(', ', $recipients));
                } else {
                    $this->line("  ✓ {$site->domain}: generated draft report (awaiting operator review)");
                }

                $schedule->advanceNextRun();
                $processed++;
            } catch (Throwable $e) {
                $this->error("  ✗ {$site->domain}: {$e->getMessage()}");
                Log::error("Failed sending scheduled client report for {$site->domain}: {$e->getMessage()}", [
                    'site_id' => $site->id,
                    'schedule_id' => $schedule->id,
                    'exception' => $e,
                ]);
            }
        }

        $this->info("Scheduled client reports dispatch completed ({$processed} processed).");

        return self::SUCCESS;
    }
}
