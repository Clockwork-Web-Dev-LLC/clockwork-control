<?php

use App\Models\ContactFormTest;
use App\Models\Site;
use App\Services\Forms\ContactFormTester;

/*
|--------------------------------------------------------------------------
| clockwork:test-contact-forms
|--------------------------------------------------------------------------
|
| ContactFormTester::test()'s own send/notify logic is covered separately
| (tests/Feature/Forms/ContactFormTesterNotificationTest.php). This suite
| covers the command's own job: --mode validation, which rows it selects
| (--form / --site / default due-filter, each with different care-plan and
| frequency-cutoff semantics), and result tallying into an exit code.
*/

describe('clockwork:test-contact-forms — --mode validation', function () {
    it('rejects an invalid --mode without ever touching the tester', function () {
        $this->mock(ContactFormTester::class, fn ($mock) => $mock->shouldNotReceive('test'));

        $this->artisan('clockwork:test-contact-forms', ['--mode' => 'bogus'])
            ->expectsOutputToContain("--mode must be 'lab' or 'live'.")
            ->assertFailed();
    });

    it('accepts --mode=live and passes it through to the tester', function () {
        $test = ContactFormTest::factory()->create([
            'site_id' => Site::factory()->create(['care_plan_enabled' => true])->id,
            'last_test_at' => null,
        ]);

        $this->mock(ContactFormTester::class, function ($mock) use ($test) {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t, string $mode) => $t->is($test) && $mode === 'live')
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'ok']);
        });

        $this->artisan('clockwork:test-contact-forms', ['--mode' => 'live'])->assertSuccessful();
    });
});

describe('clockwork:test-contact-forms — row selection', function () {
    it('warns and succeeds when nothing is due', function () {
        $this->mock(ContactFormTester::class, fn ($mock) => $mock->shouldNotReceive('test'));

        $this->artisan('clockwork:test-contact-forms')
            ->expectsOutputToContain('No form-tests due.')
            ->assertSuccessful();
    });

    it('--form targets a specific row, bypassing both the care-plan and due filters', function () {
        $site = Site::factory()->create(['care_plan_enabled' => false]);
        $test = ContactFormTest::factory()->create([
            'site_id' => $site->id,
            'last_test_at' => now(), // freshly tested — would never be "due"
        ]);

        $this->mock(ContactFormTester::class, function ($mock) use ($test) {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($test))
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'ok']);
        });

        $this->artisan('clockwork:test-contact-forms', ['--form' => (string) $test->id])->assertSuccessful();
    });

    it('--site bypasses the care-plan filter but not the enabled flag', function () {
        $site = Site::factory()->create(['care_plan_enabled' => false, 'domain' => 'no-care-plan.example.com']);
        $test = ContactFormTest::factory()->create([
            'site_id' => $site->id,
            'slot' => 1,
            'enabled' => true,
            'last_test_at' => null,
        ]);
        $disabled = ContactFormTest::factory()->create([
            'site_id' => $site->id,
            'slot' => 2,
            'enabled' => false,
            'last_test_at' => null,
        ]);

        $this->mock(ContactFormTester::class, function ($mock) use ($test) {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($test))
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'ok']);
        });

        $this->artisan('clockwork:test-contact-forms', ['--site' => 'no-care-plan.example.com'])->assertSuccessful();
    });

    it('default scheduled run requires care_plan_enabled AND applies the due filter (never-tested rows always due)', function () {
        $carePlanSite = Site::factory()->create(['care_plan_enabled' => true]);
        $nonCarePlanSite = Site::factory()->create(['care_plan_enabled' => false]);

        $due = ContactFormTest::factory()->create([
            'site_id' => $carePlanSite->id,
            'last_test_at' => null,
        ]);
        ContactFormTest::factory()->create([
            'site_id' => $nonCarePlanSite->id,
            'last_test_at' => null,
        ]);

        $this->mock(ContactFormTester::class, function ($mock) use ($due) {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($due))
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'ok']);
        });

        $this->artisan('clockwork:test-contact-forms')->assertSuccessful();
    });

    it('excludes a daily row tested less than a day ago, and includes one tested over a day ago', function () {
        $site = Site::factory()->create(['care_plan_enabled' => true]);

        $fresh = ContactFormTest::factory()->create([
            'site_id' => $site->id,
            'slot' => 1,
            'frequency' => ContactFormTest::FREQUENCY_DAILY,
            'last_test_at' => now()->subHours(2),
        ]);
        $stale = ContactFormTest::factory()->create([
            'site_id' => $site->id,
            'slot' => 2,
            'frequency' => ContactFormTest::FREQUENCY_DAILY,
            'last_test_at' => now()->subDays(2),
        ]);

        $this->mock(ContactFormTester::class, function ($mock) use ($stale) {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($stale))
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'ok']);
        });

        $this->artisan('clockwork:test-contact-forms')->assertSuccessful();
    });

    it('excludes a weekly row tested less than 7 days ago, and includes one tested over 7 days ago', function () {
        $site = Site::factory()->create(['care_plan_enabled' => true]);

        $fresh = ContactFormTest::factory()->create([
            'site_id' => $site->id,
            'slot' => 1,
            'frequency' => ContactFormTest::FREQUENCY_WEEKLY,
            'last_test_at' => now()->subDays(3),
        ]);
        $stale = ContactFormTest::factory()->create([
            'site_id' => $site->id,
            'slot' => 2,
            'frequency' => ContactFormTest::FREQUENCY_WEEKLY,
            'last_test_at' => now()->subDays(9),
        ]);

        $this->mock(ContactFormTester::class, function ($mock) use ($stale) {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($stale))
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'ok']);
        });

        $this->artisan('clockwork:test-contact-forms')->assertSuccessful();
    });

    it('--force bypasses the due filter for an otherwise-fresh row', function () {
        $site = Site::factory()->create(['care_plan_enabled' => true]);
        $fresh = ContactFormTest::factory()->create([
            'site_id' => $site->id,
            'frequency' => ContactFormTest::FREQUENCY_DAILY,
            'last_test_at' => now()->subMinutes(5),
        ]);

        $this->mock(ContactFormTester::class, function ($mock) use ($fresh) {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($fresh))
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'ok']);
        });

        $this->artisan('clockwork:test-contact-forms', ['--force' => true])->assertSuccessful();
    });
});

describe('clockwork:test-contact-forms — result tallying + exit code', function () {
    it('tallies pass/fail/skip and exits FAILURE when any test failed', function () {
        $site = Site::factory()->create(['care_plan_enabled' => true]);

        $passing = ContactFormTest::factory()->create(['site_id' => $site->id, 'slot' => 1, 'last_test_at' => null]);
        $failing = ContactFormTest::factory()->create(['site_id' => $site->id, 'slot' => 2, 'last_test_at' => null]);
        $skipping = ContactFormTest::factory()->create(['site_id' => $site->id, 'slot' => 3, 'last_test_at' => null]);

        $this->mock(ContactFormTester::class, function ($mock) use ($passing, $failing, $skipping) {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($passing))
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'ok']);
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($failing))
                ->andReturn(['result' => ContactFormTester::RESULT_FAILED, 'message' => 'timed out']);
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($skipping))
                ->andReturn(['result' => ContactFormTester::RESULT_SKIPPED, 'message' => 'no notification email configured']);
        });

        $this->artisan('clockwork:test-contact-forms')
            ->expectsOutputToContain('passed=1, failed=1, skipped=1, transitions=0')
            ->assertFailed();
    });

    it('counts a "transitioned" flag from the tester result without affecting pass/fail counts', function () {
        $site = Site::factory()->create(['care_plan_enabled' => true]);
        $test = ContactFormTest::factory()->create(['site_id' => $site->id, 'last_test_at' => null]);

        $this->mock(ContactFormTester::class, function ($mock) use ($test) {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (ContactFormTest $t) => $t->is($test))
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'recovered', 'transitioned' => true]);
        });

        $this->artisan('clockwork:test-contact-forms')
            ->expectsOutputToContain('passed=1, failed=0, skipped=0, transitions=1')
            ->assertSuccessful();
    });
});
