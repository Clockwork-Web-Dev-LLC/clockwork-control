<?php

namespace Modules\ContactForms\Commands;

use App\Models\ContactFormTest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\ContactForms\ContactFormTester;

class TestContactForms extends Command
{
    protected $signature = 'clockwork:test-contact-forms
        {--site= : Limit to a specific site ID or domain (bypasses care-plan filter)}
        {--form= : Limit to a specific contact_form_tests.id}
        {--force : Run even if the row is not yet due per its frequency}
        {--mode=lab : lab (default; suppress real send) or live (let mail go through)}';

    protected $description = 'Run contact-form tests on every care-plan site whose configured form-tests are due.';

    public function handle(ContactFormTester $tester): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['lab', 'live'], true)) {
            $this->error("--mode must be 'lab' or 'live'.");

            return self::FAILURE;
        }

        $tests = $this->targetTests();

        if ($tests->isEmpty()) {
            $this->warn('No form-tests due.');

            return self::SUCCESS;
        }

        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $transitioned = 0;

        foreach ($tests as $cft) {
            $domain = $cft->site->domain ?? "site#{$cft->site_id}";
            $this->line("→ {$domain}  form={$cft->form_id}  ({$cft->frequency})");

            $result = $tester->test($cft, $mode);

            switch ($result['result']) {
                case ContactFormTester::RESULT_SUCCESS:
                    $this->info("  ✓ {$result['message']}");
                    $passed++;
                    break;
                case ContactFormTester::RESULT_FAILED:
                    $this->error("  ✗ {$result['message']}");
                    $failed++;
                    break;
                case ContactFormTester::RESULT_SKIPPED:
                    $this->line("  · {$result['message']}");
                    $skipped++;
                    break;
            }

            if (! empty($result['transitioned'])) {
                $transitioned++;
            }
        }

        $this->line('');
        $this->info("Done. passed={$passed}, failed={$failed}, skipped={$skipped}, transitions={$transitioned}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, ContactFormTest> */
    private function targetTests()
    {
        $q = ContactFormTest::query()
            ->with('site.server')
            ->where('enabled', true);

        $siteOpt = $this->option('site');
        $formOpt = $this->option('form');

        if ($formOpt) {
            // --form targets a specific row; bypasses both filters.
            $q->where('id', (int) $formOpt);
        } elseif ($siteOpt) {
            // --site bypasses the care-plan filter so the operator can
            // smoke-test a specific site regardless of plan status.
            $q->whereHas('site', function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });
        } else {
            // Scheduled run: care-plan only + due now.
            $q->whereHas('site', fn ($q) => $q->where('care_plan_enabled', true));

            if (! $this->option('force')) {
                $this->applyDueFilter($q);
            }
        }

        return $q->orderBy('site_id')->orderBy('slot')->get();
    }

    /**
     * "Is this row due?" — never-tested rows always qualify; otherwise the
     * row must be older than its frequency window.
     *
     * @param  Builder<ContactFormTest>  $q
     */
    private function applyDueFilter($q): void
    {
        $dailyCutoff = now()->subDay();
        $weeklyCutoff = now()->subDays(7);

        $q->where(function ($q) use ($dailyCutoff, $weeklyCutoff) {
            $q->whereNull('last_test_at')
                ->orWhere(function ($q) use ($dailyCutoff) {
                    $q->where('frequency', ContactFormTest::FREQUENCY_DAILY)
                        ->where('last_test_at', '<', $dailyCutoff);
                })
                ->orWhere(function ($q) use ($weeklyCutoff) {
                    $q->where('frequency', ContactFormTest::FREQUENCY_WEEKLY)
                        ->where('last_test_at', '<', $weeklyCutoff);
                });
        });
    }
}
