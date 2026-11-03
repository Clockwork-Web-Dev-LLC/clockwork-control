<?php

use App\Models\Site;
use Modules\ClientReports\Console\Commands\ImportReportRecipients;
use Modules\ClientReports\Models\ClientReportSchedule;

function recipientsCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'recipients').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

describe('clockwork:import-report-recipients', function () {
    it('normalizes domains from URLs', function () {
        expect(ImportReportRecipients::normalizeDomain(' https://www.Example.com/about/ '))->toBe('example.com')
            ->and(ImportReportRecipients::normalizeDomain('example.com:443'))->toBe('example.com')
            ->and(ImportReportRecipients::normalizeDomain('sub.example.org.'))->toBe('sub.example.org');
    });

    it('changes nothing without --apply', function () {
        $site = Site::factory()->carePlan()->create(['domain' => 'dry.test']);
        $csv = recipientsCsv("Website,Report email\nhttps://www.dry.test,owner@dry.test\n");

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv])
            ->expectsOutputToContain('Dry run: 1 created')
            ->assertSuccessful();

        expect(ClientReportSchedule::where('site_id', $site->id)->exists())->toBeFalse();
    });

    it('creates a monthly auto schedule for a matched site', function () {
        $site = Site::factory()->carePlan()->create(['domain' => 'new.test', 'companion_installed' => false]);
        $csv = recipientsCsv("Website,Report email\nwww.new.test,\"Owner@New.test, office@new.test\"\nnew.test,owner@new.test\n");

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv, '--apply' => true])
            ->assertSuccessful();

        $schedule = ClientReportSchedule::where('site_id', $site->id)->firstOrFail();
        expect($schedule->recipients)->toBe(['owner@new.test', 'office@new.test'])
            ->and($schedule->frequency)->toBe(ClientReportSchedule::FREQUENCY_MONTHLY)
            ->and($schedule->delivery_mode)->toBe(ClientReportSchedule::MODE_AUTO)
            ->and($schedule->is_enabled)->toBeTrue()
            ->and($schedule->next_run_at)->not->toBeNull();
    });

    it('adds to existing recipients by default and replaces with --replace', function () {
        $site = Site::factory()->carePlan()->create(['domain' => 'existing.test', 'companion_installed' => false]);
        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['old@existing.test'],
            'is_enabled' => true,
        ]);
        $csv = recipientsCsv("domain,email\nexisting.test,new@existing.test\n");

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv, '--apply' => true])->assertSuccessful();
        expect($schedule->fresh()->recipients)->toBe(['old@existing.test', 'new@existing.test']);

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv, '--apply' => true, '--replace' => true])->assertSuccessful();
        expect($schedule->fresh()->recipients)->toBe(['new@existing.test']);
    });

    it('reports unknown sites and rows without a valid email without failing', function () {
        Site::factory()->carePlan()->create(['domain' => 'known.test']);
        $csv = recipientsCsv("Website,Email\nunknown.test,a@unknown.test\nknown.test,not-an-email\n");

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv, '--apply' => true])
            ->expectsOutputToContain('0 created, 0 updated, 0 unchanged, 1 not found in Control, 1 without a valid email')
            ->assertSuccessful();

        expect(ClientReportSchedule::count())->toBe(0);
    });

    it('creates a schedule for a site without a care plan switched off, and says so in the dry run', function () {
        $site = Site::factory()->create(['domain' => 'no-plan.example.com', 'care_plan_enabled' => false, 'companion_installed' => false]);
        $csv = recipientsCsv("Website,Report email\nno-plan.example.com,owner@example.com\n");

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv])
            ->expectsTable(['Site', 'Action', 'Recipients'], [['no-plan.example.com', 'Create schedule (no care plan: created disabled)', 'owner@example.com']])
            ->expectsOutputToContain('Dry run: 1 created (1 disabled: no care plan), 0 updated')
            ->assertSuccessful();
        expect(ClientReportSchedule::count())->toBe(0);

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv, '--apply' => true])->assertSuccessful();

        $schedule = ClientReportSchedule::where('site_id', $site->id)->sole();
        expect($schedule->is_enabled)->toBeFalse()
            ->and($schedule->recipients)->toBe(['owner@example.com']);
    });

    it('creates it enabled with --include-no-care-plan, and leaves care-plan sites enabled either way', function () {
        $noPlan = Site::factory()->create(['domain' => 'no-plan.example.com', 'care_plan_enabled' => false, 'companion_installed' => false]);
        $carePlan = Site::factory()->carePlan()->create(['domain' => 'care-plan.example.com', 'companion_installed' => false]);
        $csv = recipientsCsv("Website,Report email\nno-plan.example.com,owner@example.com\ncare-plan.example.com,office@example.com\n");

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv, '--apply' => true, '--include-no-care-plan' => true])
            ->expectsOutputToContain('Done: 2 created, 0 updated')
            ->assertSuccessful();

        expect(ClientReportSchedule::where('site_id', $noPlan->id)->sole()->is_enabled)->toBeTrue()
            ->and(ClientReportSchedule::where('site_id', $carePlan->id)->sole()->is_enabled)->toBeTrue();
    });

    it('fails when the CSV has no site or email column', function () {
        $csv = recipientsCsv("name,phone\nAcme,555\n");

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv])->assertFailed();
    });

    it('prioritizes URL over Website when both are present and respects --site-column', function () {
        $site = Site::factory()->carePlan()->create(['domain' => 'matched.test', 'companion_installed' => false]);
        $csv = recipientsCsv("Website,URL,Report email\n\"Site Title\",matched.test,user@matched.test\n");

        $this->artisan('clockwork:import-report-recipients', ['path' => $csv, '--apply' => true])
            ->expectsOutputToContain('1 created')
            ->assertSuccessful();

        expect(ClientReportSchedule::where('site_id', $site->id)->exists())->toBeTrue();

        // With explicit invalid --site-column
        $this->artisan('clockwork:import-report-recipients', ['path' => $csv, '--site-column' => 'nonexistent'])
            ->assertFailed();
    });
});
