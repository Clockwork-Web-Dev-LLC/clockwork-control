<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\CompanionInstaller;
use App\Support\CompanionExclusion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Off-request Companion install/update, for hosts whose transport can run
 * well past a web request's execution budget. Pressable's async command API
 * polls in 5s increments with a 120s per-call timeout, and a full install
 * issues several such calls (upload, assemble, extract, activate) — the
 * synchronous SitesController::installCompanion() path timed out on Pressable
 * sites even after raising set_time_limit(), because a reverse-proxy /
 * fastcgi read timeout in front of PHP has its own, unrelated ceiling.
 *
 * Result lands in action_logs (recordCompanionInstall) exactly as it would
 * from the synchronous path — SitesController::installCompanionStatus()
 * polls for the first row newer than the job's queued_at to report progress.
 */
class InstallCompanionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $siteId,
        public readonly bool $rotateSecret = false,
    ) {}

    public function handle(ActionLogger $logger): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        // Same policy denylist every CLI deploy path enforces — a queued
        // install must not be a bypass route either. Silently skip: the
        // dispatching controller already rejects excluded domains with a
        // visible 422, so reaching here excluded means a race or a direct
        // dispatch; either way, don't install.
        if (app(CompanionExclusion::class)->isExcluded($site->domain)) {
            return;
        }

        $installer = $site->host()->companionInstaller();
        if ($installer === null) {
            // View-Only provider — same nullable contract as the synchronous
            // path. Nothing to log; nothing was attempted.
            return;
        }

        try {
            $result = $installer->installOrUpdate($site, $this->rotateSecret);
        } catch (Throwable $e) {
            $logger->recordCompanionInstall($site, [
                'result' => CompanionInstaller::RESULT_FAILED,
                'message' => 'Install threw: '.$e->getMessage(),
            ]);

            return;
        }

        $logger->recordCompanionInstall($site, $result);
    }
}
