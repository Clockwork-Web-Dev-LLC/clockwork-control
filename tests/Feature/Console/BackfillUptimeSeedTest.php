<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\Site;

/*
|--------------------------------------------------------------------------
| Phase 6 console coverage for clockwork:backfill-uptime-seed
|--------------------------------------------------------------------------
|
| One-time data-backfill command, no external I/O — pure DB read/write via
| the real ActionLogger. Tested against small before/after fixtures per the
| Phase 6 brief, mirroring BackfillCompanionActionLogTest's shape.
*/

describe('clockwork:backfill-uptime-seed', function () {
    it('seeds a TYPE_UPTIME_TRANSITION row for an up site with no prior history', function () {
        $confirmedAt = now()->subDays(10);
        $site = Site::factory()->create([
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'up',
            'uptime_last_up_at' => $confirmedAt,
            'uptime_last_status_code' => 200,
        ]);

        $this->artisan('clockwork:backfill-uptime-seed')->assertSuccessful();

        $log = ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_UPTIME_TRANSITION)->first();
        expect($log)->not->toBeNull()
            ->and($log->target)->toBe('up')
            ->and($log->ok)->toBeTrue()
            ->and($log->actor)->toBe('backfill')
            ->and($log->ran_at->timestamp)->toBe($confirmedAt->timestamp)
            ->and($log->details['status_code'] ?? null)->toBe(200)
            ->and($log->details['backfilled'] ?? null)->toBeTrue();
    });

    it('falls back to now() as ran_at when uptime_last_up_at is null', function () {
        $site = Site::factory()->create([
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'up',
            'uptime_last_up_at' => null,
        ]);

        $this->artisan('clockwork:backfill-uptime-seed')->assertSuccessful();

        $log = ActionLog::query()->where('site_id', $site->id)->first();
        expect($log->ran_at->diffInSeconds(now()))->toBeLessThan(5);
    });

    it('is idempotent: skips a site that already has uptime_transition history', function () {
        $site = Site::factory()->create(['uptime_monitoring_enabled' => true, 'uptime_state' => 'up']);
        ActionLog::factory()->create([
            'site_id' => $site->id,
            'action_type' => ActionLog::TYPE_UPTIME_TRANSITION,
            'ran_at' => now()->subDays(30),
        ]);

        $this->artisan('clockwork:backfill-uptime-seed')->assertSuccessful();

        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_UPTIME_TRANSITION)->count())->toBe(1);
    });

    it('skips sites that are down or have uptime monitoring disabled', function () {
        Site::factory()->create(['uptime_monitoring_enabled' => true, 'uptime_state' => 'down']);
        Site::factory()->create(['uptime_monitoring_enabled' => false, 'uptime_state' => 'up']);

        $this->artisan('clockwork:backfill-uptime-seed')->assertSuccessful();

        expect(ActionLog::query()->where('action_type', ActionLog::TYPE_UPTIME_TRANSITION)->count())->toBe(0);
    });

    it('--dry-run reports what would be seeded without writing any ActionLog row', function () {
        $site = Site::factory()->create(['uptime_monitoring_enabled' => true, 'uptime_state' => 'up']);

        $this->artisan('clockwork:backfill-uptime-seed', ['--dry-run' => true])
            ->expectsOutputToContain($site->domain)
            ->assertSuccessful();

        expect(ActionLog::query()->count())->toBe(0);
    });

    it('--site limits the backfill to a single site by domain', function () {
        $target = Site::factory()->create(['uptime_monitoring_enabled' => true, 'uptime_state' => 'up', 'domain' => 'seed-me.example.test']);
        Site::factory()->create(['uptime_monitoring_enabled' => true, 'uptime_state' => 'up', 'domain' => 'not-me.example.test']);

        $this->artisan('clockwork:backfill-uptime-seed', ['--site' => 'seed-me.example.test'])->assertSuccessful();

        expect(ActionLog::query()->where('action_type', ActionLog::TYPE_UPTIME_TRANSITION)->count())->toBe(1)
            ->and(ActionLog::query()->first()->site_id)->toBe($target->id);
    });

    it('--site limits the backfill to a single site by numeric ID', function () {
        $target = Site::factory()->create(['uptime_monitoring_enabled' => true, 'uptime_state' => 'up']);
        Site::factory()->create(['uptime_monitoring_enabled' => true, 'uptime_state' => 'up']);

        $this->artisan('clockwork:backfill-uptime-seed', ['--site' => (string) $target->id])->assertSuccessful();

        expect(ActionLog::query()->where('action_type', ActionLog::TYPE_UPTIME_TRANSITION)->count())->toBe(1)
            ->and(ActionLog::query()->first()->site_id)->toBe($target->id);
    });
});
