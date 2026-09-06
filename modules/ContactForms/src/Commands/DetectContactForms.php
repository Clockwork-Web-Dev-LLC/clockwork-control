<?php

namespace Modules\ContactForms\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Modules\ContactForms\ContactFormDetector;

class DetectContactForms extends Command
{
    protected $signature = 'clockwork:detect-contact-forms
        {--site= : Limit to a specific site ID or domain}';

    protected $description = 'Detect installed form plugin + cache available form IDs on every care-plan site so the per-site Forms tab dropdown has real options when someone goes to add a form-test.';

    public function handle(ContactFormDetector $detector): int
    {
        $sites = $this->targetSites();

        if ($sites->isEmpty()) {
            $this->warn('No care-plan sites with Companion installed found.');

            return self::SUCCESS;
        }

        $detected = 0;
        $noForm = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($sites as $site) {
            $this->line("→ {$site->domain}");
            $result = $detector->detect($site);

            switch ($result['result']) {
                case ContactFormDetector::RESULT_DETECTED:
                    $this->info("  ✓ {$result['message']}");
                    $detected++;
                    break;
                case ContactFormDetector::RESULT_NO_FORM_PLUGIN:
                    $this->warn("  ! {$result['message']}");
                    $noForm++;
                    break;
                case ContactFormDetector::RESULT_SKIPPED:
                    $this->line("  · {$result['message']}");
                    $skipped++;
                    break;
                default:
                    $this->error("  ✗ {$result['message']}");
                    $failed++;
            }
        }

        $this->line('');
        $this->info("Done. detected={$detected}, no-form-plugin={$noForm}, skipped={$skipped}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function targetSites()
    {
        $q = Site::query()->with('server')->where('companion_installed', true);

        if ($siteOpt = $this->option('site')) {
            // --site bypasses the care-plan filter so the operator can run
            // detection on any site with Companion regardless of plan status.
            // Matches the TestContactForms + ScanSiteCheck + RunPerformanceScans pattern.
            $q->where(function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });
        } else {
            $q->where('care_plan_enabled', true);
        }

        return $q->orderBy('domain')->get();
    }
}
