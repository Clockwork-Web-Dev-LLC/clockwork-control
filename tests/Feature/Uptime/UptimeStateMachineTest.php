<?php

namespace Tests\Feature\Uptime;

use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Services\Chat\ChatNotifier;
use App\Services\Uptime\UptimeDiagnostician;
use App\Services\Uptime\UptimeProbeResult;
use App\Services\Uptime\UptimeStateUpdater;
use Illuminate\Support\Facades\Http;
use Modules\Core\Contracts\SmsNotifier;
use Modules\Twilio\TwilioClient;

/**
 * Characterization + regression tests for UptimeStateUpdater — the state
 * machine that decides whether "site went down" alerts fire. Feeds real
 * UptimeProbeResult value objects (its actual input boundary) into a
 * container-resolved updater, with ChatNotifier (Mattermost) and
 * SmsNotifier mocked as collaborators so we can assert exactly which
 * notification channels fired per scenario.
 *
 * Verified against app/Services/Uptime/UptimeStateUpdater.php as of this
 * writing:
 *   - Failure threshold: UptimeStateUpdater::FAILURE_THRESHOLD_FOR_DOWN = 2,
 *     read through Settings::get('monitoring.uptime_failure_threshold', 2).
 *     No AppSetting row exists in these tests, so the fallback constant (2)
 *     is what's actually exercised.
 *   - down → up recovery is instant: a single success flips state, no streak.
 *   - fireDownNotification()/fireRecoveryNotification() call
 *     ChatNotifier and SmsNotifier as two separate, independently
 *     try/caught calls — SmsNotifier is always invoked by
 *     UptimeStateUpdater; the actual care_plan_enabled gate lives one
 *     layer down, inside SmsNotifier::siteWentDown()/siteWentUp()
 *     (`if (! $site->care_plan_enabled) { return false; }`), not in
 *     UptimeStateUpdater itself.
 *   - uptime_ignored_at suppresses notifications on both directions but the
 *     state column, uptime_down_since, and the SiteUptimeEvent row are still
 *     written (Site::isUptimeIgnored() docblock: "probe still runs + state
 *     still recorded").
 *   - uptime_monitoring_enabled=false is NOT checked anywhere inside
 *     UptimeStateUpdater::update(). It's enforced one layer up, in
 *     App\Console\Commands\CheckSiteUptime::handle()'s site query
 *     (`->where('uptime_monitoring_enabled', true)`) — a disabled site is
 *     never even fetched, so it's never handed to the updater at all.
 */
describe('UptimeStateUpdater', function () {
    it('does not flip to down after a single failure below the threshold', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'up',
            'uptime_consecutive_failures' => 0,
            'uptime_down_since' => null,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldNotReceive('siteWentDown');
        $chat->shouldNotReceive('siteWentUp');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldNotReceive('siteWentDown');
        $sms->shouldNotReceive('siteWentUp');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::badStatus(500, 120, 'HTTP 500'));

        $site->refresh();

        expect($site->uptime_state)->toBe('up')
            ->and($site->uptime_consecutive_failures)->toBe(1)
            ->and($site->uptime_down_since)->toBeNull();

        expect(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0);
    });

    it('flips to down once the failure threshold is reached, recording the event and firing both channels', function () {
        // Fresh site — default factory state is 'unknown', so this also
        // exercises the docblock's "unknown -> down: 2 failures, but emits
        // a first-detected-down event" branch, not just "up -> down".
        $site = Site::factory()->create([
            'uptime_state' => 'unknown',
            'uptime_consecutive_failures' => 0,
            'uptime_down_since' => null,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldReceive('siteWentDown')
            ->once()
            ->with(
                \Mockery::on(fn ($arg) => $arg instanceof Site && $arg->id === $site->id),
                500,
                'HTTP 500',
                false,
                \Mockery::type('array'),
            )
            ->andReturn(true);
        $chat->shouldNotReceive('siteWentUp');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldReceive('siteWentDown')
            ->once()
            ->with(
                \Mockery::on(fn ($arg) => $arg instanceof Site && $arg->id === $site->id),
                500,
                'HTTP 500',
            )
            ->andReturn(true);
        $sms->shouldNotReceive('siteWentUp');

        $updater = app(UptimeStateUpdater::class);

        // Failure #1 — below threshold, must stay quiet.
        $updater->update($site, UptimeProbeResult::badStatus(500, 120, 'HTTP 500'));
        $site->refresh();
        expect($site->uptime_state)->toBe('unknown')
            ->and($site->uptime_consecutive_failures)->toBe(1);

        // Failure #2 — crosses FAILURE_THRESHOLD_FOR_DOWN (2), must flip + alert.
        $updater->update($site, UptimeProbeResult::badStatus(500, 120, 'HTTP 500'));
        $site->refresh();

        expect($site->uptime_state)->toBe('down')
            ->and($site->uptime_consecutive_failures)->toBe(2)
            ->and($site->uptime_down_since)->not->toBeNull();

        $event = SiteUptimeEvent::where('site_id', $site->id)->first();
        expect($event)->not->toBeNull()
            ->and($event->event_type)->toBe(SiteUptimeEvent::TYPE_DOWN)
            ->and($event->status_code)->toBe(500);
    });

    it('flips a down site back to up on a single success, clears down_since, and fires both recovery channels', function () {
        $downSince = now()->subMinutes(15);
        $site = Site::factory()->create([
            'uptime_state' => 'down',
            'uptime_consecutive_failures' => 3,
            'uptime_down_since' => $downSince,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldNotReceive('siteWentDown');
        $chat->shouldReceive('siteWentUp')
            ->once()
            ->with(
                \Mockery::on(fn ($arg) => $arg instanceof Site && $arg->id === $site->id),
                \Mockery::on(fn ($sec) => $sec >= 890 && $sec <= 910),
            )
            ->andReturn(true);

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldNotReceive('siteWentDown');
        $sms->shouldReceive('siteWentUp')
            ->once()
            ->with(
                \Mockery::on(fn ($arg) => $arg instanceof Site && $arg->id === $site->id),
                \Mockery::on(fn ($sec) => $sec >= 890 && $sec <= 910),
            )
            ->andReturn(true);

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::success(200, 80));

        $site->refresh();

        expect($site->uptime_state)->toBe('up')
            ->and($site->uptime_consecutive_failures)->toBe(0)
            ->and($site->uptime_down_since)->toBeNull()
            ->and($site->uptime_last_up_at)->not->toBeNull();

        $event = SiteUptimeEvent::where('site_id', $site->id)->first();
        expect($event)->not->toBeNull()
            ->and($event->event_type)->toBe(SiteUptimeEvent::TYPE_UP);
    });

    it('suppresses down notifications for an ignored site while still recording the state transition', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'up',
            'uptime_consecutive_failures' => 1, // one away from threshold
            'uptime_down_since' => null,
            'uptime_ignored_at' => now(),
            'uptime_ignore_reason' => 'client aware, paused deliberately',
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldNotReceive('siteWentDown');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldNotReceive('siteWentDown');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::badStatus(503, 90, 'HTTP 503'));

        $site->refresh();

        // State machine still does its job internally...
        expect($site->uptime_state)->toBe('down')
            ->and($site->uptime_down_since)->not->toBeNull();

        $event = SiteUptimeEvent::where('site_id', $site->id)->first();
        expect($event)->not->toBeNull()
            ->and($event->event_type)->toBe(SiteUptimeEvent::TYPE_DOWN);

        // ...it just never told anyone (assertions above via shouldNotReceive).
    });

    it('suppresses recovery notifications for an ignored site while still recording the state transition', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'down',
            'uptime_consecutive_failures' => 2,
            'uptime_down_since' => now()->subMinutes(5),
            'uptime_ignored_at' => now()->subDay(),
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldNotReceive('siteWentUp');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldNotReceive('siteWentUp');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::success(200, 60));

        $site->refresh();

        expect($site->uptime_state)->toBe('up')
            ->and($site->uptime_down_since)->toBeNull();

        $event = SiteUptimeEvent::where('site_id', $site->id)->first();
        expect($event)->not->toBeNull()
            ->and($event->event_type)->toBe(SiteUptimeEvent::TYPE_UP);
    });

    it('fires no notification and records no event on the very first successful probe (unknown -> up)', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'unknown',
            'uptime_consecutive_failures' => 0,
            'uptime_down_since' => null,
            'uptime_last_up_at' => null,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldNotReceive('siteWentDown');
        $chat->shouldNotReceive('siteWentUp');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldNotReceive('siteWentDown');
        $sms->shouldNotReceive('siteWentUp');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::success(200, 50));

        $site->refresh();

        expect($site->uptime_state)->toBe('up')
            ->and($site->uptime_last_up_at)->not->toBeNull();

        // No SiteUptimeEvent row — first-ever success isn't a "recovery".
        expect(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0);
    });

    it('gates the SMS channel on care_plan_enabled: a non-care-plan site never reaches Twilio', function () {
        $site = Site::factory()->create([
            'care_plan_enabled' => false,
            'uptime_state' => 'unknown',
            'uptime_consecutive_failures' => 1,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldReceive('siteWentDown')->once()->andReturn(true);

        // Real SmsNotifier (not mocked) — only TwilioClient is a mock, so we
        // can prove the care_plan_enabled gate is enforced INSIDE SmsNotifier
        // before it ever touches Twilio, not by UptimeStateUpdater skipping
        // the call.
        $twilio = test()->mock(TwilioClient::class);
        $twilio->shouldNotReceive('isConfigured');
        $twilio->shouldNotReceive('sms');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::badStatus(500, 100, 'HTTP 500'));

        $site->refresh();
        expect($site->uptime_state)->toBe('down');
    });

    it('opens the SMS channel gate for a care-plan site: it reaches TwilioClient before failing for lack of config', function () {
        $site = Site::factory()->create([
            'care_plan_enabled' => true,
            'uptime_state' => 'unknown',
            'uptime_consecutive_failures' => 1,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldReceive('siteWentDown')->once()->andReturn(true);

        // care_plan_enabled=true means SmsNotifier's gate opens and it
        // proceeds to ask Twilio whether it's configured (test env has no
        // Twilio credentials, so this call legitimately returns false and
        // dispatch() falls back to email/Mattermost — see SmsNotifier).
        $twilio = test()->mock(TwilioClient::class);
        $twilio->shouldReceive('isConfigured')->once()->andReturn(false);
        $twilio->shouldNotReceive('sms');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::badStatus(500, 100, 'HTTP 500'));

        $site->refresh();
        expect($site->uptime_state)->toBe('down');
    });

    it('transitions a site from up to maintenance, records the maintenance event, notifies chat, and suppresses SMS', function () {
        $site = Site::factory()->create([
            'care_plan_enabled' => true,
            'uptime_state' => 'up',
            'uptime_consecutive_failures' => 0,
            'uptime_maintenance_since' => null,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldReceive('siteEnteredMaintenance')
            ->once()
            ->with(
                \Mockery::on(fn ($arg) => $arg instanceof Site && $arg->id === $site->id),
                503,
                \Mockery::type('string'),
                '600',
            )
            ->andReturn(true);
        $chat->shouldNotReceive('siteWentDown');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldNotReceive('siteWentDown');

        $updater = app(UptimeStateUpdater::class);
        $probe = UptimeProbeResult::maintenance(503, 150, 'HTTP 503 (scheduled maintenance)', '600');
        $updater->update($site, $probe);

        $site->refresh();

        expect($site->uptime_state)->toBe('maintenance')
            ->and($site->uptime_maintenance_since)->not->toBeNull()
            ->and($site->uptime_down_since)->toBeNull()
            ->and($site->uptime_consecutive_failures)->toBe(0);

        $event = SiteUptimeEvent::where('site_id', $site->id)->first();
        expect($event)->not->toBeNull()
            ->and($event->event_type)->toBe(SiteUptimeEvent::TYPE_MAINTENANCE)
            ->and($event->status_code)->toBe(503);
    });

    it('transitions a site from maintenance to up, clears maintenance timestamp, and notifies chat of exit', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subMinutes(15),
            'uptime_down_since' => null,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldReceive('siteExitedMaintenance')
            ->once()
            ->with(
                \Mockery::on(fn ($arg) => $arg instanceof Site && $arg->id === $site->id),
                \Mockery::type('int'),
            )
            ->andReturn(true);
        $chat->shouldNotReceive('siteWentUp');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldNotReceive('siteWentUp');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::success(200, 100));

        $site->refresh();

        expect($site->uptime_state)->toBe('up')
            ->and($site->uptime_maintenance_since)->toBeNull()
            ->and($site->uptime_down_since)->toBeNull();

        $event = SiteUptimeEvent::where('site_id', $site->id)->latest('id')->first();
        expect($event)->not->toBeNull()
            ->and($event->event_type)->toBe(SiteUptimeEvent::TYPE_UP);
    });

    it('pivots a bare 503 failure to maintenance state when SSH diagnostician confirms maintenance mode', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'unknown',
            'uptime_consecutive_failures' => 1,
            'uptime_down_since' => null,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldReceive('siteEnteredMaintenance')->once()->andReturn(true);
        $chat->shouldNotReceive('siteWentDown');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldNotReceive('siteWentDown');

        $diagnostician = $this->mock(UptimeDiagnostician::class);
        $diagnostician->shouldReceive('diagnose')
            ->once()
            ->andReturn([
                'maintenance_mode' => true,
                'maintenance_excerpt' => 'WordPress .maintenance file is active',
                'summary' => 'Maintenance mode is active',
            ]);

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::badStatus(503, 100, 'HTTP 503'));

        $site->refresh();

        expect($site->uptime_state)->toBe('maintenance')
            ->and($site->uptime_maintenance_since)->not->toBeNull()
            ->and($site->uptime_down_since)->toBeNull();

        $event = SiteUptimeEvent::where('site_id', $site->id)->first();
        expect($event)->not->toBeNull()
            ->and($event->event_type)->toBe(SiteUptimeEvent::TYPE_MAINTENANCE);
    });

    it('does not re-notify when a site already in maintenance keeps returning a bare 503 that SSH still confirms', function () {
        $since = now()->subMinutes(20);
        $site = Site::factory()->create([
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => $since,
            'uptime_consecutive_failures' => 1,
            'uptime_down_since' => null,
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldNotReceive('siteEnteredMaintenance');
        $chat->shouldNotReceive('siteWentDown');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldNotReceive('siteWentDown');

        $diagnostician = $this->mock(UptimeDiagnostician::class);
        $diagnostician->shouldReceive('diagnose')
            ->once()
            ->andReturn([
                'maintenance_mode' => true,
                'maintenance_excerpt' => 'return 503;',
                'summary' => 'Maintenance mode is active',
            ]);

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::badStatus(503, 100, 'HTTP 503'));

        $site->refresh();

        expect($site->uptime_state)->toBe('maintenance')
            ->and($site->uptime_maintenance_since?->timestamp)->toBe($since->timestamp)
            ->and(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0);
    });
});

describe('CheckSiteUptime command (uptime_monitoring_enabled gate)', function () {
    it('skips uptime_monitoring_enabled=false sites entirely — they are never probed or updated', function () {
        Http::fake(['*' => Http::response('ok', 200)]);

        $enabled = Site::factory()->create([
            'domain' => 'enabled-site.example.test',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'unknown',
        ]);
        $disabled = Site::factory()->create([
            'domain' => 'disabled-site.example.test',
            'uptime_monitoring_enabled' => false,
            'uptime_state' => 'unknown',
        ]);

        test()->artisan('clockwork:check-site-uptime')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'enabled-site.example.test'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'disabled-site.example.test'));

        $enabled->refresh();
        $disabled->refresh();

        // The enabled site was actually run through the state machine.
        expect($enabled->uptime_last_checked_at)->not->toBeNull()
            ->and($enabled->uptime_state)->toBe('up');

        // The disabled site was never fetched by CheckSiteUptime's query at
        // all, so UptimeStateUpdater::update() never ran against it —
        // untouched columns prove the gate lives at the command layer, not
        // inside UptimeStateUpdater.
        expect($disabled->uptime_last_checked_at)->toBeNull()
            ->and($disabled->uptime_state)->toBe('unknown');
    });
});
