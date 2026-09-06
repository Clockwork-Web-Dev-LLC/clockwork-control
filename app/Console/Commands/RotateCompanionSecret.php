<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rotates the per-site HMAC secret on Companion-equipped sites.
 *
 * Flow per site:
 *   1. Sign POST /secret/rotate with the OLD secret.
 *   2. Companion verifies, generates NEW secret, returns it.
 *   3. We persist NEW into sites.companion_secret in the same DB transaction
 *      that records the rotation in action_logs.
 *
 * Failure handling:
 *   - secret_pinned (409): site uses wp-config.php constant — log it, skip,
 *     do NOT touch sites.companion_secret.
 *   - 404 (older Companion): we did not rotate; recommend an upgrade pass.
 *   - transport/timeout: leave Clockwork's stored secret untouched. The OLD
 *     secret still works (Companion never persisted a new one if the
 *     response didn't return ok).
 *   - "we got a NEW secret but DB save failed": only realistic via DB error
 *     after the HTTP response. The transaction rolls back; Clockwork keeps
 *     the OLD secret while Companion has the NEW one — that's the desync.
 *     Recovery: `clockwork:install-companion <site>` regenerates fresh.
 */
class RotateCompanionSecret extends Command
{
    protected $signature = 'clockwork:rotate-companion-secret
                            {--site= : Limit to a single site domain or ID}
                            {--all : Rotate every Companion-installed site}
                            {--dry-run : List what would be rotated without calling Companion}';

    protected $description = 'Rotate the per-site HMAC secret used for Clockwork → Companion auth.';

    public function handle(ActionLogger $logger): int
    {
        $single = $this->option('site');
        $all = (bool) $this->option('all');

        if (! $single && ! $all) {
            $this->error('Pass --site=<domain|id> for a single rotation, or --all for the fleet.');

            return self::FAILURE;
        }

        $query = Site::query()
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret');

        if ($single) {
            $query->where(function ($q) use ($single) {
                $q->where('id', $single)->orWhere('domain', $single);
            });
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->warn('No matching Companion-installed sites.');

            return self::SUCCESS;
        }

        $rotated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($sites as $site) {
            $this->line("→ {$site->domain}");

            if ($this->option('dry-run')) {
                $rotated++;

                continue;
            }

            try {
                $result = (new ClockworkCompanionClient($site))->rotateSecret();
            } catch (Throwable $e) {
                $this->error("  transport error: {$e->getMessage()}");
                $failed++;

                continue;
            }

            if ($result['ok'] === false) {
                $this->warn("  skipped ({$result['error_code']}): {$result['error']}");
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($site, $result, $logger) {
                    $site->forceFill(['companion_secret' => $result['secret']])->save();

                    $logger->record(
                        actionType: ActionLog::TYPE_COMPANION_SECRET_ROTATED,
                        summary: "Rotated Companion secret on {$site->domain}",
                        site: $site,
                        ok: true,
                        actor: 'manual',
                    );
                });
                $this->info('  rotated');
                $rotated++;
            } catch (Throwable $e) {
                // Companion has the new secret; we failed to persist. Loud,
                // because the recovery is a reinstall.
                $this->error('  DB save failed AFTER Companion rotated — reinstall needed: '.$e->getMessage());
                $failed++;
            }
        }

        $this->info("Rotated: {$rotated} | Skipped: {$skipped} | Failed: {$failed} | Total: ".$sites->count());

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
