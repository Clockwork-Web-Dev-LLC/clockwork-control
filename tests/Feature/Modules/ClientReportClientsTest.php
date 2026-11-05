<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\ClientManagement\Models\Client;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Services\ClientDirectory;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Modules\ClientReports\Support\ReportState;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| Clients tab and client reports page
|--------------------------------------------------------------------------
|
| The module's landing page lists every client with its sites' care plans,
| schedules and recent reports; each client has a page with every site's
| schedule and full report history, and previous/next through the list.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    $this->mockIssueCounterZero();
    $this->user = User::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

function clientsSite(?Client $client, string $domain, bool $carePlan = true, array $attributes = []): Site
{
    return Site::factory()->create(array_merge([
        'domain' => $domain,
        'client_id' => $client?->id,
        'care_plan_enabled' => $carePlan,
    ], $attributes));
}

function clientsSchedule(Site $site, array $attributes = []): ClientReportSchedule
{
    return ClientReportSchedule::create(array_merge([
        'site_id' => $site->id,
        'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
        'delivery_mode' => ClientReportSchedule::MODE_AUTO,
        'recipients' => ['owner@example.com'],
        'is_enabled' => true,
        'next_run_at' => Carbon::parse('2026-12-01'),
    ], $attributes));
}

function clientsReport(Site $site, string $month, array $attributes = [], array $meta = []): ClientReport
{
    $start = Carbon::parse($month.'-01');

    return ClientReport::create(array_merge([
        'site_id' => $site->id,
        'title' => "Report {$site->domain} {$month}",
        'period_start' => $start,
        'period_end' => $start->copy()->endOfMonth(),
        'sections_data' => ['meta' => array_merge(['schema_version' => ClientReportCompiler::SCHEMA_VERSION], $meta)],
        'client_email' => 'owner@example.com',
        'status' => ClientReport::STATUS_GENERATED,
    ], $attributes));
}

describe('Clients tab', function () {
    it('is the module landing page and needs a login', function () {
        auth()->logout();
        $this->get(route('client-reports.index'))->assertRedirect(route('login'));
        $this->get(route('client-reports.clients.show', 'unassigned'))->assertRedirect(route('login'));
    });

    it('lists each client with its sites\' care plan, schedule, recipients and a month-by-month history linking to each report', function () {
        $acme = Client::create(['name' => 'Jane Example', 'company_name' => 'Acme Example Co', 'email' => 'jane@example.com']);
        $covered = clientsSite($acme, 'covered.example.com');
        $schedule = clientsSchedule($covered, ['recipients' => ['jane@example.com', 'ops@example.com']]);
        $sent = clientsReport($covered, '2026-09', ['schedule_id' => $schedule->id, 'status' => ClientReport::STATUS_SENT, 'sent_to' => ['jane@example.com']]);
        $held = clientsReport($covered, '2026-10', ['schedule_id' => $schedule->id], ['review_reasons' => ['No backups were found this month.']]);
        clientsSite($acme, 'nocare.example.com', carePlan: false);
        clientsSite($acme, 'gap.example.com');

        $html = $this->actingAs($this->user)->get(route('client-reports.index'))->assertOk()->getContent();

        expect($html)
            ->toContain('Acme Example Co')
            ->toContain('Jane Example')
            ->toContain('covered.example.com')
            ->toContain('jane@example.com, ops@example.com')
            ->toContain('Scheduled · auto')
            ->toContain('No care plan — reports won\'t send')
            ->toContain('action="'.route('client-reports.schedules.quick', Site::where('domain', 'gap.example.com')->first()).'"')
            // History strip: a link per month that has a report, titled with its state.
            ->toContain('href="'.route('client-reports.show', $sent).'"')
            ->toContain('Sep 2026: Sent')
            ->toContain('href="'.route('client-reports.show', $held).'"')
            ->toContain('Oct 2026: Needs review')
            ->toContain('Aug 2026: no report');
    });

    it('marks months a no-care-plan site\'s schedule skipped', function () {
        $client = Client::create(['name' => 'Skip Example', 'email' => 'skip@example.com']);
        $site = clientsSite($client, 'skipped.example.com', carePlan: false);
        $schedule = clientsSchedule($site);
        $schedule->forceFill(['created_at' => Carbon::parse('2026-09-15')])->save();

        $this->actingAs($this->user)->get(route('client-reports.index'))
            ->assertOk()
            ->assertSee('Oct 2026: skipped (no care plan)')
            ->assertSee('Sep 2026: skipped (no care plan)')
            ->assertSee('Aug 2026: no report');
    });

    it('gathers sites without a client under Unassigned, after the clients', function () {
        Client::create(['name' => 'Zed Example', 'email' => 'zed@example.com']);
        clientsSite(null, 'orphan.example.com');

        $html = $this->actingAs($this->user)->get(route('client-reports.index'))->assertOk()->getContent();

        expect($html)->toContain('Unassigned sites')->toContain('orphan.example.com')
            ->and(strpos($html, 'Zed Example'))->toBeLessThan(strpos($html, 'Unassigned sites'));
    });

    it('filters by search, care plan, waiting reports and missing schedules', function () {
        $care = Client::create(['name' => 'Care Example', 'email' => 'care@example.com']);
        $careSite = clientsSite($care, 'care.example.com');
        clientsReport($careSite, '2026-10', ['schedule_id' => clientsSchedule($careSite)->id], ['review_reasons' => ['No backups were found this month.']]);

        $none = Client::create(['name' => 'Nocare Example', 'email' => 'nocare@example.com']);
        clientsSite($none, 'nocare.example.com', carePlan: false);

        $gap = Client::create(['name' => 'Gap Example', 'email' => 'gap@example.com']);
        clientsSite($gap, 'gap.example.com');

        $this->actingAs($this->user);

        $this->get(route('client-reports.index', ['q' => 'nocare.example']))->assertSee('Nocare Example')->assertDontSee('Gap Example');
        $this->get(route('client-reports.index', ['care' => 'no']))->assertSee('Nocare Example')->assertDontSee('Care Example');
        $this->get(route('client-reports.index', ['care' => 'yes']))->assertSee('Care Example')->assertSee('Gap Example')->assertDontSee('Nocare Example');
        $this->get(route('client-reports.index', ['waiting' => 1]))->assertSee('Care Example')->assertDontSee('Gap Example')->assertDontSee('Nocare Example');
        $this->get(route('client-reports.index', ['missing' => 1]))->assertSee('Gap Example')->assertDontSee('Care Example')->assertDontSee('Nocare Example');
    });

    it('does not count an inactive care-plan site as missing a schedule', function () {
        $client = Client::create(['name' => 'Inactive Example', 'email' => 'inactive@example.com']);
        clientsSite($client, 'paused.example.com', attributes: ['is_inactive' => true]);

        $this->actingAs($this->user)->get(route('client-reports.index', ['missing' => 1]))
            ->assertOk()->assertDontSee('Inactive Example');
    });
});

describe('client reports page', function () {
    it('shows the client\'s contacts, each site\'s schedule and full report history with every action', function () {
        $client = Client::create(['name' => 'Pat Example', 'company_name' => 'Page Example Ltd', 'email' => 'pat@example.com', 'additional_emails' => ['billing@example.com']]);
        $site = clientsSite($client, 'page.example.com');
        $schedule = clientsSchedule($site, ['recipients' => ['pat@example.com']]);
        $old = clientsReport($site, '2026-06', ['schedule_id' => $schedule->id, 'status' => ClientReport::STATUS_SENT, 'sent_to' => ['pat@example.com']]);
        $held = clientsReport($site, '2026-10', ['schedule_id' => $schedule->id], ['review_reasons' => [ScheduledReportRunner::FIRST_REPORT_REASON, 'No backups were found this month.']]);
        $replaced = clientsReport($site, '2026-10', ['schedule_id' => $schedule->id, 'status' => ClientReport::STATUS_SUPERSEDED]);

        $html = $this->actingAs($this->user)->get(route('client-reports.clients.show', $client->id))->assertOk()->getContent();

        expect($html)
            ->toContain('Page Example Ltd')
            ->toContain('billing@example.com')
            ->toContain('1 of 1 site on a care plan')
            ->toContain('Next run')->toContain('Dec 1, 2026')
            ->toContain(route('client-reports.schedules.edit', $schedule))
            // Every report of the site, oldest included, with its state and reason chips.
            ->toContain('June 2026')->toContain('October 2026')
            ->toContain(ReportState::label(ReportState::NEEDS_REVIEW))
            ->toContain('Backups')
            ->toContain(e(route('client-reports.email', [$held, 'period' => 'all', 'client' => (string) $client->id, 'from' => 'client'])))
            ->toContain('action="'.e(route('client-reports.recompile', [$held, 'period' => 'all', 'client' => (string) $client->id, 'from' => 'client'])).'"')
            ->toContain('action="'.route('client-reports.send', $held).'"')
            ->toContain('action="'.route('client-reports.send-test', $held).'"')
            ->toContain('action="'.route('client-reports.send-test', $old).'"')
            // The replaced copy stays out of the way until asked for.
            ->not->toContain(e(route('client-reports.show', [$replaced, 'period' => 'all', 'client' => (string) $client->id, 'from' => 'client'])))
            ->toContain('Show 1 replaced copy');

        $this->get(route('client-reports.clients.show', [$client->id, 'superseded' => 1]))
            ->assertSee(e(route('client-reports.show', [$replaced, 'period' => 'all', 'client' => (string) $client->id, 'from' => 'client', 'superseded' => '1'])), false);
    });

    it('explains the consequences before a real send: who gets it and that the schedule then sends by itself', function () {
        $client = Client::create(['name' => 'Confirm Example', 'email' => 'confirm@example.com']);
        $site = clientsSite($client, 'confirm.example.com');
        $schedule = clientsSchedule($site, ['recipients' => ['confirm@example.com']]);
        clientsReport($site, '2026-10', ['schedule_id' => $schedule->id, 'client_email' => 'confirm@example.com'], ['review_reasons' => [ScheduledReportRunner::FIRST_REPORT_REASON]]);

        $this->actingAs($this->user)->get(route('client-reports.clients.show', $client->id))
            ->assertOk()
            ->assertSee('Email report to confirm@example.com?')
            ->assertSee('once it has been sent, the schedule emails its clean reports automatically every month')
            ->assertSee('Only you get it, with [TEST] in the subject');
    });

    it('steps to the previous and next client of the filtered list, alphabetically, and offers every client in the switcher', function () {
        $alpha = Client::create(['name' => 'Alpha Example', 'email' => 'a@example.com']);
        $bravo = Client::create(['name' => 'Bravo Example', 'email' => 'b@example.com']);
        $charlie = Client::create(['name' => 'Charlie Example', 'email' => 'c@example.com']);
        clientsSite($alpha, 'alpha.example.com');
        clientsSite($bravo, 'bravo.example.com', carePlan: false);
        clientsSite($charlie, 'charlie.example.com');

        $this->actingAs($this->user);

        $this->get(route('client-reports.clients.show', $bravo->id))
            ->assertOk()
            ->assertSee('href="'.route('client-reports.clients.show', $alpha->id).'" class="cr-btn" rel="prev"', false)
            ->assertSee('href="'.route('client-reports.clients.show', $charlie->id).'" class="cr-btn" rel="next"', false)
            ->assertSee('2 of 3');

        // With "care plan: yes", Bravo drops out: Alpha's next is Charlie.
        $this->get(route('client-reports.clients.show', [$alpha->id, 'care' => 'yes']))
            ->assertSee('href="'.route('client-reports.clients.show', [$charlie->id, 'care' => 'yes']).'" class="cr-btn" rel="next"', false)
            ->assertSee('<option value="Bravo Example">', false)
            ->assertSee(route('client-reports.index', ['care' => 'yes']), false);
    });

    it('has a page for the Unassigned group and 404s for unknown clients', function () {
        clientsSite(null, 'loose.example.com');

        $this->actingAs($this->user);
        $this->get(route('client-reports.clients.show', 'unassigned'))->assertOk()->assertSee('Unassigned sites')->assertSee('loose.example.com');
        $this->get('/client-reports/clients/999999')->assertNotFound();
    });
});

describe('ClientDirectory', function () {
    it('lists the last six report periods ending with last month', function () {
        $months = app(ClientDirectory::class)->months();

        expect(array_map(fn ($m) => $m->format('Y-m'), $months))
            ->toBe(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10']);
    });

    it('counts only each site\'s newest report as waiting', function () {
        $client = Client::create(['name' => 'Count Example', 'email' => 'count@example.com']);
        $site = clientsSite($client, 'count.example.com');
        $schedule = clientsSchedule($site);
        clientsReport($site, '2026-09', ['schedule_id' => $schedule->id], ['review_reasons' => ['No backups were found this month.']]);
        clientsReport($site, '2026-10', ['schedule_id' => $schedule->id, 'status' => ClientReport::STATUS_SENT, 'sent_to' => ['owner@example.com']]);

        $row = app(ClientDirectory::class)->find((string) $client->id);

        expect($row['counts']['waiting'])->toBe(0)
            ->and($row['sites'][0]['months']['2026-09']['state'])->toBe(ReportState::NEEDS_REVIEW)
            ->and($row['sites'][0]['months']['2026-10']['state'])->toBe(ReportState::SENT);
    });
});
