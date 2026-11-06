<?php

namespace Tests\Feature\Uptime;

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Models\User;
use App\Services\Chat\ChatNotifier;
use App\Services\Uptime\UptimeProber;
use App\Services\Uptime\UptimeProbeResult;
use App\Services\Uptime\UptimeStateUpdater;
use App\Services\Uptime\UptimeStatsCalculator;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Modules\ClientSlack\ClientSlackNotifier;
use Modules\Core\Contracts\SmsNotifier;
use Modules\Mattermost\MattermostNotifier;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/**
 * HTTP 429 from a monitored site means a rate limiter or anti-bot challenge
 * refused the probe, not that the site is down. Covers the whole path:
 * UptimeProber classifies it as "blocked", UptimeStateUpdater keeps it out
 * of the failure streak / down transition / last-up timestamp and records a
 * probe_blocked state + event, a long blocked stretch sends exactly one ops
 * chat notice, and the stats calculator never counts it as downtime.
 */
function blockedProbe(?string $retryAfter = null): UptimeProbeResult
{
    return UptimeProbeResult::blocked(429, 150, 'HTTP 429 (rate-limited — probe blocked)', $retryAfter);
}

/**
 * Mocks both alert channels so no test can page anyone by accident, and
 * forbids the outage alerts outright — a blocked probe must never send them.
 */
function quietUptimeChannels(): MockInterface
{
    $chat = test()->mock(ChatNotifier::class);
    $chat->shouldNotReceive('siteWentDown');
    $chat->shouldNotReceive('siteWentUp');

    $sms = test()->mock(SmsNotifier::class);
    $sms->shouldNotReceive('siteWentDown');
    $sms->shouldNotReceive('siteWentUp');

    return $chat;
}

describe('UptimeProber on HTTP 429', function () {
    it('returns a blocked result that is neither a success nor a plain failure, keeping Retry-After', function () {
        Http::fake([
            'https://blocked.example.com/' => Http::response('Too Many Requests', 429, ['Retry-After' => '120']),
        ]);

        $result = (new UptimeProber)->probe('https://blocked.example.com/');

        expect($result->isProbeBlocked)->toBeTrue()
            ->and($result->succeeded)->toBeFalse()
            ->and($result->isMaintenance)->toBeFalse()
            ->and($result->statusCode)->toBe(429)
            ->and($result->retryAfter)->toBe('120')
            ->and($result->error)->toContain('HTTP 429')
            ->and($result->error)->toContain('Retry-After: 120');
    });

    it('returns a blocked result without Retry-After when the header is absent', function () {
        Http::fake([
            'https://blocked.example.com/' => Http::response('Too Many Requests', 429),
        ]);

        $result = (new UptimeProber)->probe('https://blocked.example.com/');

        expect($result->isProbeBlocked)->toBeTrue()
            ->and($result->retryAfter)->toBeNull()
            ->and($result->error)->toBe('HTTP 429 (rate-limited — probe blocked)');
    });

    it('caps an oversized Retry-After header', function () {
        Http::fake([
            'https://blocked.example.com/' => Http::response('Too Many Requests', 429, ['Retry-After' => str_repeat('9', 400)]),
        ]);

        $result = (new UptimeProber)->probe('https://blocked.example.com/');

        expect(mb_strlen((string) $result->retryAfter))->toBe(64)
            ->and(mb_strlen((string) $result->error))->toBeLessThan(500);
    });

    it('still treats other 4xx responses as failures, not blocked probes', function () {
        Http::fake([
            'https://blocked.example.com/' => Http::response('Not Found', 404),
        ]);

        $result = (new UptimeProber)->probe('https://blocked.example.com/');

        expect($result->isProbeBlocked)->toBeFalse()
            ->and($result->succeeded)->toBeFalse()
            ->and($result->statusCode)->toBe(404);
    });
});

describe('UptimeStateUpdater with blocked probes', function () {
    it('moves an up site to probe_blocked after the threshold of 429s in a row, without touching the failure streak or last-up, dating the event at the first', function () {
        $lastUp = now()->subMinutes(10)->startOfSecond();
        $site = Site::factory()->create([
            'uptime_state' => 'up',
            'uptime_consecutive_failures' => 1,
            'uptime_last_up_at' => $lastUp,
            'uptime_down_since' => null,
        ]);

        $chat = quietUptimeChannels();
        $chat->shouldNotReceive('uptimeProbeBlocked');

        $firstAt = now()->startOfSecond();
        app(UptimeStateUpdater::class)->update($site, blockedProbe('120'));
        expect($site->refresh()->uptime_state)->toBe('up')
            ->and(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0);

        $this->travel(5)->minutes();
        app(UptimeStateUpdater::class)->update($site, blockedProbe('120'));
        $site->refresh();

        expect($site->uptime_state)->toBe(Site::UPTIME_STATE_PROBE_BLOCKED)
            ->and($site->uptime_consecutive_failures)->toBe(1)
            ->and($site->uptime_last_up_at?->timestamp)->toBe($lastUp->timestamp)
            ->and($site->uptime_down_since)->toBeNull()
            ->and($site->uptime_probe_blocked_since)->not->toBeNull()
            ->and($site->uptime_probe_blocked_alerted_at)->toBeNull()
            ->and($site->uptime_last_status_code)->toBe(429)
            ->and($site->uptime_last_checked_at)->not->toBeNull()
            ->and($site->healthColor())->toBe('yellow');

        $events = SiteUptimeEvent::where('site_id', $site->id)->get();
        expect($events)->toHaveCount(1)
            ->and($events->first()->event_type)->toBe(SiteUptimeEvent::TYPE_PROBE_BLOCKED)
            ->and($events->first()->status_code)->toBe(429)
            ->and($events->first()->event_at->timestamp)->toBe($firstAt->timestamp)
            ->and($events->first()->is_sla_exempt)->toBeFalse()
            ->and($site->uptime_probe_blocked_since?->timestamp)->toBe($firstAt->timestamp);

        $log = ActionLog::where('site_id', $site->id)->where('target', Site::UPTIME_STATE_PROBE_BLOCKED)->sole();
        expect($log->ran_at->timestamp)->toBe($firstAt->timestamp);
    });

    it('leaves no trace of a single 429 followed by a success', function () {
        $site = Site::factory()->create(['uptime_state' => 'up', 'uptime_consecutive_failures' => 0]);

        quietUptimeChannels()->shouldNotReceive('uptimeProbeBlocked');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, blockedProbe());
        $this->travel(5)->minutes();
        $updater->update($site, UptimeProbeResult::success(200, 80));
        $site->refresh();

        expect($site->uptime_state)->toBe('up')
            ->and($site->uptime_consecutive_failures)->toBe(0)
            ->and($site->uptime_consecutive_blocked)->toBe(0)
            ->and($site->uptime_probe_blocked_since)->toBeNull()
            ->and(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0)
            ->and(ActionLog::where('site_id', $site->id)->count())->toBe(0);
    });

    it('does not block on 429s broken up by another answer', function () {
        $site = Site::factory()->create(['uptime_state' => 'up', 'uptime_consecutive_failures' => 0]);

        quietUptimeChannels();

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, blockedProbe());
        $this->travel(5)->minutes();
        $updater->update($site, UptimeProbeResult::transportFailed('Connection timed out'));
        $this->travel(5)->minutes();
        $updater->update($site, blockedProbe());
        $site->refresh();

        expect($site->uptime_state)->toBe('up')
            ->and($site->uptime_consecutive_blocked)->toBe(1)
            ->and(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0);
    });

    it('blocks on the first 429 when the threshold is set to 1', function () {
        app(Settings::class)->put('monitoring.uptime_failure_threshold', 1);
        $site = Site::factory()->create(['uptime_state' => 'up']);

        quietUptimeChannels();

        app(UptimeStateUpdater::class)->update($site, blockedProbe());

        expect($site->refresh()->uptime_state)->toBe(Site::UPTIME_STATE_PROBE_BLOCKED);
    });

    it('never takes a site down however many 429s arrive in a row, and records the stretch once', function () {
        $site = Site::factory()->create(['uptime_state' => 'up', 'uptime_consecutive_failures' => 0]);

        $chat = quietUptimeChannels();
        $chat->shouldNotReceive('uptimeProbeBlocked');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, blockedProbe());
        $site->refresh();
        $blockedSince = $site->uptime_probe_blocked_since;
        expect($blockedSince)->not->toBeNull();

        for ($i = 0; $i < 10; $i++) {
            $this->travel(10)->minutes();
            $updater->update($site, blockedProbe());
        }
        $site->refresh();

        expect($site->uptime_state)->toBe(Site::UPTIME_STATE_PROBE_BLOCKED)
            ->and($site->uptime_consecutive_failures)->toBe(0)
            ->and($site->uptime_down_since)->toBeNull()
            ->and($site->uptime_probe_blocked_since?->timestamp)->toBe($blockedSince->timestamp)
            ->and(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(1);
    });

    it('does not set last-up for a site first seen blocked', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'unknown',
            'uptime_last_up_at' => null,
        ]);

        quietUptimeChannels();

        app(UptimeStateUpdater::class)->update($site, blockedProbe());
        $this->travel(5)->minutes();
        app(UptimeStateUpdater::class)->update($site, blockedProbe());
        $site->refresh();

        expect($site->uptime_state)->toBe(Site::UPTIME_STATE_PROBE_BLOCKED)
            ->and($site->uptime_last_up_at)->toBeNull();
    });

    it('keeps an outage open through a 429, so a later failure is the same outage', function () {
        $downSince = now()->subMinutes(30)->startOfSecond();
        $site = Site::factory()->create([
            'uptime_state' => 'down',
            'uptime_consecutive_failures' => 3,
            'uptime_down_since' => $downSince,
        ]);

        quietUptimeChannels();
        $updater = app(UptimeStateUpdater::class);

        $updater->update($site, blockedProbe());
        $site->refresh();
        expect($site->uptime_state)->toBe('down')
            ->and($site->uptime_down_since?->timestamp)->toBe($downSince->timestamp)
            ->and($site->uptime_consecutive_failures)->toBe(3)
            ->and($site->uptime_last_status_code)->toBe(429);

        // 502, 502 after it: no new down event, no second alert.
        $this->travel(5)->minutes();
        $updater->update($site, UptimeProbeResult::badStatus(502, 90, 'HTTP 502'));
        $this->travel(5)->minutes();
        $updater->update($site, UptimeProbeResult::badStatus(502, 90, 'HTTP 502'));
        $site->refresh();

        expect($site->uptime_state)->toBe('down')
            ->and($site->uptime_down_since?->timestamp)->toBe($downSince->timestamp)
            ->and($site->uptime_probe_blocked_since)->toBeNull()
            ->and($site->uptime_consecutive_blocked)->toBe(0)
            ->and(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0);
    });

    it('recovers a down site normally when a success follows a 429, with the full downtime', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'down',
            'uptime_consecutive_failures' => 2,
            'uptime_down_since' => now()->subMinutes(30),
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldReceive('siteWentUp')->once()
            ->withArgs(fn (Site $s, ?int $sec) => $s->is($site) && $sec >= 35 * 60)
            ->andReturn(true);
        $chat->shouldNotReceive('siteWentDown');
        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldReceive('siteWentUp')->once()->andReturn(false);

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, blockedProbe());
        $this->travel(5)->minutes();
        $updater->update($site, UptimeProbeResult::success(200, 80));

        expect($site->refresh()->uptime_state)->toBe('up')
            ->and(SiteUptimeEvent::where('site_id', $site->id)->pluck('event_type')->all())->toBe([SiteUptimeEvent::TYPE_UP]);
    });

    it('alerts once for a real outage that the host rate-limits now and then (502, 502, 429, 502, 502, 429, ...)', function () {
        $site = Site::factory()->create(['uptime_state' => 'up', 'uptime_consecutive_failures' => 0]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldReceive('siteWentDown')->once()->andReturn(true);
        $chat->shouldNotReceive('siteWentUp');
        $chat->shouldNotReceive('uptimeProbeBlocked');
        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldReceive('siteWentDown')->once()->andReturn(false);
        $sms->shouldNotReceive('siteWentUp');

        $updater = app(UptimeStateUpdater::class);
        foreach ([502, 502, 429, 502, 502, 429, 502, 502, 429, 502, 502] as $code) {
            $updater->update($site, $code === 429 ? blockedProbe() : UptimeProbeResult::badStatus($code, 90, "HTTP {$code}"));
            $this->travel(5)->minutes();
        }

        expect($site->refresh()->uptime_state)->toBe('down')
            ->and(SiteUptimeEvent::where('site_id', $site->id)->pluck('event_type')->all())->toBe([SiteUptimeEvent::TYPE_DOWN]);
    });

    it('closes an open outage at the first 429 once a down site has only answered 429 for the configured hours, without claiming recovery', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'down',
            'uptime_consecutive_failures' => 3,
            'uptime_down_since' => now()->subMinutes(30),
            'uptime_last_up_at' => now()->subMinutes(40),
        ]);
        $lastUp = $site->uptime_last_up_at;
        $firstAt = now()->startOfSecond();

        $chat = quietUptimeChannels();
        $chat->shouldReceive('uptimeProbeBlocked')->once()->andReturn(true);

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, blockedProbe());
        for ($i = 0; $i < 11; $i++) {
            $this->travel(30)->minutes();
            $updater->update($site, blockedProbe());
        }
        expect($site->refresh()->uptime_state)->toBe('down');

        // Six hours of nothing but 429s.
        $this->travel(30)->minutes();
        $updater->update($site, blockedProbe());
        $site->refresh();

        expect($site->uptime_state)->toBe(Site::UPTIME_STATE_PROBE_BLOCKED)
            ->and($site->uptime_down_since)->toBeNull()
            ->and($site->uptime_consecutive_failures)->toBe(0)
            ->and($site->uptime_last_up_at?->timestamp)->toBe($lastUp->timestamp);

        $event = SiteUptimeEvent::where('site_id', $site->id)->sole();
        expect($event->event_type)->toBe(SiteUptimeEvent::TYPE_PROBE_BLOCKED)
            ->and($event->event_at->timestamp)->toBe($firstAt->timestamp);

        $log = ActionLog::where('site_id', $site->id)->where('target', Site::UPTIME_STATE_PROBE_BLOCKED)->first();
        expect($log)->not->toBeNull()
            ->and($log->summary)->toContain('outage closed after 30 min')
            ->and($log->summary)->toContain('recovery not confirmed');
    });

    it('keeps a site in maintenance through a 429, without a second maintenance notice', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subMinutes(20),
        ]);

        $chat = quietUptimeChannels();
        $chat->shouldNotReceive('siteExitedMaintenance');
        $chat->shouldNotReceive('siteEnteredMaintenance');

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, blockedProbe());
        expect($site->refresh()->uptime_state)->toBe('maintenance')
            ->and($site->uptime_maintenance_since)->not->toBeNull();

        $this->travel(5)->minutes();
        $updater->update($site, UptimeProbeResult::maintenance(503, 90, 'HTTP 503 (maintenance)'));

        expect($site->refresh()->uptime_state)->toBe('maintenance')
            ->and(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0);
    });

    it('moves a site in maintenance to probe_blocked only after the configured hours of 429s', function () {
        app(Settings::class)->put(UptimeStateUpdater::SETTING_PROBE_BLOCKED_ALERT_HOURS, 1);
        $site = Site::factory()->create([
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subMinutes(20),
        ]);

        $chat = quietUptimeChannels();
        $chat->shouldNotReceive('siteExitedMaintenance');
        $chat->shouldReceive('uptimeProbeBlocked')->once()->andReturn(true);

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, blockedProbe());
        $this->travel(1)->hours();
        $updater->update($site, blockedProbe());
        $site->refresh();

        expect($site->uptime_state)->toBe(Site::UPTIME_STATE_PROBE_BLOCKED)
            ->and($site->uptime_maintenance_since)->toBeNull();
    });

    it('returns a blocked site to up on the next success, closing the stretch quietly', function () {
        $site = Site::factory()->create([
            'uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED,
            'uptime_probe_blocked_since' => now()->subHours(7),
            'uptime_probe_blocked_alerted_at' => now()->subHour(),
            'uptime_last_up_at' => null,
        ]);

        $chat = quietUptimeChannels();
        $chat->shouldNotReceive('uptimeProbeBlocked');

        app(UptimeStateUpdater::class)->update($site, UptimeProbeResult::success(200, 80));
        $site->refresh();

        expect($site->uptime_state)->toBe('up')
            ->and($site->uptime_last_up_at)->not->toBeNull()
            ->and($site->uptime_probe_blocked_since)->toBeNull()
            ->and($site->uptime_probe_blocked_alerted_at)->toBeNull();

        expect(SiteUptimeEvent::where('site_id', $site->id)->value('event_type'))->toBe(SiteUptimeEvent::TYPE_UP);
    });

    it('keeps the blocked stretch open through a single failure below the threshold', function () {
        $since = now()->subHours(2)->startOfSecond();
        $site = Site::factory()->create([
            'uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED,
            'uptime_consecutive_failures' => 0,
            'uptime_probe_blocked_since' => $since,
        ]);

        quietUptimeChannels();

        app(UptimeStateUpdater::class)->update($site, UptimeProbeResult::transportFailed('Connection timed out'));
        $site->refresh();

        expect($site->uptime_state)->toBe(Site::UPTIME_STATE_PROBE_BLOCKED)
            ->and($site->uptime_consecutive_failures)->toBe(1)
            ->and($site->uptime_probe_blocked_since?->timestamp)->toBe($since->timestamp)
            ->and(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0);
    });

    it('still takes a blocked site down on a real outage, ending the blocked stretch', function () {
        $site = Site::factory()->create([
            'uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED,
            'uptime_consecutive_failures' => 0,
            'uptime_probe_blocked_since' => now()->subHours(2),
        ]);

        $chat = $this->mock(ChatNotifier::class);
        $chat->shouldReceive('siteWentDown')
            ->once()
            ->withArgs(fn (Site $s, ?int $code) => $s->is($site) && $code === 502)
            ->andReturn(true);
        $chat->shouldNotReceive('uptimeProbeBlocked');

        $sms = $this->mock(SmsNotifier::class);
        $sms->shouldReceive('siteWentDown')->once()->andReturn(false);

        $updater = app(UptimeStateUpdater::class);
        $updater->update($site, UptimeProbeResult::badStatus(502, 90, 'HTTP 502'));
        $updater->update($site, UptimeProbeResult::badStatus(502, 90, 'HTTP 502'));
        $site->refresh();

        expect($site->uptime_state)->toBe('down')
            ->and($site->uptime_down_since)->not->toBeNull()
            ->and($site->uptime_probe_blocked_since)->toBeNull()
            ->and(SiteUptimeEvent::where('site_id', $site->id)->value('event_type'))->toBe(SiteUptimeEvent::TYPE_DOWN);
    });
});

describe('probe-blocked ops notice', function () {
    it('sends one notice once the site has blocked the probe for the default 6 hours', function () {
        $site = Site::factory()->create(['uptime_state' => 'up']);

        $chat = quietUptimeChannels();
        $chat->shouldReceive('uptimeProbeBlocked')
            ->once()
            ->withArgs(fn (Site $s, int $sec, ?int $code, ?string $retryAfter) => $s->is($site)
                && $sec >= 6 * 3600
                && $code === 429
                && $retryAfter === '120')
            ->andReturn(true);

        $updater = app(UptimeStateUpdater::class);

        // Ten-minute cadence: nothing until the stretch reaches six hours...
        $updater->update($site, blockedProbe('120'));
        for ($i = 0; $i < 35; $i++) {
            $this->travel(10)->minutes();
            $updater->update($site, blockedProbe('120'));
        }
        expect($site->refresh()->uptime_probe_blocked_alerted_at)->toBeNull();

        // ...the probe at the six-hour mark sends it...
        $this->travel(10)->minutes();
        $updater->update($site, blockedProbe('120'));
        expect($site->refresh()->uptime_probe_blocked_alerted_at)->not->toBeNull();

        // ...and that stays the only notice, however long it keeps blocking.
        for ($i = 0; $i < 12; $i++) {
            $this->travel(10)->minutes();
            $updater->update($site, blockedProbe('120'));
        }

        expect(ActionLog::where('site_id', $site->id)->where('summary', 'like', '%ops notified%')->count())->toBe(1);
    });

    it('uses the hours configured on Monitoring settings', function () {
        app(Settings::class)->put(UptimeStateUpdater::SETTING_PROBE_BLOCKED_ALERT_HOURS, 2);

        $site = Site::factory()->create([
            'uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED,
            'uptime_probe_blocked_since' => now()->subHours(2)->subMinute(),
        ]);

        $chat = quietUptimeChannels();
        $chat->shouldReceive('uptimeProbeBlocked')->once()->andReturn(true);

        app(UptimeStateUpdater::class)->update($site, blockedProbe());
    });

    it('stays quiet below the configured hours', function () {
        $site = Site::factory()->create([
            'uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED,
            'uptime_probe_blocked_since' => now()->subHours(5)->subMinutes(50),
        ]);

        $chat = quietUptimeChannels();
        $chat->shouldNotReceive('uptimeProbeBlocked');

        app(UptimeStateUpdater::class)->update($site, blockedProbe());

        expect($site->refresh()->uptime_probe_blocked_alerted_at)->toBeNull();
    });

    it('respects the per-site ignore flag', function () {
        $site = Site::factory()->create([
            'uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED,
            'uptime_probe_blocked_since' => now()->subHours(8),
            'uptime_ignored_at' => now()->subDay(),
        ]);

        $chat = quietUptimeChannels();
        $chat->shouldNotReceive('uptimeProbeBlocked');

        app(UptimeStateUpdater::class)->update($site, blockedProbe());

        expect($site->refresh()->uptime_probe_blocked_alerted_at)->toBeNull();
    });

    it('can notify again for a new blocked stretch after the site got through', function () {
        $site = Site::factory()->create([
            'uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED,
            'uptime_probe_blocked_since' => now()->subHours(8),
            'uptime_probe_blocked_alerted_at' => now()->subHours(2),
        ]);

        $chat = quietUptimeChannels();
        $chat->shouldReceive('uptimeProbeBlocked')->once()->andReturn(true);

        $updater = app(UptimeStateUpdater::class);

        // Already notified for this stretch: silent.
        $updater->update($site, blockedProbe());

        // A success ends it; a fresh six-hour stretch notifies once more.
        $updater->update($site, UptimeProbeResult::success(200, 70));
        $updater->update($site, blockedProbe());
        $this->travel(6)->hours();
        $updater->update($site, blockedProbe());
    });

    it('is a gated chat event that names the site and the duration in generic terms', function () {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => 'https://chat.example.com/hooks/test',
        ]);
        Http::fake(['chat.example.com/*' => Http::response('ok', 200)]);

        $site = Site::factory()->create(['domain' => 'blocked.example.com']);

        expect(ChatNotifier::EVENTS)->toHaveKey('uptime_probe_blocked');

        $sent = app(MattermostNotifier::class)->uptimeProbeBlocked($site, 6 * 3600 + 600, 429, '120');

        expect($sent)->toBeTrue();
        Http::assertSent(function ($request) {
            $title = (string) $request['text'];
            $body = (string) ($request['attachments'][0]['text'] ?? '');

            return str_contains($title, 'blocked.example.com')
                && str_contains($title, 'HTTP 429')
                && str_contains($title, '6h 10m')
                && str_contains($body, 'not counted as downtime');
        });
    });

    it('is not sent when the event is switched off for the channel', function () {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => 'https://chat.example.com/hooks/test',
        ]);
        Http::fake();
        app(Settings::class)->put(MattermostNotifier::SETTINGS_KEY, ['uptime_probe_blocked' => false]);

        $site = Site::factory()->create();

        expect(app(MattermostNotifier::class)->uptimeProbeBlocked($site, 7 * 3600, 429))->toBeFalse();
        Http::assertNothingSent();
    });

    it('never reaches the client-facing Slack channel', function () {
        $site = Site::factory()->create();

        expect((new ClientSlackNotifier)->uptimeProbeBlocked($site, 7 * 3600, 429))->toBeFalse();
    });
});

describe('uptime stats with blocked stretches', function () {
    it('never counts a blocked stretch as downtime', function () {
        $site = Site::factory()->create(['uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED]);

        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_PROBE_BLOCKED,
            'status_code' => 429,
            'error' => 'HTTP 429 (rate-limited — probe blocked)',
            'event_at' => now()->subHours(20),
        ]);

        $pct = app(UptimeStatsCalculator::class)->siteUptime($site, now()->subDay(), now());

        expect($pct)->toBe(100.0);
    });

    it('stops counting an outage once the site answers 429', function () {
        $site = Site::factory()->create([
            'uptime_state' => 'down',
            'uptime_consecutive_failures' => 2,
            'uptime_down_since' => now()->subHours(12),
        ]);
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'status_code' => 502,
            'event_at' => now()->subHours(12),
        ]);

        quietUptimeChannels()->shouldReceive('uptimeProbeBlocked')->andReturn(true);

        // Down for 12h, then blocked for the following 12h: once the 429s
        // have lasted the configured hours, the outage ends at the first one.
        $updater = app(UptimeStateUpdater::class);
        for ($hour = 0; $hour < 12; $hour++) {
            $updater->update($site, blockedProbe());
            $this->travel(1)->hours();
        }

        $pct = app(UptimeStatsCalculator::class)->siteUptime($site, now()->subDay(), now());

        expect($pct)->toBe(50.0);
    });
});

describe('clockwork:check-site-uptime with a rate-limited site', function () {
    it('reports the site as blocked rather than down', function () {
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);

        $site = Site::factory()->create([
            'domain' => 'blocked.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'up',
        ]);

        quietUptimeChannels();

        $this->artisan('clockwork:check-site-uptime', ['--site' => $site->id])
            ->expectsOutputToContain('up=0 down=0 blocked=1')
            ->assertExitCode(0);
        // One 429 could be a blip; the next one in a row confirms it.
        expect($site->refresh()->uptime_state)->toBe('up');

        $this->travel(5)->minutes();
        $this->artisan('clockwork:check-site-uptime', ['--site' => $site->id])->assertExitCode(0);

        expect($site->refresh()->uptime_state)->toBe(Site::UPTIME_STATE_PROBE_BLOCKED);
    });
});

describe('site dashboard with a blocked probe', function () {
    it('labels the uptime widget and its event list as blocked, not down', function () {
        $this->mockIssueCounterZero();

        $site = Site::factory()->create([
            'domain' => 'blocked.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED,
            'uptime_probe_blocked_since' => now()->subHours(2),
            'uptime_last_status_code' => 429,
        ]);
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_PROBE_BLOCKED,
            'status_code' => 429,
            'error' => 'HTTP 429 (rate-limited — probe blocked)',
            'event_at' => now()->subHours(2),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('sites.show', $site))
            ->assertOk()
            ->assertSee('Probe blocked')
            ->assertSee('⊘ Blocked')
            ->assertSee('not counted as downtime')
            ->assertDontSee('↓ Down');
    });
});
