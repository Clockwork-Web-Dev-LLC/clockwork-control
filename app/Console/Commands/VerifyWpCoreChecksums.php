<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Security\SecurityScanRecorder;
use App\Services\Security\WpCoreChecksumVerifier;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Server-side counterpart to clockwork:scan-sitecheck — runs `wp core
 * verify-checksums` against every WordPress site, capturing any modified,
 * missing, or unexpectedly-present core files. Transport is per-site: SSH
 * for SpinupWP, Pressable's async command API for Pressable — see
 * WpCoreChecksumVerifier for the branch. Sites with no transport at all
 * (WpCoreChecksumVerifier::hasTransport) are skipped and counted, not
 * recorded as failures.
 *
 * Catches the case Sucuri can't see by design: PHP shells and base64
 * backdoors that aren't rendered into the public HTML. Anything dropped
 * into wp-includes/ or wp-admin/ shows up here.
 */
class VerifyWpCoreChecksums extends Command
{
    protected $signature = 'clockwork:verify-wp-core-checksums
        {--site= : Limit to a specific site ID or domain}';

    protected $description = 'Run wp core verify-checksums against every WordPress site (SSH for SpinupWP, async command API for Pressable) against the wordpress.org SHA256 manifest. Records any modified or missing core files.';

    public function handle(WpCoreChecksumVerifier $verifier, SecurityScanRecorder $recorder, Settings $settings): int
    {
        $sites = $this->targetSites();

        if ($sites->isEmpty()) {
            $this->warn('No sites match.');

            return self::SUCCESS;
        }

        // A site with no way to run wp-cli (a standalone site on a host we
        // have no shell access to) can never be checked. Recording a failed
        // row for it every night only turned the job red and buried real
        // failures, so it's skipped and counted instead.
        [$sites, $noTransport] = $sites->partition(fn (Site $site) => $verifier->hasTransport($site));
        $skipped = $noTransport->count();
        if ($skipped > 0) {
            Log::info('verify-wp-core-checksums: skipped sites with no checksum transport', [
                'count' => $skipped,
                'site_ids' => $noTransport->pluck('id')->values()->all(),
            ]);
            if ($this->option('site') || $this->getOutput()->isVerbose()) {
                foreach ($noTransport as $site) {
                    $this->line("  - {$site->domain}: skipped, no SSH, Pressable or host command runner to run wp-cli");
                }
            }
        }

        $clean = 0;
        $issues = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($sites->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();

        foreach ($sites as $site) {
            $bar->setMessage($site->domain);

            $result = $verifier->verify($site);
            $recorder->record($result);

            if ($result->isClean()) {
                $clean++;
            } elseif ($result->hasIssues()) {
                $issues++;
                if ($this->getOutput()->isVerbose()) {
                    $bar->clear();
                    $this->line("  ⚠ {$site->domain}: {$result->summary}");
                    $bar->display();
                }
            } else {
                $failed++;
                if ($this->getOutput()->isVerbose()) {
                    $bar->clear();
                    $this->line("  ✗ {$site->domain}: {$result->error}");
                    $bar->display();
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info("Done. clean={$clean}, issues={$issues}, failed={$failed}, skipped={$skipped}");

        $settings->put('security_scans.checksums_last_run_at', now()->toIso8601String());

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Care-plan filter: scans are a paid deliverable. The unfiltered, scheduled
     * run skips any site without `care_plan_enabled=true`. `--site=X` overrides
     * the gate so we can still smoke-test a specific site (or diagnose a
     * misconfigured care_plan flag) without flipping the column first.
     *
     * @return Collection<int, Site>
     */
    private function targetSites()
    {
        $q = Site::query()
            ->with('server')
            ->where('is_wordpress', true)
            ->hostMonitored()
            ->orderBy('domain');

        if ($siteOpt = $this->option('site')) {
            $q->where(function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });
        } else {
            $q->carePlanEligible();
        }

        return $q->get();
    }
}
