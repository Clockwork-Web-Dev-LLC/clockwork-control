<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Security\SecurityScanRecorder;
use App\Services\Security\WpCoreChecksumVerifier;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Server-side counterpart to clockwork:scan-sitecheck — runs `wp core
 * verify-checksums` against every WordPress site, capturing any modified,
 * missing, or unexpectedly-present core files. Transport is per-site: SSH
 * for SpinupWP, Pressable's async command API for Pressable — see
 * WpCoreChecksumVerifier for the branch.
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
        $this->info("Done. clean={$clean}, issues={$issues}, failed={$failed}");

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
            $q->where('care_plan_enabled', true);
        }

        return $q->get();
    }
}
