<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Security\BlacklistChecker;
use App\Services\Security\SecurityScanRecorder;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Domain blacklist check — does any major reputation list flag this site?
 *
 * Recovers the most useful signal from Sucuri SiteCheck (the "is this domain
 * blacklisted?" check) without depending on Sucuri being able to fetch the
 * homepage. Their scanner gets 403'd by Cloudflare WAFs in front of our
 * sites; this path queries blacklist sources directly.
 *
 * Hosting-tier feature, NOT care-plan-gated. Every Companion-equipped (or
 * SSH-equipped) site benefits from knowing it's not on a major blocklist —
 * this is the same value as "your site shows a red Chrome warning, you
 * probably want to know." Care plan still adds the deeper SiteCheck +
 * core-checksum verification.
 *
 * Sequential: ~100ms per site, no rate-limit concerns. 150 sites = ~15s.
 */
class CheckBlacklists extends Command
{
    protected $signature = 'clockwork:check-blacklists
        {--site= : Limit to a specific site ID or domain}';

    protected $description = 'Check every site against free domain-reputation blacklists (URLHaus, Spamhaus DBL, optional Google Safe Browsing).';

    public function handle(BlacklistChecker $checker, SecurityScanRecorder $recorder, Settings $settings): int
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
            $result = $checker->check($site);
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

        $settings->put('security_scans.blacklist_last_run_at', now()->toIso8601String());

        return $failed > 0 && $clean === 0 && $issues === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function targetSites()
    {
        $q = Site::query()
            ->with('server')
            ->whereHas('server', fn ($q) => $q->monitored())
            ->orderBy('domain');

        if ($siteOpt = $this->option('site')) {
            $q->where(function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });
        }

        return $q->get();
    }
}
