<?php

use App\Models\Site;
use App\Models\SiteTrafficDaily;
use App\Models\SiteWorkLog;
use App\Models\User;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| ClientReportsController — HTTP-level coverage
|--------------------------------------------------------------------------
|
| Regression coverage for a real production bug: show() and publicShow()
| rendered view('client-reports::report'), but the view file actually lives
| at resources/views/reports/show.blade.php — which the 'client-reports'
| view namespace resolves as 'client-reports::reports.show'. Every
| "Generate & Preview Report" click 500'd on InvalidArgumentException
| ("View [report] not found") immediately after the report row was already
| successfully created, since generate() redirects straight into show().
*/

it('requires authentication for the operator routes', function () {
    $report = ClientReport::create([
        'site_id' => Site::factory()->create()->id,
        'title' => 'Test Report',
        'period_start' => now()->subMonth(),
        'period_end' => now(),
        'sections_data' => [],
        'status' => 'generated',
    ]);

    $this->get(route('client-reports.index'))->assertRedirect(route('login'));
    $this->get(route('client-reports.reports'))->assertRedirect(route('login'));
    $this->get(route('client-reports.show', $report))->assertRedirect(route('login'));
    $this->post(route('client-reports.generate'))->assertRedirect(route('login'));
    $this->post(route('client-reports.send', $report))->assertRedirect(route('login'));
    $this->get(route('client-reports.email', $report))->assertRedirect(route('login'));
});

describe('index', function () {
    it('renders the reports dashboard', function () {
        $this->mockIssueCounterZero();
        $site = Site::factory()->create(['domain' => 'reports-index.test']);
        ClientReport::create([
            'site_id' => $site->id,
            'title' => 'Existing Report',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'sections_data' => [],
            'status' => 'generated',
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('client-reports.reports'));

        $response->assertOk()->assertSee('Existing Report');
    });
});

describe('generate + show', function () {
    it('compiles a report and previews it without error — the exact generate-then-preview flow that used to 500', function () {
        $site = Site::factory()->create(['domain' => 'clockworkwd.test', 'care_plan_enabled' => true]);

        $generateResponse = $this->actingAs(User::factory()->create())->post(route('client-reports.generate'), [
            'site_id' => $site->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'client_name' => 'Test Client',
            'client_email' => 'client@example.test',
        ]);

        $report = ClientReport::query()->where('site_id', $site->id)->firstOrFail();

        $generateResponse->assertRedirect(route('client-reports.show', $report))
            ->assertSessionHas('status', 'Report generated successfully.');

        $showResponse = $this->actingAs(User::factory()->create())->get(route('client-reports.show', $report));

        $showResponse->assertOk()
            ->assertSee('clockworkwd.test')
            ->assertSee($report->title);
    });

    it('renders the custom Reports brand palette colors set via Settings > White Labeling', function () {
        app(CompanionBrandingManager::class)->saveReportsBranding([
            'primary_color' => '#123456',
            'accent_color' => '#abcdef',
        ]);

        $site = Site::factory()->create(['domain' => 'brand-colors.test', 'care_plan_enabled' => true]);

        $generateResponse = $this->actingAs(User::factory()->create())->post(route('client-reports.generate'), [
            'site_id' => $site->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'client_name' => 'Test Client',
            'client_email' => 'client@example.test',
        ]);

        $report = ClientReport::query()->where('site_id', $site->id)->firstOrFail();
        $generateResponse->assertRedirect(route('client-reports.show', $report));

        $showResponse = $this->actingAs(User::factory()->create())->get(route('client-reports.show', $report));

        $showResponse->assertOk()
            ->assertSee('#123456', false)
            ->assertSee('#abcdef', false);
    });

    it('shows the Traffic Analytics section when the period had recorded traffic', function () {
        $site = Site::factory()->create(['domain' => 'traffic-report.test']);
        $periodStart = now()->subMonthNoOverflow()->startOfMonth();
        // Every day of the period, each row written after its day was over:
        // a month with days missing is held back as partial.
        $days = $periodStart->daysInMonth;
        foreach (range(0, $days - 1) as $offset) {
            $day = $periodStart->copy()->addDays($offset);
            SiteTrafficDaily::factory()->create([
                'site_id' => $site->id,
                'date' => $day->toDateString(),
                'visits' => 41,
                'requests' => 1003,
                'bytes_sent' => $offset === 0 ? 10 * 1024 * 1024 : 0,
                'top_ips' => null,
                'created_at' => $day->copy()->addDay()->setTime(5, 0),
                'updated_at' => $day->copy()->addDay()->setTime(5, 0),
            ]);
        }

        $response = $this->actingAs(User::factory()->create())->post(route('client-reports.generate'), [
            'site_id' => $site->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
        ]);

        $report = ClientReport::query()->where('site_id', $site->id)->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->get(route('client-reports.show', $report))
            ->assertOk()
            ->assertSee('Traffic Analytics')
            ->assertSee(number_format(41 * $days))
            ->assertSee(number_format(1003 * $days))
            ->assertSee('10.0 MB')
            ->assertSee("Recorded on {$days} of {$days} days", false);
    });

    it('hides the Traffic Analytics section entirely when no traffic was recorded for the period', function () {
        $site = Site::factory()->create(['domain' => 'no-traffic-report.test']);

        $response = $this->actingAs(User::factory()->create())->post(route('client-reports.generate'), [
            'site_id' => $site->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        ]);

        $report = ClientReport::query()->where('site_id', $site->id)->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->get(route('client-reports.show', $report))
            ->assertOk()
            ->assertDontSee('Traffic Analytics');
    });
});

describe('email version', function () {
    it('shows the email exactly as the client gets it, with who it goes to and why it was held', function () {
        $this->mockIssueCounterZero(); // the email version renders inside the app layout
        $user = User::factory()->create();
        $site = Site::factory()->create(['domain' => 'email-version.test']);
        $data = app(ClientReportCompiler::class)->compile($site, now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth());
        $data['meta']['review_reasons'] = ['Backups could not be checked.'];
        $report = ClientReport::create([
            'site_id' => $site->id, 'title' => 'Email version', 'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(), 'sections_data' => $data,
            'client_email' => 'a@client.test, b@client.test', 'sent_to' => ['a@client.test'], 'status' => 'generated',
        ]);
        $subject = (new ClientReportMail($report))->envelope()->subject;

        $this->actingAs($user)->get(route('client-reports.email', $report))
            ->assertOk()
            ->assertSee($subject)
            ->assertSee('b@client.test')
            ->assertSee('Backups could not be checked.')
            ->assertSee('Website Care Report') // inside the rendered email
            ->assertSee('srcdoc=', false);

        $this->actingAs($user)->get(route('client-reports.show', $report))
            ->assertSee(route('client-reports.email', $report), false);
    });

    it('shows the From line the client will see: the sending address under the report brand name', function () {
        $this->mockIssueCounterZero();
        config(['mail.from.address' => 'reports@example.com', 'mail.from.name' => 'Internal Control App']);
        app(CompanionBrandingManager::class)->saveReportsBranding(['company_name' => 'Example Agency']);
        $user = User::factory()->create();
        $site = Site::factory()->create(['domain' => 'from-line.example.com']);
        $report = ClientReport::create([
            'site_id' => $site->id, 'title' => 'From line', 'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'sections_data' => app(ClientReportCompiler::class)->compile($site, now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()),
            'client_email' => 'a@example.com', 'status' => 'generated',
        ]);

        $this->actingAs($user)->get(route('client-reports.email', $report))
            ->assertOk()
            ->assertSee('Example Agency <reports@example.com>')
            ->assertDontSee('Internal Control App');
    });

    it('shows the plain-text part too, and says so when everyone already has the report', function () {
        $this->mockIssueCounterZero();
        $user = User::factory()->create();
        $site = Site::factory()->create(['domain' => 'text-part.test']);
        $report = ClientReport::create([
            'site_id' => $site->id, 'title' => 'Text part', 'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'sections_data' => app(ClientReportCompiler::class)->compile($site, now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()),
            'client_email' => 'a@client.test', 'sent_to' => ['a@client.test'], 'status' => 'generated',
        ]);

        $this->actingAs($user)->get(route('client-reports.email', $report))
            ->assertOk()
            ->assertSee('Plain-text version')
            ->assertSee('WEBSITE CARE REPORT')
            ->assertSee('Everyone on this report already has it')
            ->assertDontSee('No recipient saved');
    });
});

describe('publicShow', function () {
    it('renders the client-facing report via a valid public token without authentication', function () {
        $site = Site::factory()->create(['domain' => 'public-report.test']);
        $sectionsData = app(ClientReportCompiler::class)
            ->compile($site, now()->subMonth(), now());

        $report = ClientReport::create([
            'site_id' => $site->id,
            'title' => 'Public Preview Report',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'sections_data' => $sectionsData,
            'status' => 'generated',
        ]);

        $response = $this->get(route('client-reports.public', ['token' => $report->public_token]));

        $response->assertOk()->assertSee('Public Preview Report');
    });

    it('404s for an unknown public token', function () {
        $response = $this->get(route('client-reports.public', ['token' => 'not-a-real-token']));

        $response->assertNotFound();
    });
});

describe('send', function () {
    /** A report compiled under the current rules, so nothing stops it being sent. */
    function sendableReport(?string $clientEmail, array $attributes = []): ClientReport
    {
        return ClientReport::create(array_merge([
            'site_id' => Site::factory()->create()->id,
            'title' => 'Send Test Report',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'sections_data' => ['meta' => ['schema_version' => ClientReportCompiler::SCHEMA_VERSION]],
            'client_email' => $clientEmail,
            'status' => 'generated',
        ], $attributes));
    }

    it('emails every address in a scheduled report\'s comma-joined recipient list', function () {
        // Regression: send() ran the whole "a, b" string through
        // FILTER_VALIDATE_EMAIL, so any scheduled draft with more than one
        // recipient could never be sent from the UI.
        Mail::fake();
        $report = sendableReport('one@client.test, two@client.test');

        $this->actingAs(User::factory()->create())
            ->post(route('client-reports.send', $report))
            ->assertSessionHas('status', 'Report emailed to one@client.test, two@client.test.');

        Mail::assertSent(ClientReportMail::class, fn ($mail) => $mail->hasTo('one@client.test'));
        Mail::assertSent(ClientReportMail::class, fn ($mail) => $mail->hasTo('two@client.test'));

        $report->refresh();
        expect($report->status)->toBe('sent')
            ->and($report->sent_to)->toBe(['one@client.test', 'two@client.test']);
    });

    it('sends a report with no saved address to the one entered, and remembers it', function () {
        Mail::fake();
        $report = sendableReport(null);

        $this->actingAs(User::factory()->create())
            ->post(route('client-reports.send', $report), ['email' => 'entered@client.test'])
            ->assertSessionHas('status', 'Report emailed to entered@client.test.');

        Mail::assertSent(ClientReportMail::class, fn ($mail) => $mail->hasTo('entered@client.test'));
        expect($report->fresh()->client_email)->toBe('entered@client.test');
    });

    it('refuses to send when there is no address at all', function () {
        Mail::fake();
        $report = sendableReport(null);

        $this->actingAs(User::factory()->create())
            ->post(route('client-reports.send', $report))
            ->assertSessionHas('status_error');

        Mail::assertNothingSent();
        expect($report->fresh()->status)->toBe('generated');
    });

    it('refuses the whole send if any address is invalid', function () {
        Mail::fake();
        $report = sendableReport('good@client.test, not-an-email');

        $this->actingAs(User::factory()->create())
            ->post(route('client-reports.send', $report))
            ->assertSessionHas('status_error', "Invalid email address: 'not-an-email'.");

        Mail::assertNothingSent();
    });

    it('refuses to send an unsent report compiled under older report rules, and leaves the schedule as it was', function () {
        Mail::fake();
        $site = Site::factory()->create();
        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO, 'recipients' => ['client@example.com'],
            'is_enabled' => true, 'next_run_at' => Carbon::parse('2027-01-01 00:00:00'),
        ]);
        $report = sendableReport('client@example.com', [
            'site_id' => $site->id, 'schedule_id' => $schedule->id,
            'sections_data' => ['meta' => ['schema_version' => 3, 'review_reasons' => ['Backups could not be checked.']]],
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('client-reports.send', $report))
            ->assertSessionHas('status_error', 'This report was compiled before the latest report fixes. Press Recompile, check the new copy, then send that one.');

        Mail::assertNothingSent();
        expect($report->fresh()->status)->toBe('generated')
            ->and($schedule->fresh()->last_sent_at)->toBeNull();
    });

    it('tells an operator to generate a new report when an outdated one has no schedule to recompile it from', function () {
        Mail::fake();
        $report = sendableReport('client@example.com', ['sections_data' => []]);

        $this->actingAs(User::factory()->create())
            ->post(route('client-reports.send', $report))
            ->assertSessionHas('status_error', 'This report was compiled before the latest report fixes. Generate a new report for this period instead.');

        Mail::assertNothingSent();
    });

    it('still finishes delivering an outdated report that some recipients already have', function () {
        Mail::fake();
        $report = sendableReport('one@client.test, two@client.test', [
            'sections_data' => ['meta' => ['schema_version' => 3]],
            'sent_to' => ['one@client.test'],
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('client-reports.send', $report))
            ->assertSessionHas('status', 'Report emailed to two@client.test.');

        Mail::assertSent(ClientReportMail::class, fn ($mail) => $mail->hasTo('two@client.test'));
        Mail::assertNotSent(ClientReportMail::class, fn ($mail) => $mail->hasTo('one@client.test'));
    });

    it('marks an outdated unsent report on the list and in the preview, without a send button', function () {
        $this->mockIssueCounterZero();
        $report = sendableReport('outdated@client.test', ['sections_data' => ['meta' => ['schema_version' => 3]]]);

        $this->actingAs(User::factory()->create())
            ->get(route('client-reports.reports'))
            ->assertOk()
            ->assertSee('Outdated')
            ->assertSee('Compiled before the latest report fixes')
            ->assertDontSee('action="'.route('client-reports.send', $report).'"', false)
            ->assertDontSee('Email report to outdated@client.test?');

        $site = Site::factory()->create();
        $data = app(ClientReportCompiler::class)->compile($site, now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth());
        $data['meta']['schema_version'] = 3;
        $compiled = sendableReport('outdated@client.test', ['site_id' => $site->id, 'sections_data' => $data]);

        $this->get(route('client-reports.show', $compiled))
            ->assertOk()
            ->assertSee('Outdated: compiled before the latest report fixes');
        // The public view never shows it.
        $this->get(route('client-reports.public', $compiled->public_token))
            ->assertOk()
            ->assertDontSee('Outdated');
    });

    it('asks for an address on the reports list when a report has none', function () {
        $this->mockIssueCounterZero();
        sendableReport(null);
        sendableReport('saved@client.test');

        $this->actingAs(User::factory()->create())
            ->get(route('client-reports.reports'))
            ->assertOk()
            ->assertSee('Send this report to which email address(es)?')
            ->assertSee('Email report to saved@client.test?');
    });
});

describe('recompile', function () {
    /**
     * A care-plan site whose report has nothing to hold on its own: no backup
     * source, a running uptime probe, a host with no core-file check, and
     * enrolled long before October.
     */
    function recompileSite(): Site
    {
        return Site::factory()->carePlan()->create([
            'domain' => 'site-'.Str::lower(Str::random(6)).'.example.com', 'hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM,
            'backup_relay_enabled' => false, 'uptime_last_checked_at' => now(), 'created_at' => now()->subYear(),
        ]);
    }

    function recompileSchedule(Site $site, array $attributes = []): ClientReportSchedule
    {
        return ClientReportSchedule::create(array_merge([
            'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO, 'recipients' => ['client@example.com'],
            'is_enabled' => true, 'next_run_at' => Carbon::parse('2027-01-01 00:00:00'),
        ], $attributes));
    }

    /** An October report from the schedule, held for review, as the Nov 1 run left it. */
    function heldOctoberReport(ClientReportSchedule $schedule, array $attributes = []): ClientReport
    {
        $start = Carbon::parse('2026-10-01');
        $end = Carbon::parse('2026-10-31');
        $data = app(ClientReportCompiler::class)->compile($schedule->site, $start, $end);
        $data['meta']['review_reasons'] = [ScheduledReportRunner::FIRST_REPORT_REASON];

        return ClientReport::create(array_merge([
            'site_id' => $schedule->site_id, 'schedule_id' => $schedule->id, 'title' => 'Maintenance Report (Oct 2026)',
            'period_start' => $start, 'period_end' => $end, 'sections_data' => $data,
            'client_email' => 'client@example.com', 'status' => ClientReport::STATUS_GENERATED,
        ], $attributes));
    }

    beforeEach(function () {
        // A month later than the reports: "last month" is November, not October.
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00'));
    });

    it('requires authentication', function () {
        $report = heldOctoberReport(recompileSchedule(recompileSite()));

        $this->post(route('client-reports.recompile', $report))->assertRedirect(route('login'));
        expect(ClientReport::count())->toBe(1);
    });

    it('recompiles a held report for its own period, supersedes the old copy and holds the new one, without sending', function () {
        Mail::fake();
        $schedule = recompileSchedule(recompileSite());
        $old = heldOctoberReport($schedule);
        // Logged after the draft was compiled: the reason drafts need refreshing.
        SiteWorkLog::create(['site_id' => $schedule->site_id, 'worked_on' => '2026-10-20', 'hours' => 1.5, 'description' => 'Example plugin cleanup']);
        $nextRun = $schedule->next_run_at->toIso8601String();

        $response = $this->actingAs(User::factory()->create())->post(route('client-reports.recompile', $old));

        $fresh = ClientReport::query()->where('status', ClientReport::STATUS_GENERATED)->sole();
        $response->assertRedirect(route('client-reports.show', $fresh))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Recompiled from current data for Oct 1 – Oct 31, 2026. Nothing was sent.')
                && str_contains($m, ScheduledReportRunner::FIRST_REPORT_REASON));

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        expect($old->fresh()->status)->toBe(ClientReport::STATUS_SUPERSEDED)
            ->and($fresh->id)->not->toBe($old->id)
            ->and($fresh->schedule_id)->toBe($schedule->id)
            ->and($fresh->period_start->toDateString())->toBe('2026-10-01')
            ->and($fresh->period_end->toDateString())->toBe('2026-10-31')
            ->and($fresh->sections_data['meta']['period_start'])->toBe('2026-10-01')
            ->and($fresh->sections_data['work_log']['total_hours'])->toEqual(1.5)
            ->and($fresh->sections_data['meta']['review_reasons'])->toBe([ScheduledReportRunner::FIRST_REPORT_REASON])
            ->and($fresh->sent_to)->toBeNull()
            // Not a scheduled cycle: the schedule is left exactly as it was.
            ->and($schedule->fresh()->last_sent_at)->toBeNull()
            ->and($schedule->fresh()->next_run_at->toIso8601String())->toBe($nextRun);
    });

    it('never sends, even when the new copy is clean and the schedule sends clean reports by itself', function () {
        Mail::fake();
        $schedule = recompileSchedule(recompileSite(), ['last_sent_at' => Carbon::parse('2026-10-01 06:00:00')]);
        $old = heldOctoberReport($schedule);

        $this->actingAs(User::factory()->create())->post(route('client-reports.recompile', $old))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Nothing needs review'));

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $fresh = ClientReport::query()->where('status', ClientReport::STATUS_GENERATED)->sole();
        expect($fresh->sections_data['meta'])->not->toHaveKey('review_reasons')
            ->and($fresh->sent_to)->toBeNull()
            ->and($schedule->fresh()->last_sent_at->toDateString())->toBe('2026-10-01');
    });

    it('does not add the first-report reason for a draft-mode schedule, like a scheduled run', function () {
        Mail::fake();
        $schedule = recompileSchedule(recompileSite(), ['delivery_mode' => ClientReportSchedule::MODE_DRAFT]);

        $run = app(ScheduledReportRunner::class)->recompile(heldOctoberReport($schedule));

        Mail::assertNothingSent();
        expect($run->isDraft)->toBeTrue()
            ->and($run->heldReasons)->toBe([])
            ->and($run->report->sections_data['meta'])->not->toHaveKey('review_reasons');
    });

    it('keeps the report\'s custom note', function () {
        $schedule = recompileSchedule(recompileSite());
        $old = heldOctoberReport($schedule);
        $old->update(['sections_data' => array_replace_recursive($old->sections_data, ['meta' => ['custom_notes' => 'Example note for the client.']])]);

        $run = app(ScheduledReportRunner::class)->recompile($old->fresh());

        expect($run->report->sections_data['meta']['custom_notes'])->toBe('Example note for the client.');
    });

    it('refuses reports that reached someone, were replaced, or have no schedule', function (Closure $makeReport, string $error) {
        Mail::fake();
        $report = $makeReport();
        $before = ClientReport::count();

        $this->actingAs(User::factory()->create())->post(route('client-reports.recompile', $report))
            ->assertSessionHas('status_error', $error);

        Mail::assertNothingSent();
        expect(ClientReport::count())->toBe($before)
            ->and($report->fresh()->status)->toBe($report->status);
        expect(fn () => app(ScheduledReportRunner::class)->recompile($report))->toThrow(InvalidArgumentException::class);
    })->with([
        'sent' => [fn () => heldOctoberReport(recompileSchedule(recompileSite()), ['status' => ClientReport::STATUS_SENT, 'sent_to' => ['client@example.com']]), "This report has already been sent to someone, so it can't be recompiled."],
        'partly sent' => [fn () => heldOctoberReport(recompileSchedule(recompileSite()), ['sent_to' => ['client@example.com']]), "This report has already been sent to someone, so it can't be recompiled."],
        'superseded' => [fn () => heldOctoberReport(recompileSchedule(recompileSite()), ['status' => ClientReport::STATUS_SUPERSEDED]), 'A newer report for this period replaced this one. Recompile that one instead.'],
        'generated by hand' => [fn () => heldOctoberReport(recompileSchedule(recompileSite()), ['schedule_id' => null]), 'Only scheduled reports can be recompiled. Generate a new report for this period instead.'],
    ]);

    it('leaves the old copy in place when compiling fails', function () {
        Mail::fake();
        $old = heldOctoberReport(recompileSchedule(recompileSite()));
        $this->mock(ClientReportCompiler::class)->shouldReceive('compile')->andThrow(new RuntimeException('Example compile failure'));

        $this->actingAs(User::factory()->create())->post(route('client-reports.recompile', $old))
            ->assertSessionHas('status_error', fn ($m) => str_contains($m, 'Example compile failure'));

        Mail::assertNothingSent();
        expect($old->fresh()->status)->toBe(ClientReport::STATUS_GENERATED)
            ->and(ClientReport::count())->toBe(1);
    });

    it('offers Recompile on unsent scheduled reports in the history and the preview, never on the public view', function () {
        $this->mockIssueCounterZero();
        $user = User::factory()->create();
        $held = heldOctoberReport(recompileSchedule(recompileSite()));
        $sent = heldOctoberReport(recompileSchedule(recompileSite()), ['status' => ClientReport::STATUS_SENT, 'sent_to' => ['client@example.com']]);
        $manual = heldOctoberReport(recompileSchedule(recompileSite()), ['schedule_id' => null]);

        $index = $this->actingAs($user)->get(route('client-reports.reports'))->assertOk()->getContent();
        expect($index)->toContain('action="'.route('client-reports.recompile', $held).'"')
            ->not->toContain('action="'.route('client-reports.recompile', $sent).'"')
            ->not->toContain('action="'.route('client-reports.recompile', $manual).'"');

        $this->actingAs($user)->get(route('client-reports.show', $held))
            ->assertOk()->assertSee(route('client-reports.recompile', $held))->assertSee('Recompile');
        $this->actingAs($user)->get(route('client-reports.show', $sent))
            ->assertOk()->assertDontSee(route('client-reports.recompile', $sent));

        $this->post('/logout');
        $this->get(route('client-reports.public', $held->public_token))
            ->assertOk()->assertDontSee('/recompile', false)->assertDontSee('Recompile');
    });
});
