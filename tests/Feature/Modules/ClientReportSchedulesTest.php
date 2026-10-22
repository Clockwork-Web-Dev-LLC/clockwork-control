<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Models\ClientReportTemplate;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/** A care-plan site whose compiled report has nothing to hold for review (no backup source to check). */
function reportableSite(array $attributes = []): Site
{
    return Site::factory()->carePlan()->create(array_merge(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'backup_relay_enabled' => false], $attributes));
}

it('requires authentication for schedule management routes', function () {
    $site = Site::factory()->create();
    $schedule = ClientReportSchedule::create([
        'site_id' => $site->id,
        'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
        'delivery_mode' => ClientReportSchedule::MODE_AUTO,
        'recipients' => ['client@example.com'],
        'is_enabled' => true,
    ]);

    $this->get(route('client-reports.schedules.index'))->assertRedirect(route('login'));
    $this->get(route('client-reports.schedules.create'))->assertRedirect(route('login'));
    $this->post(route('client-reports.schedules.store'))->assertRedirect(route('login'));
    $this->get(route('client-reports.schedules.edit', $schedule))->assertRedirect(route('login'));
    $this->put(route('client-reports.schedules.update', $schedule))->assertRedirect(route('login'));
    $this->delete(route('client-reports.schedules.destroy', $schedule))->assertRedirect(route('login'));
    $this->patch(route('client-reports.schedules.toggle', $schedule))->assertRedirect(route('login'));
    $this->post(route('client-reports.schedules.send-now', $schedule))->assertRedirect(route('login'));
});

describe('Schedules UI & CRUD', function () {
    it('renders the schedules list including active schedules and unscheduled sites', function () {
        $this->mockIssueCounterZero();
        $user = User::factory()->create();

        $scheduledSite = Site::factory()->create(['domain' => 'scheduled.test', 'care_plan_enabled' => true]);
        $unscheduledSite = Site::factory()->create(['domain' => 'unscheduled.test', 'care_plan_enabled' => true]);

        $schedule = ClientReportSchedule::create([
            'site_id' => $scheduledSite->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['boss@scheduled.test'],
            'is_enabled' => true,
            'next_run_at' => now()->addWeeks(2),
        ]);

        $response = $this->actingAs($user)->get(route('client-reports.schedules.index'));

        $response->assertOk()
            ->assertSee('scheduled.test')
            ->assertSee('boss@scheduled.test')
            ->assertSee('Auto-Send')
            ->assertSee('unscheduled.test')
            ->assertSee('Unscheduled Managed Sites');
    });

    it('renders the create schedule form with available sites and templates', function () {
        $this->mockIssueCounterZero();
        $user = User::factory()->create();

        $site = Site::factory()->create(['domain' => 'client-site.test']);

        $response = $this->actingAs($user)->get(route('client-reports.schedules.create'));

        $response->assertOk()
            ->assertSee('New Reporting Schedule')
            ->assertSee('client-site.test')
            ->assertSee('Default template');
    });

    it('redirects create form to edit form if site_id query parameter already has a schedule', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['client@example.com'],
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($user)->get(route('client-reports.schedules.create', ['site_id' => $site->id]));

        $response->assertRedirect(route('client-reports.schedules.edit', $schedule));
    });

    it('stores a new schedule with valid recipients and calculates next_run_at', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create(['domain' => 'new-sched.test']);
        $template = ClientReportTemplate::query()->first();

        $response = $this->actingAs($user)->post(route('client-reports.schedules.store'), [
            'site_id' => $site->id,
            'template_id' => $template?->id,
            'frequency' => ClientReportSchedule::FREQUENCY_WEEKLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => "client1@example.com\nclient2@example.com",
            'is_enabled' => 1,
        ]);

        $response->assertRedirect(route('client-reports.schedules.index'))
            ->assertSessionHas('status', "Reporting schedule for {$site->domain} created successfully.");

        $schedule = ClientReportSchedule::where('site_id', $site->id)->firstOrFail();
        expect($schedule->frequency)->toBe(ClientReportSchedule::FREQUENCY_WEEKLY)
            ->and($schedule->delivery_mode)->toBe(ClientReportSchedule::MODE_AUTO)
            ->and($schedule->recipients)->toBe(['client1@example.com', 'client2@example.com'])
            ->and($schedule->is_enabled)->toBeTrue()
            ->and($schedule->next_run_at)->not->toBeNull()
            ->and($schedule->next_run_at->isFuture())->toBeTrue();
    });

    it('validates that auto delivery mode requires at least one recipient email', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($user)->post(route('client-reports.schedules.store'), [
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => '',
        ]);

        $response->assertSessionHasErrors(['recipients']);
        expect(ClientReportSchedule::where('site_id', $site->id)->exists())->toBeFalse();
    });

    it('validates recipient email formatting', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($user)->post(route('client-reports.schedules.store'), [
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => 'not-an-email, valid@example.com',
        ]);

        $response->assertSessionHasErrors(['recipients']);
    });

    it('allows draft delivery mode without recipients', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create(['domain' => 'draft-site.test']);

        $response = $this->actingAs($user)->post(route('client-reports.schedules.store'), [
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_DRAFT,
            'recipients' => '',
            'is_enabled' => 1,
        ]);

        $response->assertRedirect(route('client-reports.schedules.index'));

        $schedule = ClientReportSchedule::where('site_id', $site->id)->firstOrFail();
        expect($schedule->delivery_mode)->toBe(ClientReportSchedule::MODE_DRAFT)
            ->and($schedule->recipients)->toBeEmpty();
    });

    it('updates an existing schedule', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create(['domain' => 'update-sched.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['old@example.com'],
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($user)->put(route('client-reports.schedules.update', $schedule), [
            'frequency' => ClientReportSchedule::FREQUENCY_WEEKLY,
            'delivery_mode' => ClientReportSchedule::MODE_DRAFT,
            'recipients' => 'new@example.com',
            'is_enabled' => 0,
        ]);

        $response->assertRedirect(route('client-reports.schedules.index'))
            ->assertSessionHas('status', "Schedule for {$site->domain} updated successfully.");

        $schedule->refresh();
        expect($schedule->frequency)->toBe(ClientReportSchedule::FREQUENCY_WEEKLY)
            ->and($schedule->delivery_mode)->toBe(ClientReportSchedule::MODE_DRAFT)
            ->and($schedule->recipients)->toBe(['new@example.com'])
            ->and($schedule->is_enabled)->toBeFalse();
    });

    it('toggles schedule is_enabled status via toggle endpoint', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['toggle@example.com'],
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($user)->patch(route('client-reports.schedules.toggle', $schedule));
        $response->assertRedirect(route('client-reports.schedules.index'));

        expect($schedule->fresh()->is_enabled)->toBeFalse();

        $this->actingAs($user)->patch(route('client-reports.schedules.toggle', $schedule));
        expect($schedule->fresh()->is_enabled)->toBeTrue();
    });

    it('deletes a schedule', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create(['domain' => 'delete-sched.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['del@example.com'],
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($user)->delete(route('client-reports.schedules.destroy', $schedule));

        $response->assertRedirect(route('client-reports.schedules.index'))
            ->assertSessionHas('status', "Reporting schedule for {$site->domain} deleted.");

        expect(ClientReportSchedule::where('id', $schedule->id)->exists())->toBeFalse();
    });
});

describe('sendNow immediate execution', function () {
    it('executes immediate send in auto mode by sending mail and advancing next_run_at', function () {
        Mail::fake();

        $user = User::factory()->create();
        $site = reportableSite(['domain' => 'sendnow-auto.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['recipient@sendnow.test'],
            'is_enabled' => true,
            'last_sent_at' => now()->subMonth(), // has sent before: a first report is always held
            'next_run_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)
            ->post(route('client-reports.schedules.send-now', $schedule));

        $response->assertRedirect();

        Mail::assertSent(ClientReportMail::class, function ($mail) {
            return $mail->hasTo('recipient@sendnow.test');
        });

        $report = ClientReport::where('site_id', $site->id)->firstOrFail();
        expect($report->status)->toBe('sent')
            ->and($report->sent_at)->not->toBeNull();

        $schedule->refresh();
        expect($schedule->last_sent_at->isToday())->toBeTrue()
            ->and($schedule->next_run_at->isFuture())->toBeTrue();
    });

    it('executes immediate send in draft mode by generating draft report without sending mail', function () {
        Mail::fake();

        $user = User::factory()->create();
        $site = reportableSite(['domain' => 'sendnow-draft.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_WEEKLY,
            'delivery_mode' => ClientReportSchedule::MODE_DRAFT,
            'recipients' => ['draft-reviewer@test.com'],
            'is_enabled' => true,
            'next_run_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)
            ->post(route('client-reports.schedules.send-now', $schedule));

        Mail::assertNothingSent();

        $report = ClientReport::where('site_id', $site->id)->firstOrFail();
        expect($report->status)->toBe('generated')
            ->and($report->sent_at)->toBeNull();

        $response->assertRedirect(route('client-reports.show', $report));

        $schedule->refresh();
        expect($schedule->last_sent_at)->toBeNull()
            ->and($schedule->next_run_at->isFuture())->toBeTrue();
    });
});

describe('Console Command: clockwork:send-client-reports', function () {
    it('skips disabled schedules and schedules whose next_run_at is in the future', function () {
        Mail::fake();

        $site1 = reportableSite(['domain' => 'disabled-site.test']);
        $site2 = reportableSite(['domain' => 'future-site.test']);

        // Disabled schedule
        ClientReportSchedule::create([
            'site_id' => $site1->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['client1@test.com'],
            'is_enabled' => false,
            'next_run_at' => now()->subDay(),
        ]);

        // Future schedule
        ClientReportSchedule::create([
            'site_id' => $site2->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['client2@test.com'],
            'is_enabled' => true,
            'next_run_at' => now()->addDays(5),
        ]);

        $this->artisan('clockwork:send-client-reports')
            ->expectsOutputToContain('skipped (not due until')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        expect(ClientReport::count())->toBe(0);
    });

    it('processes due schedules in auto mode: generates report, emails client, and advances next_run_at', function () {
        Mail::fake();

        $site = reportableSite(['domain' => 'due-site.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_WEEKLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['partner@due-site.test'],
            'is_enabled' => true,
            'last_sent_at' => now()->subMonth(), // has sent before: a first report is always held
            'next_run_at' => now()->subMinutes(10),
        ]);

        $this->artisan('clockwork:send-client-reports')
            ->expectsOutputToContain('sent report to partner@due-site.test')
            ->expectsOutputToContain('Scheduled client reports dispatch completed (1 processed).')
            ->assertExitCode(0);

        Mail::assertSent(ClientReportMail::class, function ($mail) {
            return $mail->hasTo('partner@due-site.test');
        });

        $report = ClientReport::where('site_id', $site->id)->firstOrFail();
        expect($report->status)->toBe('sent')
            ->and($report->sent_at)->not->toBeNull();

        $schedule->refresh();
        expect($schedule->last_sent_at->isToday())->toBeTrue()
            ->and($schedule->next_run_at->isFuture())->toBeTrue()
            // Weekly advancement should be ~ 7 days in future
            ->and($schedule->next_run_at->greaterThan(now()->addDays(6)))->toBeTrue();
    });

    it('processes due schedules in draft mode: generates report without emailing, and advances next_run_at', function () {
        Mail::fake();

        $site = reportableSite(['domain' => 'draft-due-site.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_DRAFT,
            'recipients' => ['team@draft-due-site.test'],
            'is_enabled' => true,
            'next_run_at' => now()->subHour(),
        ]);

        $this->artisan('clockwork:send-client-reports')
            ->expectsOutputToContain('generated draft report (awaiting operator review)')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        $report = ClientReport::where('site_id', $site->id)->firstOrFail();
        expect($report->status)->toBe('generated')
            ->and($report->sent_at)->toBeNull();

        $schedule->refresh();
        expect($schedule->last_sent_at)->toBeNull()
            ->and($schedule->next_run_at->isFuture())->toBeTrue();
    });

    it('--force bypasses the due-date check and processes future schedules', function () {
        Mail::fake();

        $site = reportableSite(['domain' => 'forced-site.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['forced@site.test'],
            'is_enabled' => true,
            'last_sent_at' => now()->subMonth(), // has sent before: a first report is always held
            'next_run_at' => now()->addDays(20), // not due!
        ]);

        // Without force: skipped
        $this->artisan('clockwork:send-client-reports --site='.$site->id)
            ->expectsOutputToContain('skipped (not due until')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        // With force: processed!
        $this->artisan('clockwork:send-client-reports --site='.$site->id.' --force')
            ->expectsOutputToContain('sent report to forced@site.test')
            ->assertExitCode(0);

        Mail::assertSent(ClientReportMail::class);
        expect(ClientReport::where('site_id', $site->id)->exists())->toBeTrue();
    });

    it('forcing a not-yet-due schedule does not skip its originally planned cycle', function () {
        // Regression: advanceNextRun() used to chain from the schedule's own
        // (still-future) next_run_at when forced/run early, silently
        // skipping the originally planned dispatch — e.g. a monthly schedule
        // due 2026-10-01, force-run on 2026-09-15, would jump straight to
        // 2026-11-01 and the client would never get an October report.
        Mail::fake();

        $site = reportableSite(['domain' => 'early-force-site.test']);
        $originalNextRun = now()->addDays(20);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['forced@site.test'],
            'is_enabled' => true,
            'next_run_at' => $originalNextRun,
        ]);

        $this->artisan('clockwork:send-client-reports --site='.$site->id.' --force')
            ->assertExitCode(0);

        $schedule->refresh();
        // Should land ~1 month from now (today + interval), not ~2 months
        // from now (the original future next_run_at + interval).
        expect($schedule->next_run_at->lessThan($originalNextRun->copy()->addMonth()->subDays(15)))->toBeTrue();
    });

    it('applies custom template sections when schedule references a template', function () {
        Mail::fake();

        $site = reportableSite(['domain' => 'custom-tpl-site.test']);

        $template = ClientReportTemplate::create([
            'name' => 'Backups & Traffic Only',
            'sections' => ['backups', 'traffic'],
            'is_default' => false,
        ]);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'template_id' => $template->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_DRAFT,
            'recipients' => [],
            'is_enabled' => true,
            'next_run_at' => now()->subDay(),
        ]);

        $this->artisan('clockwork:send-client-reports --site='.$site->id)
            ->assertExitCode(0);

        $report = ClientReport::where('site_id', $site->id)->firstOrFail();
        expect($report->template_id)->toBe($template->id)
            ->and($report->sections_data)->toHaveKey('backups')
            ->and($report->sections_data)->toHaveKey('traffic')
            ->and($report->sections_data)->not->toHaveKey('uptime')
            ->and($report->sections_data)->not->toHaveKey('security');
    });

    it('verifies that the command is registered on the scheduler', function () {
        $schedule = app(Schedule::class);

        $scheduledCommands = collect($schedule->events())
            ->filter(fn (Event $event) => str_contains($event->command ?? '', 'clockwork:send-client-reports'));

        expect($scheduledCommands->isNotEmpty())->toBeTrue();

        /** @var Event $event */
        $event = $scheduledCommands->first();
        expect($event->expression)->toBe('0 6 * * *'); // daily at 06:00
    });
});

describe('ClientReportSchedule next-run cadence', function () {
    it('snaps monthly runs to the 1st at midnight so the 06:00 tick never slips a day', function () {
        $schedule = new ClientReportSchedule(['frequency' => ClientReportSchedule::FREQUENCY_MONTHLY]);

        // A batch that finishes a few minutes after the 06:00 tick on Nov 1.
        $next = $schedule->computeNextRun(Carbon::parse('2026-11-01 06:04:37', 'UTC'));

        expect($next->toDateTimeString())->toBe('2026-12-01 00:00:00');
    });

    it('does not overflow past short months', function () {
        $schedule = new ClientReportSchedule(['frequency' => ClientReportSchedule::FREQUENCY_MONTHLY]);

        expect($schedule->computeNextRun(Carbon::parse('2027-01-31 06:01:00', 'UTC'))->toDateTimeString())
            ->toBe('2027-02-01 00:00:00');
    });

    it('snaps weekly runs to midnight seven days out', function () {
        $schedule = new ClientReportSchedule(['frequency' => ClientReportSchedule::FREQUENCY_WEEKLY]);

        expect($schedule->computeNextRun(Carbon::parse('2026-11-02 06:03:00', 'UTC'))->toDateTimeString())
            ->toBe('2026-11-09 00:00:00');
    });

    it('is due again at the next month\'s 06:00 tick after a slow send', function () {
        $this->travelTo(Carbon::parse('2026-11-01 06:04:37', 'UTC'));
        $schedule = ClientReportSchedule::create([
            'site_id' => reportableSite()->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_DRAFT,
            'recipients' => [],
            'is_enabled' => true,
        ]);
        $schedule->advanceNextRun();

        $this->travelTo(Carbon::parse('2026-12-01 06:00:00', 'UTC'));

        expect($schedule->fresh()->isDue())->toBeTrue();
    });
});

describe('partial delivery failures', function () {
    /**
     * Swap the mailer for one that throws for any address currently in
     * $failing (mutable, so a test can let a retry succeed) and records every
     * address it actually delivered to.
     */
    function mailerFailingFor(ArrayObject $failing): ArrayObject
    {
        $delivered = new ArrayObject;

        Mail::shouldReceive('to')->andReturnUsing(function (string $email) use ($failing, $delivered) {
            $pending = Mockery::mock();
            $pending->shouldReceive('send')->andReturnUsing(function () use ($email, $failing, $delivered) {
                if (in_array($email, $failing->getArrayCopy(), true)) {
                    throw new RuntimeException("550 mailbox unavailable: {$email}");
                }
                $delivered[] = $email;
            });

            return $pending;
        });

        return $delivered;
    }

    function dueAutoSchedule(array $recipients): ClientReportSchedule
    {
        return ClientReportSchedule::create([
            'site_id' => reportableSite()->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => $recipients,
            'is_enabled' => true,
            'last_sent_at' => now()->subMonth(), // has sent before: a first report is always held
            'next_run_at' => now()->subHour(),
        ]);
    }

    it('records who got the report and leaves the schedule due when one recipient fails', function () {
        $delivered = mailerFailingFor(new ArrayObject(['bad@client.test']));
        $schedule = dueAutoSchedule(['a@client.test', 'bad@client.test', 'c@client.test']);

        $this->artisan('clockwork:send-client-reports')
            ->expectsOutputToContain('failed for bad@client.test')
            ->assertExitCode(0);

        expect($delivered->getArrayCopy())->toBe(['a@client.test', 'c@client.test']);

        $report = ClientReport::sole();
        expect($report->status)->toBe('generated')
            ->and($report->sent_to)->toBe(['a@client.test', 'c@client.test'])
            ->and($report->schedule_id)->toBe($schedule->id);

        // Not stamped by this run: it still shows last month's send.
        $schedule->refresh();
        expect($schedule->last_sent_at->lessThan(now()->subDay()))->toBeTrue()
            ->and($schedule->isDue())->toBeTrue();
    });

    it('resumes the same report on the next run and only emails the recipient that failed', function () {
        $failing = new ArrayObject(['bad@client.test']);
        $delivered = mailerFailingFor($failing);
        $schedule = dueAutoSchedule(['a@client.test', 'bad@client.test', 'c@client.test']);
        $this->artisan('clockwork:send-client-reports')->assertExitCode(0);

        $failing->exchangeArray([]);
        $delivered->exchangeArray([]);

        $this->artisan('clockwork:send-client-reports')
            ->expectsOutputToContain('sent report to bad@client.test (resumed earlier partial send)')
            ->assertExitCode(0);

        expect($delivered->getArrayCopy())->toBe(['bad@client.test']);

        $report = ClientReport::sole();
        expect($report->status)->toBe('sent')
            ->and($report->sent_to)->toBe(['a@client.test', 'c@client.test', 'bad@client.test']);

        $schedule->refresh();
        expect($schedule->last_sent_at->isToday())->toBeTrue()
            ->and($schedule->next_run_at->isFuture())->toBeTrue();
    });

    it('does not pile up duplicate reports while every send keeps failing', function () {
        mailerFailingFor(new ArrayObject(['only@client.test']));
        dueAutoSchedule(['only@client.test']);

        $this->artisan('clockwork:send-client-reports')->assertExitCode(0);
        $this->artisan('clockwork:send-client-reports')->assertExitCode(0);

        expect(ClientReport::count())->toBe(1)
            ->and(ClientReport::sole()->sent_to)->toBeNull();
    });

    it('Send now reports the failed addresses and only retries those', function () {
        $user = User::factory()->create();
        mailerFailingFor(new ArrayObject(['bad@client.test']));
        $schedule = dueAutoSchedule(['a@client.test', 'bad@client.test']);

        $this->actingAs($user)
            ->post(route('client-reports.schedules.send-now', $schedule))
            ->assertSessionHas('status_error', fn (string $msg) => str_contains($msg, 'bad@client.test')
                && str_contains($msg, 'Delivered to a@client.test'));

        expect(ClientReport::sole()->sent_to)->toBe(['a@client.test']);
    });
});

describe('care-plan customers only', function () {
    it('skips a schedule whose site has no care plan, and moves it on to next month', function () {
        Mail::fake();
        $site = Site::factory()->create(['domain' => 'no-plan.test', 'care_plan_enabled' => false, 'hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM]);
        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO, 'recipients' => ['client@no-plan.test'],
            'is_enabled' => true, 'next_run_at' => now()->subHour(),
        ]);

        $this->artisan('clockwork:send-client-reports')
            ->expectsOutputToContain('no-plan.test: skipped this month (care plan is off')
            ->assertSuccessful();

        Mail::assertNothingSent();
        expect(ClientReport::count())->toBe(0)
            ->and($schedule->fresh()->next_run_at->isFuture())->toBeTrue();
    });

    it('refuses Send now for a site with no care plan', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create(['care_plan_enabled' => false]);
        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_DRAFT, 'recipients' => [], 'is_enabled' => true,
        ]);

        $this->actingAs($user)->post(route('client-reports.schedules.send-now', $schedule))
            ->assertSessionHas('status_error', fn ($m) => str_contains($m, 'no care plan'));
        expect(ClientReport::count())->toBe(0);
    });
});

describe('hold for review', function () {
    it('keeps an auto report as a draft when something needs a person, and moves the schedule on', function () {
        Mail::fake();
        // A SpinupWP site whose backups were never checked: "Backups could not be checked."
        $site = Site::factory()->carePlan()->create(['domain' => 'flagged.test', 'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP]);
        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO, 'recipients' => ['client@flagged.test'],
            'is_enabled' => true, 'next_run_at' => now()->subHour(), 'last_sent_at' => now()->subMonth(),
        ]);

        $this->artisan('clockwork:send-client-reports')
            ->expectsOutputToContain('flagged.test: held as a draft for review: Backups could not be checked.')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $report = ClientReport::sole();
        expect($report->status)->toBe('generated')
            ->and($report->sections_data['meta']['review_reasons'])->toBe(['Backups could not be checked.'])
            ->and($schedule->fresh()->next_run_at->isFuture())->toBeTrue();

        // Running again in the same period never sends a held report by itself,
        // and leaves one live copy: the older one is superseded.
        $this->artisan('clockwork:send-client-reports', ['--force' => true])->assertSuccessful();
        $this->artisan('clockwork:send-client-reports', ['--force' => true])->assertSuccessful();
        Mail::assertNothingSent();
        expect(ClientReport::where('status', ClientReport::STATUS_GENERATED)->count())->toBe(1)
            ->and(ClientReport::where('status', ClientReport::STATUS_SUPERSEDED)->count())->toBe(2);
    });

    it('checks a draft-mode report too, and never emails it unchecked after switching to auto', function () {
        Mail::fake();
        $site = Site::factory()->carePlan()->create(['domain' => 'was-draft.test', 'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP]);
        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_DRAFT, 'recipients' => ['client@was-draft.test'],
            'is_enabled' => true, 'next_run_at' => now()->subHour(),
        ]);

        $this->artisan('clockwork:send-client-reports')->assertSuccessful();
        expect(ClientReport::sole()->sections_data['meta']['review_reasons'])->toBe(['Backups could not be checked.']);

        $schedule->update(['delivery_mode' => ClientReportSchedule::MODE_AUTO]);
        $this->artisan('clockwork:send-client-reports', ['--force' => true])->assertSuccessful();

        Mail::assertNothingSent();
    });

    it('will not send a superseded copy by hand', function () {
        Mail::fake();
        $user = User::factory()->create();
        $report = ClientReport::create([
            'site_id' => reportableSite()->id, 'title' => 'Old copy', 'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(), 'sections_data' => ['meta' => []],
            'client_email' => 'client@example.com', 'status' => ClientReport::STATUS_SUPERSEDED,
        ]);

        $this->actingAs($user)->post(route('client-reports.send', $report))->assertSessionHas('status_error');
        Mail::assertNothingSent();
    });

    it('shows the review reasons to operators but never on the public view', function () {
        $this->mockIssueCounterZero();
        $user = User::factory()->create();
        $site = reportableSite();
        $data = app(ClientReportCompiler::class)->compile($site, now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth());
        $data['meta']['review_reasons'] = ['Backups could not be checked.'];
        $report = ClientReport::create([
            'site_id' => $site->id, 'title' => 'Held', 'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(), 'status' => ClientReport::STATUS_GENERATED, 'sections_data' => $data,
        ]);

        $this->actingAs($user)->get(route('client-reports.show', $report))->assertSee('Backups could not be checked.');
        $this->get(route('client-reports.public', $report->public_token))->assertDontSee('Backups could not be checked.');
    });

    it('sends a clean auto report straight away', function () {
        Mail::fake();
        $site = reportableSite(['domain' => 'clean.test']);
        ClientReportSchedule::create([
            'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO, 'recipients' => ['client@clean.test'],
            'is_enabled' => true, 'next_run_at' => now()->subHour(), 'last_sent_at' => now()->subMonth(),
        ]);

        $this->artisan('clockwork:send-client-reports')->assertSuccessful();

        Mail::assertSent(ClientReportMail::class, 1);
        expect(ClientReport::sole()->status)->toBe('sent');
    });

    it('holds a schedule\'s first report, and sends the next clean one by itself once that was sent by hand', function () {
        Mail::fake();
        $user = User::factory()->create();
        $site = reportableSite(['domain' => 'first.test']);
        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO, 'recipients' => ['client@first.test'],
            'is_enabled' => true, 'next_run_at' => now()->subHour(),
        ]);

        $this->artisan('clockwork:send-client-reports')
            ->expectsOutputToContain('first.test: held as a draft for review: This is the first report from this schedule.')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $first = ClientReport::sole();
        expect($first->sections_data['meta']['review_reasons'])->toBe([ScheduledReportRunner::FIRST_REPORT_REASON])
            ->and($schedule->fresh()->next_run_at->isFuture())->toBeTrue();

        $this->actingAs($user)->post(route('client-reports.send', $first))->assertSessionHas('status');
        Mail::assertSent(ClientReportMail::class, 1);
        expect($schedule->fresh()->last_sent_at)->not->toBeNull();

        // Next month: clean, so it goes out by itself.
        $this->travel(1)->months();
        $this->artisan('clockwork:send-client-reports')
            ->expectsOutputToContain('first.test: sent report to client@first.test')
            ->assertSuccessful();
        Mail::assertSent(ClientReportMail::class, 2);
    });

    it('does not hold a draft-mode report just for being the first', function () {
        Mail::fake();
        $site = reportableSite(['domain' => 'first-draft.test']);
        ClientReportSchedule::create([
            'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_DRAFT, 'recipients' => ['client@first-draft.test'],
            'is_enabled' => true, 'next_run_at' => now()->subHour(),
        ]);

        $this->artisan('clockwork:send-client-reports')->assertSuccessful();

        expect(ClientReport::sole()->sections_data['meta'])->not->toHaveKey('review_reasons');
    });
});

describe('sending a partly sent report by hand', function () {
    it('only emails the recipients who do not have it yet', function () {
        Mail::fake();
        $user = User::factory()->create();
        $report = ClientReport::create([
            'site_id' => reportableSite()->id, 'title' => 'Partly sent', 'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(), 'sections_data' => ['meta' => []],
            'client_email' => 'a@client.test, b@client.test', 'sent_to' => ['a@client.test'],
            'status' => ClientReport::STATUS_GENERATED,
        ]);

        $this->actingAs($user)->post(route('client-reports.send', $report))
            ->assertSessionHas('status', 'Report emailed to b@client.test.');

        Mail::assertSent(ClientReportMail::class, 1);
        Mail::assertSent(ClientReportMail::class, fn ($mail) => $mail->hasTo('b@client.test'));
        expect($report->fresh()->status)->toBe(ClientReport::STATUS_SENT)
            ->and($report->fresh()->sent_to)->toBe(['a@client.test', 'b@client.test']);
    });

    it('sends a fully sent report to everyone again when asked', function () {
        Mail::fake();
        $user = User::factory()->create();
        $report = ClientReport::create([
            'site_id' => reportableSite()->id, 'title' => 'Sent', 'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(), 'sections_data' => ['meta' => []],
            'client_email' => 'a@client.test, b@client.test', 'sent_to' => ['a@client.test', 'b@client.test'],
            'status' => ClientReport::STATUS_SENT,
        ]);

        $this->actingAs($user)->post(route('client-reports.send', $report))->assertSessionHas('status');

        Mail::assertSent(ClientReportMail::class, 2);
    });
});
