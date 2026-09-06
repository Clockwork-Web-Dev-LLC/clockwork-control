<?php

namespace App\Console\Commands;

use App\Models\PluginUpdateJob;
use App\Services\Chat\ChatNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net for two failure modes that can leave plugin_update_jobs rows
 * stuck forever, lying to the dashboard and blocking the batch from
 * "completing" for the operator.
 *
 * Pass 1 — STUCK RUNNING (10 min threshold)
 *   A worker died mid-update — OOM killed, host rebooted, hung in a syscall
 *   the kernel won't interrupt. The row stays 'running', the per-row badge
 *   keeps spinning, no progress.
 *
 * Pass 2 — STUCK PENDING (60 min threshold)
 *   Real bug we caught the morning of 2026-06-18: AbstractRunUpdate's
 *   `Cache::lock("site_update:{site_id}", 300)` contention path calls
 *   `$this->release(15)` and returns early. The Laravel worker logs the
 *   job as DONE (no exception thrown), the queue entry vanishes, but the
 *   plugin_update_jobs row never moves past 'pending'. After 3 release
 *   cycles → MaxAttemptsExceeded → failed_jobs entry — but again, the
 *   plugin_update_jobs row stays 'pending'. Result: orphaned rows
 *   forever, the "Batch starting…" banner stays up on /updates, the
 *   user thinks nothing is happening. 60 min is far past any legitimate
 *   "queued waiting for worker" window — worst-case a 200-job batch at
 *   ~10s/job is ~33 min — so 60 min reaps only truly stuck rows.
 *
 *   This pass also CLEARS the orphaned Cache::lock for each affected site.
 *   That lock has a 300s TTL so it should be gone already, but if the
 *   site has been stuck in a contention loop the lock may have been
 *   periodically refreshed by other (also-stuck) jobs. Defensive cleanup.
 *
 * Scheduled hourly. Idempotent — re-running over an already-reaped row
 * is a no-op (filters require live status + age past threshold).
 *
 * Alerting: a row reaped here never went through AbstractRunUpdate's own
 * failure path (that's precisely why it was orphaned — a dead worker or a
 * silent early-return never reaches the try/catch that would have fired
 * ChatNotifier::pluginUpdateFailed). Confirmed live: these reaps happened
 * completely silently until this was added. Mirrors AbstractRunUpdate's
 * same nightly-only gate so manual bulk-update reaps stay quiet.
 */
class ReapStaleUpdateJobs extends Command
{
    public const STUCK_RUNNING_THRESHOLD_MINUTES = 10;

    public const STUCK_PENDING_THRESHOLD_MINUTES = 60;

    protected $signature = 'clockwork:reap-stale-update-jobs';

    protected $description = 'Flip plugin_update_jobs rows orphaned in running (>10 min) or pending (>60 min) to failed.';

    public function handle(ChatNotifier $chatNotifier): int
    {
        $now = Carbon::now();
        $stuckRunningCutoff = $now->copy()->subMinutes(self::STUCK_RUNNING_THRESHOLD_MINUTES);
        $stuckPendingCutoff = $now->copy()->subMinutes(self::STUCK_PENDING_THRESHOLD_MINUTES);

        // Pass 1: stuck running
        $stuckRunning = PluginUpdateJob::query()
            ->where('status', PluginUpdateJob::STATUS_RUNNING)
            ->where('started_at', '<', $stuckRunningCutoff)
            ->get();

        foreach ($stuckRunning as $row) {
            $row->forceFill([
                'status' => PluginUpdateJob::STATUS_FAILED,
                'error' => 'reaped: worker died or hung past '.self::STUCK_RUNNING_THRESHOLD_MINUTES.' min threshold',
                'completed_at' => $now,
            ])->save();
        }

        // Pass 2: orphaned pending
        $stuckPending = PluginUpdateJob::query()
            ->where('status', PluginUpdateJob::STATUS_PENDING)
            ->where('queued_at', '<', $stuckPendingCutoff)
            ->get();

        $sitesToUnlock = [];
        foreach ($stuckPending as $row) {
            $row->forceFill([
                'status' => PluginUpdateJob::STATUS_FAILED,
                'error' => 'reaped: orphaned in pending state past '.self::STUCK_PENDING_THRESHOLD_MINUTES.' min threshold (Cache::lock contention loop or worker silently dropped the job — re-queue from the UI to retry)',
                'completed_at' => $now,
            ])->save();
            $sitesToUnlock[$row->site_id] = true;
        }

        // Clear orphaned site_update Cache locks so the next retry/redispatch
        // for these sites doesn't immediately hit the same contention loop.
        // Laravel's Cache::lock with the same key is acquired via lock()->get();
        // we forcibly release by reconstructing the lock and calling forceRelease().
        $unlocked = 0;
        foreach (array_keys($sitesToUnlock) as $siteId) {
            try {
                Cache::lock('site_update:'.$siteId)->forceRelease();
                $unlocked++;
            } catch (Throwable $e) {
                // Lock driver may not support forceRelease on every backend;
                // log + continue rather than abort the whole reaper run.
                $this->warn("  could not forceRelease site_update:{$siteId} — {$e->getMessage()}");
            }
        }

        $totalReaped = $stuckRunning->count() + $stuckPending->count();
        if ($totalReaped === 0) {
            $this->info('No stale jobs to reap.');

            return self::SUCCESS;
        }

        // Same nightly-only gate AbstractRunUpdate uses for its own live
        // failure ping — manual bulk-update reaps stay quiet since the
        // operator is watching the /updates page in real time.
        foreach ($stuckRunning->merge($stuckPending) as $row) {
            if (! is_string($row->batch_id) || ! str_starts_with($row->batch_id, 'nightly-')) {
                continue;
            }
            try {
                $chatNotifier->pluginUpdateFailed($row->site, $row->fresh());
            } catch (Throwable $e) {
                Log::warning('reap_stale_update_jobs.notify_failed', [
                    'job_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->warn(sprintf(
            'Reaped %d stale update job(s): %d stuck-running, %d stuck-pending. Force-released %d site_update lock(s).',
            $totalReaped,
            $stuckRunning->count(),
            $stuckPending->count(),
            $unlocked,
        ));

        return self::SUCCESS;
    }
}
