<?php

namespace Modules\ClientReports\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Throwable;

/**
 * The Recompile action for every unsent scheduled report at once: each is
 * recompiled for its own period from current data through
 * ScheduledReportRunner::recompile(), its old copy superseded and the new
 * one checked for review. It never sends anything and never moves a
 * schedule on. Meant for refreshing a batch of held drafts after report
 * fixes, before anyone sends them by hand.
 */
class RecompileHeldClientReports extends Command
{
    protected $signature = 'clockwork:recompile-held-client-reports
        {--outdated : Only reports compiled under older report rules (schema_version below the current one)}
        {--site= : Limit to one site id or domain}
        {--dry-run : List what would be recompiled, change nothing}';

    protected $description = 'Recompile every unsent scheduled client report from current data (never sends).';

    public function handle(ScheduledReportRunner $runner): int
    {
        $query = ClientReport::query()
            ->whereNotNull('schedule_id')
            ->where('status', ClientReport::STATUS_GENERATED)
            ->with('site')
            ->orderBy('id');

        if ($target = $this->option('site')) {
            $query->whereHas('site', fn ($q) => is_numeric($target) ? $q->where('id', (int) $target) : $q->where('domain', $target));
        }

        $reports = $query->get()
            ->filter(fn (ClientReport $r) => $r->canRecompile() && (! $this->option('outdated') || $r->isStale()))
            ->values();

        if ($reports->isEmpty()) {
            $this->info('Nothing to recompile.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $recompiled = 0;
        $held = 0;
        $failed = 0;

        foreach ($reports as $report) {
            $label = sprintf('#%d %s (%s – %s)', $report->id, $report->site?->domain ?? 'unknown site', $report->period_start->format('M j'), $report->period_end->format('M j, Y'));

            if ($dryRun) {
                $this->line("{$label}: would recompile".($report->isStale() ? ' (outdated)' : ''));

                continue;
            }

            try {
                $run = $runner->recompile($report);
                $recompiled++;
                if ($run->held()) {
                    $held++;
                }
                $this->line("{$label}: recompiled as #{$run->report->id}".($run->held() ? ', held: '.implode(' ', $run->heldReasons) : ', nothing to review'));
            } catch (Throwable $e) {
                $failed++;
                Log::error("Recompiling client report #{$report->id} failed: {$e->getMessage()}", ['exception' => $e]);
                $this->error("{$label}: failed: {$e->getMessage()}");
            }
        }

        if ($dryRun) {
            $this->info($reports->count().' report(s) would be recompiled. Nothing was changed or sent.');

            return self::SUCCESS;
        }

        $this->info("Recompiled {$recompiled} report(s), {$held} held for review, {$failed} failed. Nothing was sent.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
