<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Models\ClientReportTemplate;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

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
        $site = Site::factory()->create(['domain' => 'sendnow-auto.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['recipient@sendnow.test'],
            'is_enabled' => true,
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
        expect($schedule->last_sent_at)->not->toBeNull()
            ->and($schedule->next_run_at->isFuture())->toBeTrue();
    });

    it('executes immediate send in draft mode by generating draft report without sending mail', function () {
        Mail::fake();

        $user = User::factory()->create();
        $site = Site::factory()->create(['domain' => 'sendnow-draft.test']);

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

        $site1 = Site::factory()->create(['domain' => 'disabled-site.test']);
        $site2 = Site::factory()->create(['domain' => 'future-site.test']);

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

        $site = Site::factory()->create(['domain' => 'due-site.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_WEEKLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['partner@due-site.test'],
            'is_enabled' => true,
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
        expect($schedule->last_sent_at)->not->toBeNull()
            ->and($schedule->next_run_at->isFuture())->toBeTrue()
            // Weekly advancement should be ~ 7 days in future
            ->and($schedule->next_run_at->greaterThan(now()->addDays(6)))->toBeTrue();
    });

    it('processes due schedules in draft mode: generates report without emailing, and advances next_run_at', function () {
        Mail::fake();

        $site = Site::factory()->create(['domain' => 'draft-due-site.test']);

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

        $site = Site::factory()->create(['domain' => 'forced-site.test']);

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'recipients' => ['forced@site.test'],
            'is_enabled' => true,
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

        $site = Site::factory()->create(['domain' => 'early-force-site.test']);
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

        $site = Site::factory()->create(['domain' => 'custom-tpl-site.test']);

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
