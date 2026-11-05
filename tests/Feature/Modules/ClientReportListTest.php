<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\ClientManagement\Models\Client;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ReportBrowser;
use Modules\ClientReports\Services\ScheduledReportRun;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Modules\ClientReports\Support\ReportListFilters;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| Reports list: filters, summary, bulk recompile, schedules page
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    $this->mockIssueCounterZero();
    $this->user = User::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

function listReport(string $domain, string $month, array $attributes = [], array $meta = [], ?Client $client = null, bool $scheduled = true): ClientReport
{
    $site = Site::where('domain', $domain)->first()
        ?? Site::factory()->create(['domain' => $domain, 'care_plan_enabled' => true, 'client_id' => $client?->id]);
    $schedule = $scheduled
        ? (ClientReportSchedule::where('site_id', $site->id)->first() ?? ClientReportSchedule::create([
            'site_id' => $site->id, 'frequency' => 'monthly', 'delivery_mode' => 'auto',
            'recipients' => ["owner@{$domain}"], 'is_enabled' => true, 'next_run_at' => Carbon::parse('2026-12-01'),
        ]))
        : null;
    $start = Carbon::parse($month.'-01');

    return ClientReport::create(array_merge([
        'site_id' => $site->id,
        'schedule_id' => $schedule?->id,
        'title' => "Report {$domain} {$month}",
        'period_start' => $start,
        'period_end' => $start->copy()->endOfMonth(),
        'sections_data' => ['meta' => array_merge(['schema_version' => ClientReportCompiler::SCHEMA_VERSION], $meta)],
        'client_email' => "owner@{$domain}",
        'status' => ClientReport::STATUS_GENERATED,
    ], $attributes));
}

describe('reports list', function () {
    it('shows the latest period by default, without replaced copies unless asked', function () {
        listReport('old.example.com', '2026-09');
        $live = listReport('live.example.com', '2026-10');
        $replaced = listReport('live.example.com', '2026-10', ['status' => ClientReport::STATUS_SUPERSEDED]);

        $this->actingAs($this->user);
        $html = $this->get(route('client-reports.reports'))->assertOk()->getContent();
        expect($html)->toContain('Report live.example.com 2026-10')
            ->not->toContain('Report old.example.com 2026-09')
            ->toContain(e(route('client-reports.show', [$live, 'period' => '2026-10'])))
            ->not->toContain(e(route('client-reports.show', [$replaced, 'period' => '2026-10'])));

        $this->get(route('client-reports.reports', ['period' => '2026-10', 'superseded' => 1]))
            ->assertSee(e(route('client-reports.show', [$replaced, 'period' => '2026-10', 'superseded' => '1'])), false);
        $this->get(route('client-reports.reports', ['period' => 'all']))->assertSee('Report old.example.com 2026-09')->assertSee('Report live.example.com 2026-10');
    });

    it('filters by status: needs review, ready (held only as a first report), outdated, sent and waiting', function () {
        listReport('held.example.com', '2026-10', meta: ['review_reasons' => ['No backups were found this month.']]);
        listReport('first.example.com', '2026-10', meta: ['review_reasons' => [ScheduledReportRunner::FIRST_REPORT_REASON]]);
        listReport('stale.example.com', '2026-10', meta: ['schema_version' => 1]);
        listReport('done.example.com', '2026-10', ['status' => ClientReport::STATUS_SENT, 'sent_to' => ['owner@done.example.com']]);

        $this->actingAs($this->user);
        $see = fn (string $status) => $this->get(route('client-reports.reports', ['status' => $status]))->assertOk();

        $see('needs_review')->assertSee('Report held.example.com 2026-10')->assertDontSee('Report first.example.com 2026-10')->assertDontSee('Report stale.example.com 2026-10')->assertDontSee('Report done.example.com 2026-10');
        $see('ready')->assertSee('Report first.example.com 2026-10')->assertDontSee('Report held.example.com 2026-10');
        $see('outdated')->assertSee('Report stale.example.com 2026-10')->assertDontSee('Report held.example.com 2026-10');
        $see('sent')->assertSee('Report done.example.com 2026-10')->assertDontSee('Report held.example.com 2026-10');
        $see('waiting')->assertSee('Report held.example.com 2026-10')->assertSee('Report first.example.com 2026-10')->assertSee('Report stale.example.com 2026-10')->assertDontSee('Report done.example.com 2026-10');
    });

    it('filters by hold-reason category and shows short reason chips on each row', function () {
        listReport('backups.example.com', '2026-10', meta: ['review_reasons' => ['No backups were found this month.']]);
        listReport('malware.example.com', '2026-10', meta: ['review_reasons' => ['The malware scan flagged something (scan #9).']]);

        $this->actingAs($this->user);
        $this->get(route('client-reports.reports', ['category' => 'backups']))
            ->assertSee('Report backups.example.com 2026-10')->assertDontSee('Report malware.example.com 2026-10')
            ->assertSee('cr-chip', false)->assertSee('No backups were found this month.');
        $this->get(route('client-reports.reports', ['category' => 'malware']))->assertSee('Report malware.example.com 2026-10')->assertDontSee('Report backups.example.com 2026-10');
    });

    it('searches by site, client and recipient', function () {
        $client = Client::create(['name' => 'Searchable Person', 'company_name' => 'Findme Holdings', 'email' => 'x@example.com']);
        listReport('company-site.example.com', '2026-10', client: $client);
        listReport('other.example.com', '2026-10', ['client_email' => 'unique-recipient@example.com']);

        $this->actingAs($this->user);
        $this->get(route('client-reports.reports', ['q' => 'findme']))->assertSee('Report company-site.example.com 2026-10')->assertDontSee('Report other.example.com 2026-10');
        $this->get(route('client-reports.reports', ['q' => 'unique-recipient']))->assertSee('Report other.example.com 2026-10')->assertDontSee('Report company-site.example.com 2026-10');
        $this->get(route('client-reports.reports', ['q' => 'other.example']))->assertSee('Report other.example.com 2026-10');
    });

    it('summarises the period with counts that link to each status, and keeps filters across pages', function () {
        foreach (range(1, ReportBrowser::PER_PAGE + 2) as $i) {
            listReport(sprintf('site%02d.example.com', $i), '2026-10', meta: ['review_reasons' => ['No backups were found this month.']]);
        }
        listReport('clean.example.com', '2026-10');

        $this->actingAs($this->user);
        $html = $this->get(route('client-reports.reports', ['status' => 'needs_review']))->assertOk()->getContent();

        expect($html)
            ->toContain(e(route('client-reports.reports', ['period' => '2026-10', 'status' => 'ready'])))
            ->toContain(e(route('client-reports.reports', ['period' => '2026-10', 'status' => 'needs_review', 'page' => 2])));

        preg_match('/href="[^"]*status=needs_review"\s+class="cr-filter-link is-active">(.*?)<\/a>/s', $html, $tile);
        preg_match('/href="[^"]*status=ready"\s+class="cr-filter-link ">(.*?)<\/a>/s', $html, $readyTile);
        expect($tile[1] ?? '')->toContain('>27<')
            ->and($readyTile[1] ?? '')->toContain('>1<');

        $this->get(route('client-reports.reports', ['status' => 'needs_review', 'page' => 2]))
            ->assertSee(sprintf('Report site%02d.example.com 2026-10', ReportBrowser::PER_PAGE + 2))
            ->assertDontSee('Report clean.example.com 2026-10');
    });

    it('lists reports of archived sites without breaking', function () {
        $report = listReport('archived.example.com', '2026-10');
        $report->site->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->user)->get(route('client-reports.reports'))->assertOk()->assertSee('archived.example.com');
    });
});

describe('bulk recompile', function () {
    it('recompiles every outdated unsent scheduled report of the period, and nothing else, without sending', function () {
        Mail::fake();
        $outdated = listReport('a.example.com', '2026-10', meta: ['schema_version' => 1]);
        $outdatedToo = listReport('b.example.com', '2026-10', meta: ['schema_version' => 1]);
        listReport('c.example.com', '2026-10', meta: ['review_reasons' => ['No backups were found this month.']]); // current, held
        listReport('d.example.com', '2026-09', meta: ['schema_version' => 1]); // other period
        listReport('e.example.com', '2026-10', meta: ['schema_version' => 1], scheduled: false); // one-off: can't recompile

        $recompiled = [];
        $this->mock(ScheduledReportRunner::class, function ($mock) use (&$recompiled) {
            $mock->shouldReceive('recompile')->andReturnUsing(function (ClientReport $report) use (&$recompiled) {
                $recompiled[] = $report->id;

                return new ScheduledReportRun($report, isDraft: true, resumed: false, heldReasons: ['No backups were found this month.']);
            });
            $mock->shouldNotReceive('run');
        });

        $this->actingAs($this->user)
            ->post(route('client-reports.recompile-many'), ['scope' => 'outdated', 'period' => '2026-10', 'status' => 'outdated'])
            ->assertRedirect(route('client-reports.reports', ['period' => '2026-10', 'status' => 'outdated']))
            ->assertSessionHas('status', 'Recompiled 2 report(s) from current data, 2 of them held for review. Nothing was sent.');

        expect($recompiled)->toEqualCanonicalizing([$outdated->id, $outdatedToo->id]);
        Mail::assertNothingSent();
    });

    it('recompiles the ticked reports, skipping any that can no longer be recompiled', function () {
        Mail::fake();
        $ticked = listReport('a.example.com', '2026-10');
        $sent = listReport('b.example.com', '2026-10', ['status' => ClientReport::STATUS_SENT, 'sent_to' => ['owner@b.example.com']]);

        $this->mock(ScheduledReportRunner::class, fn ($mock) => $mock->shouldReceive('recompile')->once()
            ->andReturnUsing(fn (ClientReport $r) => new ScheduledReportRun($r, isDraft: true, resumed: false)));

        $this->actingAs($this->user)
            ->post(route('client-reports.recompile-many'), ['scope' => 'selected', 'ids' => [$ticked->id, $sent->id, 'nonsense'], 'period' => '2026-10'])
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Recompiled 1 report(s)') && str_contains($m, 'Skipped 1') && str_contains($m, 'Nothing was sent.'));

        Mail::assertNothingSent();
    });

    it('reports failures by site and keeps going', function () {
        listReport('fails.example.com', '2026-10', meta: ['schema_version' => 1]);
        listReport('works.example.com', '2026-10', meta: ['schema_version' => 1]);

        $this->mock(ScheduledReportRunner::class, fn ($mock) => $mock->shouldReceive('recompile')->twice()
            ->andReturnUsing(fn (ClientReport $r) => $r->site->domain === 'fails.example.com'
                ? throw new RuntimeException('Example failure')
                : new ScheduledReportRun($r, isDraft: true, resumed: false)));

        $this->actingAs($this->user)
            ->post(route('client-reports.recompile-many'), ['scope' => 'outdated', 'period' => '2026-10'])
            ->assertSessionHas('status_error', fn (string $m) => str_contains($m, 'Recompiled 1 report(s)') && str_contains($m, 'Failed: fails.example.com'));
    });

    it('asks for a selection, and needs a login', function () {
        $this->actingAs($this->user)
            ->post(route('client-reports.recompile-many'), ['scope' => 'selected'])
            ->assertSessionHas('status_error', 'Tick the reports to recompile first.');

        auth()->logout();
        $this->post(route('client-reports.recompile-many'), ['scope' => 'outdated'])->assertRedirect(route('login'));
    });
});

describe('module tabs', function () {
    it('show how many of the latest period\'s reports are waiting and how many care-plan sites have no schedule', function () {
        listReport('held.example.com', '2026-10', meta: ['review_reasons' => ['No backups were found this month.']]);
        listReport('sent.example.com', '2026-10', ['status' => ClientReport::STATUS_SENT, 'sent_to' => ['owner@sent.example.com']]);
        Site::factory()->create(['care_plan_enabled' => true]);
        Site::factory()->create(['care_plan_enabled' => true]);

        $this->actingAs($this->user)->get(route('client-reports.schedules.index'))
            ->assertSee('1 Oct 2026 report(s) waiting for review or sending')
            ->assertSee('2 care-plan site(s) without a report schedule');
    });
});

describe('schedules page', function () {
    it('filters schedules by why they won\'t send, and shows each one\'s latest report', function () {
        $noCare = Site::factory()->create(['domain' => 'nocare.example.com', 'care_plan_enabled' => false]);
        ClientReportSchedule::create(['site_id' => $noCare->id, 'frequency' => 'monthly', 'delivery_mode' => 'auto', 'recipients' => ['a@example.com'], 'is_enabled' => true]);
        $noRecipients = Site::factory()->create(['domain' => 'norecipients.example.com', 'care_plan_enabled' => true]);
        ClientReportSchedule::create(['site_id' => $noRecipients->id, 'frequency' => 'monthly', 'delivery_mode' => 'draft', 'recipients' => [], 'is_enabled' => true]);
        $disabled = Site::factory()->create(['domain' => 'disabled.example.com', 'care_plan_enabled' => true]);
        ClientReportSchedule::create(['site_id' => $disabled->id, 'frequency' => 'monthly', 'delivery_mode' => 'auto', 'recipients' => ['b@example.com'], 'is_enabled' => false]);
        $latest = listReport('reported.example.com', '2026-10', meta: ['review_reasons' => ['No backups were found this month.']]);
        listReport('reported.example.com', '2026-09', ['status' => ClientReport::STATUS_SENT, 'sent_to' => ['owner@reported.example.com']]);

        $this->actingAs($this->user);
        $see = fn (string $show) => $this->get(route('client-reports.schedules.index', ['show' => $show]))->assertOk();

        $see('wont_send')->assertSee('nocare.example.com')->assertDontSee('norecipients.example.com')->assertDontSee('disabled.example.com');
        $see('no_recipients')->assertSee('norecipients.example.com')->assertDontSee('nocare.example.com');
        $see('disabled')->assertSee('disabled.example.com')->assertDontSee('nocare.example.com');
        $see('draft')->assertSee('norecipients.example.com')->assertDontSee('disabled.example.com');

        $this->get(route('client-reports.schedules.index', ['q' => 'reported.example']))
            ->assertSee('reported.example.com')->assertDontSee('nocare.example.com')
            ->assertSee(route('client-reports.show', $latest), false)
            ->assertSee('Needs review');
    });

    it('lists active care-plan sites without a schedule, with one-click create, and leaves out the rest', function () {
        $client = Client::create(['name' => 'Gap Person', 'email' => 'gap@example.com']);
        $gap = Site::factory()->create(['domain' => 'gap.example.com', 'care_plan_enabled' => true, 'client_id' => $client->id]);
        Site::factory()->create(['domain' => 'nocare.example.com', 'care_plan_enabled' => false]);
        Site::factory()->create(['domain' => 'inactive.example.com', 'care_plan_enabled' => true, 'is_inactive' => true]);
        Site::factory()->archived()->create(['domain' => 'archived.example.com', 'care_plan_enabled' => true]);

        $this->actingAs($this->user)->get(route('client-reports.schedules.index'))
            ->assertOk()
            ->assertSee('Care-plan sites without a report schedule (1)')
            ->assertSee('gap.example.com')
            ->assertSee('to gap@example.com')
            ->assertSee('action="'.route('client-reports.schedules.quick', $gap).'"', false)
            ->assertSee('action="'.route('client-reports.schedules.quick-all').'"', false)
            ->assertDontSee('nocare.example.com')
            ->assertDontSee('inactive.example.com')
            ->assertDontSee('archived.example.com');
    });
});

it('builds the same filters back from the query string', function () {
    $request = request()->duplicate(['period' => '2026-10', 'status' => 'ready', 'category' => 'backups', 'q' => ' acme ', 'superseded' => '1', 'client' => '12', 'from' => 'client']);
    $filters = ReportListFilters::fromRequest($request);

    expect($filters->toQuery())->toBe(['period' => '2026-10', 'status' => 'ready', 'category' => 'backups', 'q' => 'acme', 'superseded' => '1', 'client' => '12', 'from' => 'client']);

    $junk = ReportListFilters::fromRequest(request()->duplicate(['period' => '2026-13', 'status' => 'bogus', 'category' => 'bogus', 'client' => 'x', 'from' => 'client']), '2026-09');
    expect($junk->toQuery())->toBe(['period' => '2026-09']);
});
