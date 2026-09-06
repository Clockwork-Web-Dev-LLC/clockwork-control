<?php

use App\Models\Site;
use App\Services\Forms\ContactFormDetector;

/*
|--------------------------------------------------------------------------
| clockwork:detect-contact-forms
|--------------------------------------------------------------------------
|
| ContactFormDetector::detect()'s own internal logic (Companion HTTP call,
| form normalization/auto-pick, DB writes) is untouched here — it's mocked
| at that boundary. What's under test is the command's OWN job: which
| sites it selects (care_plan_enabled default vs. --site override, both
| scoped to companion_installed) and how it tallies/reports the four
| possible per-site results into an overall exit code.
*/

describe('clockwork:detect-contact-forms — site selection', function () {
    it('defaults to companion-installed, care-plan-enabled sites only', function () {
        $eligible = Site::factory()->create([
            'companion_installed' => true,
            'care_plan_enabled' => true,
            'domain' => 'eligible.example.com',
        ]);
        $noCarePlan = Site::factory()->create([
            'companion_installed' => true,
            'care_plan_enabled' => false,
            'domain' => 'no-care-plan.example.com',
        ]);
        $noCompanion = Site::factory()->create([
            'companion_installed' => false,
            'care_plan_enabled' => true,
            'domain' => 'no-companion.example.com',
        ]);

        $this->mock(ContactFormDetector::class, function ($mock) use ($eligible) {
            $mock->shouldReceive('detect')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($eligible))
                ->andReturn(['result' => ContactFormDetector::RESULT_DETECTED, 'message' => 'Detected CF7; 1 form.']);
        });

        $this->artisan('clockwork:detect-contact-forms')
            ->expectsOutputToContain('detected=1, no-form-plugin=0, skipped=0, failed=0')
            ->assertSuccessful();
    });

    it('--site bypasses the care-plan filter but still requires companion_installed', function () {
        $target = Site::factory()->create([
            'companion_installed' => true,
            'care_plan_enabled' => false,
            'domain' => 'target.example.com',
        ]);

        $this->mock(ContactFormDetector::class, function ($mock) use ($target) {
            $mock->shouldReceive('detect')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($target))
                ->andReturn(['result' => ContactFormDetector::RESULT_NO_FORM_PLUGIN, 'message' => 'No supported form plugin active.']);
        });

        $this->artisan('clockwork:detect-contact-forms', ['--site' => 'target.example.com'])
            ->expectsOutputToContain('detected=0, no-form-plugin=1, skipped=0, failed=0')
            ->assertSuccessful();
    });

    it('--site matches by numeric ID as well as domain', function () {
        $target = Site::factory()->create([
            'companion_installed' => true,
            'domain' => 'by-id.example.com',
        ]);

        $this->mock(ContactFormDetector::class, function ($mock) use ($target) {
            $mock->shouldReceive('detect')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($target))
                ->andReturn(['result' => ContactFormDetector::RESULT_DETECTED, 'message' => 'ok']);
        });

        $this->artisan('clockwork:detect-contact-forms', ['--site' => (string) $target->id])
            ->assertSuccessful();
    });

    it('warns and succeeds with no matching sites', function () {
        $this->mock(ContactFormDetector::class, function ($mock) {
            $mock->shouldNotReceive('detect');
        });

        $this->artisan('clockwork:detect-contact-forms')
            ->expectsOutputToContain('No care-plan sites with Companion installed found.')
            ->assertSuccessful();
    });
});

describe('clockwork:detect-contact-forms — result tallying + exit code', function () {
    it('tallies every result bucket and exits FAILURE when any site failed', function () {
        $sites = Site::factory()->count(4)->sequence(
            ['domain' => 'a.example.com'],
            ['domain' => 'b.example.com'],
            ['domain' => 'c.example.com'],
            ['domain' => 'd.example.com'],
        )->create([
            'companion_installed' => true,
            'care_plan_enabled' => true,
        ]);

        $results = [
            ContactFormDetector::RESULT_DETECTED,
            ContactFormDetector::RESULT_NO_FORM_PLUGIN,
            ContactFormDetector::RESULT_SKIPPED,
            ContactFormDetector::RESULT_FAILED,
        ];

        $this->mock(ContactFormDetector::class, function ($mock) use ($sites, $results) {
            foreach ($sites->values() as $i => $site) {
                $mock->shouldReceive('detect')
                    ->once()
                    ->withArgs(fn (Site $s) => $s->is($site))
                    ->andReturn(['result' => $results[$i], 'message' => 'msg']);
            }
        });

        $this->artisan('clockwork:detect-contact-forms')
            ->expectsOutputToContain('detected=1, no-form-plugin=1, skipped=1, failed=1')
            ->assertFailed();
    });
});
