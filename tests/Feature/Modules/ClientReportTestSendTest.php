<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\ClientManagement\Models\Client;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Mail\ClientReportTestMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Services\ClientReportCompiler;

/*
|--------------------------------------------------------------------------
| "Send test to me" and one-click schedules
|--------------------------------------------------------------------------
|
| A test send emails the client's exact email to the signed-in operator and
| nobody else, and changes nothing: the report isn't marked sent and the
| schedule's last send (which switches it to sending by itself) stays put.
*/

function testSendReport(array $attributes = []): ClientReport
{
    $site = Site::factory()->create(['domain' => 'test-send-'.Str::lower(Str::random(6)).'.example.com', 'care_plan_enabled' => true]);
    $schedule = ClientReportSchedule::create([
        'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
        'delivery_mode' => ClientReportSchedule::MODE_AUTO, 'recipients' => ['client-one@example.com', 'client-two@example.com'],
        'is_enabled' => true, 'next_run_at' => Carbon::parse('2027-01-01'),
    ]);

    return ClientReport::create(array_merge([
        'site_id' => $site->id,
        'schedule_id' => $schedule->id,
        'title' => 'Test send',
        'period_start' => Carbon::parse('2026-10-01'),
        'period_end' => Carbon::parse('2026-10-31'),
        'sections_data' => app(ClientReportCompiler::class)->compile($site, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')),
        'client_email' => 'client-one@example.com, client-two@example.com',
        'status' => ClientReport::STATUS_GENERATED,
    ], $attributes));
}

describe('send test to me', function () {
    it('needs a login', function () {
        Mail::fake();
        $this->post(route('client-reports.send-test', testSendReport()))->assertRedirect(route('login'));
        Mail::assertNothingSent();
    });

    it('emails the exact client email to the operator only, marked [TEST], and changes nothing', function () {
        Mail::fake();
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $report = testSendReport();
        $before = $report->fresh()->only(['status', 'sent_to', 'sent_at', 'client_email', 'sections_data', 'updated_at']);
        $schedule = $report->schedule;
        $expectedSubject = (new ClientReportMail($report))->envelope()->subject;

        $this->actingAs($operator)->post(route('client-reports.send-test', $report))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'operator@example.com only'));

        Mail::assertSent(ClientReportTestMail::class, 1);
        Mail::assertSent(ClientReportTestMail::class, function (ClientReportTestMail $mail) use ($report, $expectedSubject) {
            return $mail->hasTo('operator@example.com')
                && count($mail->to) === 1 && $mail->cc === [] && $mail->bcc === []
                && $mail->report->is($report)
                && $mail->envelope()->subject === '[TEST] '.$expectedSubject;
        });
        // Never to a client address.
        Mail::assertNotSent(ClientReportMail::class, fn ($mail) => $mail->hasTo('client-one@example.com') || $mail->hasTo('client-two@example.com'));

        expect($report->fresh()->only(['status', 'sent_to', 'sent_at', 'client_email', 'sections_data', 'updated_at']))->toEqual($before)
            ->and($schedule->fresh()->last_sent_at)->toBeNull()
            ->and($schedule->fresh()->next_run_at->toDateString())->toBe('2027-01-01');
    });

    it('works for any report, even one that may not be sent for real, without touching it', function () {
        Mail::fake();
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $outdated = testSendReport(['sections_data' => ['meta' => ['schema_version' => 1]]]);
        $replaced = testSendReport(['status' => ClientReport::STATUS_SUPERSEDED]);

        $this->actingAs($operator)->post(route('client-reports.send-test', $outdated))->assertSessionHas('status');
        $this->actingAs($operator)->post(route('client-reports.send-test', $replaced))->assertSessionHas('status');

        Mail::assertSent(ClientReportTestMail::class, 2);
        Mail::assertSent(ClientReportTestMail::class, fn ($mail) => $mail->hasTo('operator@example.com'));
        expect($outdated->fresh()->status)->toBe(ClientReport::STATUS_GENERATED)
            ->and($replaced->fresh()->status)->toBe(ClientReport::STATUS_SUPERSEDED);
    });

    it('says so when the test email fails, and still changes nothing', function () {
        Mail::shouldReceive('to')->once()->with('operator@example.com')->andThrow(new RuntimeException('Example transport failure'));
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $report = testSendReport();

        $this->actingAs($operator)->post(route('client-reports.send-test', $report))
            ->assertSessionHas('status_error', fn (string $m) => str_contains($m, 'Example transport failure'));

        expect($report->fresh()->status)->toBe(ClientReport::STATUS_GENERATED)
            ->and($report->fresh()->sent_to)->toBeNull()
            ->and($report->schedule->fresh()->last_sent_at)->toBeNull();
    });
});

describe('one-click schedules', function () {
    beforeEach(function () {
        Carbon::setTestNow('2026-11-05 10:00:00');
        Mail::fake();
        $this->user = User::factory()->create();
    });

    afterEach(fn () => Carbon::setTestNow());

    it('creates a monthly auto schedule to the client\'s contacts, sending nothing', function () {
        $client = Client::create(['name' => 'Quick Example', 'email' => 'quick@example.com', 'additional_emails' => ['also@example.com']]);
        $site = Site::factory()->create(['domain' => 'quick.example.com', 'client_id' => $client->id, 'care_plan_enabled' => true]);

        $this->actingAs($this->user)->post(route('client-reports.schedules.quick', $site))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'quick@example.com, also@example.com'));

        $schedule = ClientReportSchedule::where('site_id', $site->id)->sole();
        expect($schedule->delivery_mode)->toBe(ClientReportSchedule::MODE_AUTO)
            ->and($schedule->frequency)->toBe(ClientReportSchedule::FREQUENCY_MONTHLY)
            ->and($schedule->recipients)->toBe(['quick@example.com', 'also@example.com'])
            ->and($schedule->is_enabled)->toBeTrue()
            ->and($schedule->last_sent_at)->toBeNull()
            ->and($schedule->next_run_at->toDateString())->toBe('2026-12-01');
        Mail::assertNothingSent();
        expect(ClientReport::count())->toBe(0);
    });

    it('creates a draft schedule when there are no contacts to send to', function () {
        $site = Site::factory()->create(['domain' => 'nocontacts.example.com', 'client_id' => null, 'client_email' => null, 'care_plan_enabled' => true]);

        $this->actingAs($this->user)->post(route('client-reports.schedules.quick', $site))->assertSessionHas('status');

        $schedule = ClientReportSchedule::where('site_id', $site->id)->sole();
        expect($schedule->delivery_mode)->toBe(ClientReportSchedule::MODE_DRAFT)
            ->and($schedule->recipients)->toBe([]);
    });

    it('refuses a site with no care plan, an inactive site, and a site that already has one', function () {
        $noCare = Site::factory()->create(['care_plan_enabled' => false]);
        $inactive = Site::factory()->create(['care_plan_enabled' => true, 'is_inactive' => true]);
        $scheduled = Site::factory()->create(['care_plan_enabled' => true]);
        ClientReportSchedule::create(['site_id' => $scheduled->id, 'frequency' => 'monthly', 'delivery_mode' => 'draft', 'recipients' => [], 'is_enabled' => false]);

        $this->actingAs($this->user);
        $this->post(route('client-reports.schedules.quick', $noCare))->assertSessionHas('status_error');
        $this->post(route('client-reports.schedules.quick', $inactive))->assertSessionHas('status_error');
        $this->post(route('client-reports.schedules.quick', $scheduled))->assertSessionHas('status', fn (string $m) => str_contains($m, 'already has'));

        expect(ClientReportSchedule::count())->toBe(1)
            ->and(ClientReportSchedule::first()->is_enabled)->toBeFalse();
    });

    it('creates one for every active care-plan site without one, and nothing else', function () {
        $a = Site::factory()->create(['care_plan_enabled' => true]);
        $b = Site::factory()->create(['care_plan_enabled' => true]);
        $noCare = Site::factory()->create(['care_plan_enabled' => false]);
        $inactive = Site::factory()->create(['care_plan_enabled' => true, 'is_inactive' => true]);

        $this->actingAs($this->user)->post(route('client-reports.schedules.quick-all'))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Created 2 monthly report schedule(s)') && str_contains($m, 'Nothing was sent.'));

        expect(ClientReportSchedule::pluck('site_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
        Mail::assertNothingSent();
    });

    it('lets the client page toggle a schedule in place', function () {
        $site = Site::factory()->create(['care_plan_enabled' => true]);
        $schedule = ClientReportSchedule::create(['site_id' => $site->id, 'frequency' => 'monthly', 'delivery_mode' => 'auto', 'recipients' => ['a@example.com'], 'is_enabled' => true]);

        $this->actingAs($this->user)
            ->from('/client-reports/clients/unassigned')
            ->patch(route('client-reports.schedules.toggle', $schedule), ['return' => 'back'])
            ->assertRedirect('/client-reports/clients/unassigned');

        expect($schedule->fresh()->is_enabled)->toBeFalse();
    });
});
