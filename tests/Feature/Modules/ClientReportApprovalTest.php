<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\ClientReports\Http\Controllers\SchedulesController;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Modules\ClientReports\Support\ReportState;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
| Every report waits for approval (on by default): the monthly run compiles
| and checks reports but emails nothing, and operators send them from the
| Reports list, one by one, ticked, or "Approve & send all ready".
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-12-01 06:00:00'));
});

/** A care-plan site whose November report has nothing to hold for review. */
function approvalSite(string $domain): Site
{
    $site = Site::factory()->carePlan()->create([
        'domain' => $domain, 'hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'backup_relay_enabled' => false,
        'uptime_last_checked_at' => now(), 'created_at' => now()->subYear(),
    ]);
    cleanSecurityScans($site);
    SiteSecurityScan::factory()->coreChecksums()->create(['site_id' => $site->id, 'scanned_at' => Carbon::parse('2026-11-10 02:30')]);

    return $site;
}

/** A November report in the state asked for. */
function approvalReport(string $domain, string $state, array $attributes = []): ClientReport
{
    $site = approvalSite($domain);
    $schedule = ClientReportSchedule::create([
        'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY, 'delivery_mode' => ClientReportSchedule::MODE_AUTO,
        'recipients' => ["client@{$domain}"], 'is_enabled' => true, 'next_run_at' => Carbon::parse('2027-01-01'),
    ]);
    $meta = ['schema_version' => $state === ReportState::OUTDATED ? 1 : ClientReportCompiler::SCHEMA_VERSION];
    if ($state === ReportState::NEEDS_REVIEW) {
        $meta['review_reasons'] = ['Backups could not be checked.'];
    }

    return ClientReport::create(array_merge([
        'site_id' => $site->id, 'schedule_id' => $schedule->id, 'title' => 'Maintenance Report (Nov 2026)',
        'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'sections_data' => ['meta' => $meta],
        'client_email' => "client@{$domain}", 'status' => ClientReport::STATUS_GENERATED,
    ], $attributes));
}

it('is on unless switched off', function () {
    expect(ScheduledReportRunner::approvalRequired())->toBeTrue();

    clientReportsSendThemselves();

    expect(ScheduledReportRunner::approvalRequired())->toBeFalse();
});

it('compiles and checks a clean report on the monthly run, emails nothing, and leaves it ready to send', function () {
    Mail::fake();
    $site = approvalSite('approval.test');
    $schedule = ClientReportSchedule::create([
        'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY, 'delivery_mode' => ClientReportSchedule::MODE_AUTO,
        'recipients' => ['client@approval.test'], 'is_enabled' => true,
        'last_sent_at' => Carbon::parse('2026-11-01 07:00'), // has sent before: would have gone out by itself
        'next_run_at' => Carbon::parse('2026-12-01 00:00'),
    ]);

    $this->artisan('clockwork:send-client-reports')
        ->expectsOutputToContain('approval.test: compiled, waiting for approval (nothing sent)')
        ->assertSuccessful();

    $report = ClientReport::query()->where('schedule_id', $schedule->id)->sole();
    expect(ReportState::of($report))->toBe(ReportState::READY)
        ->and($report->sent_to)->toBeEmpty()
        ->and($schedule->refresh()->next_run_at->toDateString())->toBe('2027-01-01');
    Mail::assertNothingSent();
});

it('still holds a flagged report as needing review', function () {
    Mail::fake();
    $site = Site::factory()->carePlan()->create(['domain' => 'flagged-approval.test', 'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP, 'uptime_last_checked_at' => now(), 'created_at' => now()->subYear()]);
    cleanSecurityScans($site);
    $schedule = ClientReportSchedule::create([
        'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY, 'delivery_mode' => ClientReportSchedule::MODE_AUTO,
        'recipients' => ['client@flagged-approval.test'], 'is_enabled' => true, 'last_sent_at' => Carbon::parse('2026-11-01 07:00'),
        'next_run_at' => Carbon::parse('2026-12-01 00:00'),
    ]);

    $run = app(ScheduledReportRunner::class)->run($schedule);

    expect($run->held())->toBeTrue()
        ->and(ReportState::of($run->report))->toBe(ReportState::NEEDS_REVIEW);
    Mail::assertNothingSent();
});

it('approves and sends every ready report of the period, leaving the rest alone', function () {
    Mail::fake();
    $this->mockIssueCounterZero();
    $ready = [approvalReport('ready-one.test', ReportState::READY), approvalReport('ready-two.test', ReportState::READY)];
    $held = approvalReport('held.test', ReportState::NEEDS_REVIEW);
    $outdated = approvalReport('outdated.test', ReportState::OUTDATED);
    $sent = approvalReport('sent.test', ReportState::SENT, ['status' => ClientReport::STATUS_SENT, 'sent_to' => ['client@sent.test'], 'sent_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->get(route('client-reports.reports', ['period' => '2026-11']))
        ->assertOk()
        ->assertSee('Approve &amp; send all ready (2)', false)
        ->assertSee('formaction="'.route('client-reports.send-many').'"', false);

    $this->post(route('client-reports.send-many'), ['scope' => 'ready', 'period' => '2026-11'])
        ->assertRedirect(route('client-reports.reports', ['period' => '2026-11']))
        ->assertSessionHas('status', 'Sent 2 reports.');

    Mail::assertSent(ClientReportMail::class, 2);
    foreach ($ready as $report) {
        expect($report->refresh()->status)->toBe(ClientReport::STATUS_SENT)
            ->and($report->sent_to)->toBe(["client@{$report->site->domain}"])
            ->and($report->schedule->last_sent_at)->not->toBeNull();
    }
    expect($held->refresh()->status)->toBe(ClientReport::STATUS_GENERATED)
        ->and($outdated->refresh()->status)->toBe(ClientReport::STATUS_GENERATED)
        ->and($sent->refresh()->sent_to)->toBe(['client@sent.test']);
});

it('sends the ticked reports, held ones included, and skips what cannot be sent', function () {
    Mail::fake();
    $held = approvalReport('ticked-held.test', ReportState::NEEDS_REVIEW);
    $outdated = approvalReport('ticked-outdated.test', ReportState::OUTDATED);
    $sent = approvalReport('ticked-sent.test', ReportState::SENT, ['status' => ClientReport::STATUS_SENT, 'sent_to' => ['client@ticked-sent.test'], 'sent_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->post(route('client-reports.send-many'), ['scope' => 'selected', 'period' => '2026-11', 'ids' => [$held->id, $outdated->id, $sent->id]])
        ->assertSessionHas('status', fn (string $message) => str_starts_with($message, 'Sent 1 report. Skipped 2 that can\'t be sent as they are'));

    Mail::assertSent(ClientReportMail::class, 1);
    expect($held->refresh()->status)->toBe(ClientReport::STATUS_SENT)
        ->and($outdated->refresh()->sent_to)->toBeEmpty();
});

it('says so when nothing is ready or nothing was ticked', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('client-reports.send-many'), ['scope' => 'ready', 'period' => '2026-11'])
        ->assertSessionHas('status', 'No reports are ready to send in this period.');
    $this->actingAs($user)->post(route('client-reports.send-many'), ['scope' => 'selected', 'period' => '2026-11'])
        ->assertSessionHas('status_error', 'Tick the reports to send first.');
});

it('needs a signed-in operator to send in bulk', function () {
    $report = approvalReport('guest.test', ReportState::READY);

    $this->post(route('client-reports.send-many'), ['scope' => 'ready'])->assertRedirect(route('login'));

    expect($report->refresh()->status)->toBe(ClientReport::STATUS_GENERATED);
});

it('shows the approval switch on the Scheduling tab and logs turning it off and on', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('client-reports.schedules.index'))
        ->assertOk()
        ->assertSee('Approval on')
        ->assertSee('Let clean reports send themselves');

    $this->actingAs($user)->post(route('client-reports.schedules.approval'), ['require_approval' => '0'])
        ->assertSessionHas('status', 'Approval is off: Automatic schedules now email their clean reports by themselves.');
    expect(ScheduledReportRunner::approvalRequired())->toBeFalse()
        ->and(app(Settings::class)->get(ScheduledReportRunner::APPROVAL_SETTING))->toBeFalse();

    $this->actingAs($user)->post(route('client-reports.schedules.approval'), ['require_approval' => '1']);
    expect(ScheduledReportRunner::approvalRequired())->toBeTrue()
        ->and(ActionLog::query()->where('action_type', SchedulesController::ACTION_APPROVAL_CHANGED)->count())->toBe(2);
});
