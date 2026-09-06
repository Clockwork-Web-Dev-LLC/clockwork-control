<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-push action_log rows that never made it (or are no longer present) on
 * Companion's mirror table. Two cases this handles:
 *
 *   1. Site existed and accumulated action_logs BEFORE Companion was first
 *      installed. Day-of-install pushes new rows but historical rows have
 *      `companion_pushed_at = NULL`.
 *
 *   2. Site got migrated and Companion was reinstalled. The local mirror
 *      table on the new server is empty; rows pushed previously have a
 *      stale companion_pushed_at on this side. (Caller can pass
 *      `--reset-since=YYYY-MM-DD` to ignore older marks and re-push.)
 *
 * Idempotency: ActionLogger marks rows companion_pushed_at on success, so
 * re-running without --reset-since will skip already-pushed rows. The
 * companion_pushed_at column was added in the same release as this command.
 *
 * Limit: defaults to 500 rows per site to avoid hammering a slow site.
 * Bump with --limit=N.
 */
class BackfillCompanionActionLog extends Command
{
    protected $signature = 'clockwork:backfill-companion-action-log
        {--site= : Limit to a single site (id or domain)}
        {--server= : Limit to sites on one server (id, name, or hostname)}
        {--limit=500 : Max rows pushed per site}
        {--reset-since= : Re-push rows whose companion_pushed_at is older than this YYYY-MM-DD; leaves newer marks alone}';

    protected $description = 'Re-push action_log rows missing from each site\'s Companion mirror (post-reinstall recovery).';

    public function handle(): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->warn('No Companion-equipped sites matched.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $resetSince = $this->option('reset-since');
        $resetCutoff = $resetSince ? strtotime($resetSince) : null;
        if ($resetSince && $resetCutoff === false) {
            $this->error("Invalid --reset-since value: {$resetSince}. Use YYYY-MM-DD.");

            return self::FAILURE;
        }

        $stats = ['ok' => 0, 'failed' => 0, 'skipped_no_capability' => 0, 'sites_with_nothing' => 0];

        foreach ($sites as $site) {
            $caps = $site->companion_capabilities ?? [];
            if (! in_array('action-log', $caps, true)) {
                $stats['skipped_no_capability']++;
                $this->line("  [skip] {$site->domain} — capability 'action-log' not advertised");

                continue;
            }

            // Optional reset: clear companion_pushed_at for old rows so they
            // get re-pushed. Recent marks stay intact.
            if ($resetCutoff !== null) {
                ActionLog::query()
                    ->where('site_id', $site->id)
                    ->where('companion_pushed_at', '<', date('Y-m-d H:i:s', $resetCutoff))
                    ->update(['companion_pushed_at' => null]);
            }

            $rows = ActionLog::query()
                ->where('site_id', $site->id)
                ->whereNull('companion_pushed_at')
                ->orderBy('ran_at')
                ->limit($limit)
                ->get();

            if ($rows->isEmpty()) {
                $stats['sites_with_nothing']++;

                continue;
            }

            $client = new ClockworkCompanionClient($site);
            $okCount = 0;
            $failCount = 0;

            foreach ($rows as $row) {
                try {
                    $client->appendActionLog([
                        'action_type' => $row->action_type,
                        'target' => $row->target,
                        'summary' => $row->summary,
                        'details' => $row->details,
                        'ok' => (bool) $row->ok,
                        'error' => $row->error,
                        'elapsed_ms' => $row->elapsed_ms,
                        'actor' => $row->actor,
                        'care_plan_enabled' => (bool) ($site->care_plan_enabled ?? false),
                        'ran_at' => $row->ran_at?->toIso8601String(),
                    ]);
                    $row->forceFill(['companion_pushed_at' => now()])->save();
                    $okCount++;
                } catch (Throwable $e) {
                    $failCount++;
                    Log::warning('action_log.backfill_push_failed', [
                        'site_id' => $site->id,
                        'action_log_id' => $row->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $stats['ok'] += $okCount;
            $stats['failed'] += $failCount;
            $marker = $failCount > 0 ? '[part]' : '[ok]  ';
            $this->line("  {$marker} {$site->domain} — pushed={$okCount} failed={$failCount}");
        }

        $msg = sprintf(
            'companion.action_log.backfill complete: rows_ok=%d rows_failed=%d sites_skipped=%d sites_already_clean=%d',
            $stats['ok'],
            $stats['failed'],
            $stats['skipped_no_capability'],
            $stats['sites_with_nothing'],
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret')
            ->hostMonitored();

        if ($needle = $this->option('site')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', $needle)->orWhere('domain', $needle);
            });
        }

        if ($needle = $this->option('server')) {
            $q->whereHas('server', function ($q) use ($needle) {
                $q->where('id', $needle)
                    ->orWhere('name', $needle)
                    ->orWhere('hostname', $needle);
            });
        }

        return $q->orderBy('domain')->get();
    }
}
