<?php

use App\Services\Chat\ChatNotifier;
use App\Services\Scheduler\SchedulerHeartbeat;
use App\Support\IssueCounter;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

describe('SchedulerHeartbeat', function () {
    beforeEach(function () {
        $pdo = DB::connection()->getPdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value);
        }
    });

    it('reports never when no tick has been recorded', function () {
        $status = app(SchedulerHeartbeat::class)->status();

        expect($status->neverTicked())->toBeTrue()
            ->and($status->isStale())->toBeFalse()
            ->and($status->isOk())->toBeFalse()
            ->and($status->needsAttention())->toBeTrue();
    });

    it('reports ok when the last tick is inside the threshold', function () {
        Carbon::setTestNow('2026-09-09 12:00:00');
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_HEARTBEAT_AT,
            now()->subMinutes(2)->toIso8601String()
        );

        $status = app(SchedulerHeartbeat::class)->status();

        expect($status->isOk())->toBeTrue()
            ->and($status->isStale())->toBeFalse()
            ->and($status->ageSeconds)->toBe(120);
    });

    it('reports stale when the last tick is older than five minutes', function () {
        Carbon::setTestNow('2026-09-09 12:00:00');
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_HEARTBEAT_AT,
            now()->subMinutes(6)->toIso8601String()
        );

        $status = app(SchedulerHeartbeat::class)->status();

        expect($status->isStale())->toBeTrue()
            ->and($status->isOk())->toBeFalse()
            ->and($status->ageSeconds)->toBe(360);
    });

    it('treats a tick exactly at the threshold as still ok', function () {
        Carbon::setTestNow('2026-09-09 12:00:00');
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_HEARTBEAT_AT,
            now()->subSeconds(SchedulerHeartbeat::THRESHOLD_MINUTES * 60)->toIso8601String()
        );

        expect(app(SchedulerHeartbeat::class)->status()->isOk())->toBeTrue();
    });

    it('does not notify when the scheduler has never ticked', function () {
        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('schedulerStale');
            $mock->shouldNotReceive('schedulerRecovered');
        });

        app(SchedulerHeartbeat::class)->notifyIfStale();

        expect(app(Settings::class)->get(SchedulerHeartbeat::SETTING_STALE_SINCE))->toBeNull();
    });

    it('fires schedulerStale once on the first stale web request', function () {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $lastAt = now()->subMinutes(8);
        app(Settings::class)->put(SchedulerHeartbeat::SETTING_HEARTBEAT_AT, $lastAt->toIso8601String());

        $this->mock(ChatNotifier::class, function ($mock) use ($lastAt) {
            $mock->shouldReceive('schedulerStale')
                ->once()
                ->withArgs(fn (?int $age, $at) => $age === 480 && $at instanceof Carbon && $at->equalTo($lastAt))
                ->andReturn(true);
            $mock->shouldNotReceive('schedulerRecovered');
        });

        app(SchedulerHeartbeat::class)->notifyIfStale();

        expect((string) app(Settings::class)->get(SchedulerHeartbeat::SETTING_STALE_SINCE, ''))->not->toBe('');
    });

    it('does not re-fire schedulerStale while still stale', function () {
        Carbon::setTestNow('2026-09-09 12:00:00');
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_HEARTBEAT_AT,
            now()->subMinutes(10)->toIso8601String()
        );
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_STALE_SINCE,
            now()->subMinutes(4)->toIso8601String()
        );

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('schedulerStale');
            $mock->shouldNotReceive('schedulerRecovered');
        });

        app(SchedulerHeartbeat::class)->notifyIfStale();
    });

    it('does not fire recovered on the first tick', function () {
        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('schedulerStale');
            $mock->shouldNotReceive('schedulerRecovered');
        });

        app(SchedulerHeartbeat::class)->record();

        expect(app(Settings::class)->get(SchedulerHeartbeat::SETTING_HEARTBEAT_AT))->not->toBeEmpty()
            ->and(app(SchedulerHeartbeat::class)->status()->isOk())->toBeTrue();
    });

    it('fires recovered only after a stale alert had been sent', function () {
        Carbon::setTestNow('2026-09-09 12:00:00');
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_HEARTBEAT_AT,
            now()->subMinutes(12)->toIso8601String()
        );
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_STALE_SINCE,
            now()->subMinutes(6)->toIso8601String()
        );

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('schedulerRecovered')->once()->andReturn(true);
            $mock->shouldNotReceive('schedulerStale');
        });

        app(SchedulerHeartbeat::class)->record();

        expect(app(Settings::class)->get(SchedulerHeartbeat::SETTING_STALE_SINCE))->toBeNull()
            ->and(app(SchedulerHeartbeat::class)->status()->isOk())->toBeTrue();
    });

    it('does not count a never-ticked scheduler as an issue', function () {
        $initial = (new IssueCounter)->total();

        expect(app(SchedulerHeartbeat::class)->status()->neverTicked())->toBeTrue()
            ->and((new IssueCounter)->total())->toBe($initial);
    });

    it('counts a stale scheduler as one issue', function () {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $initial = (new IssueCounter)->total();

        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_HEARTBEAT_AT,
            now()->subMinutes(9)->toIso8601String()
        );

        expect((new IssueCounter)->total())->toBe($initial + 1);
    });

    it('records a tick from the artisan command', function () {
        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('schedulerRecovered');
        });

        $this->artisan('clockwork:scheduler-heartbeat')
            ->expectsOutput('Scheduler heartbeat recorded.')
            ->assertSuccessful();

        expect(app(Settings::class)->get(SchedulerHeartbeat::SETTING_HEARTBEAT_AT))->not->toBeEmpty()
            ->and(app(SchedulerHeartbeat::class)->status()->isOk())->toBeTrue();
    });
});
