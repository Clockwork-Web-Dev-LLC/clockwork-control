<?php

use App\Models\Site;
use App\Models\ThreatLog;
use App\Services\Logs\ThreatLogRetention;
use App\Support\Settings;

describe('PruneThreatLogs', function () {
    it('deletes rows older than the default 30-day window and keeps recent ones', function () {
        $site = Site::factory()->create();

        $old = ThreatLog::factory()->create([
            'site_id' => $site->id,
            'event_at' => now()->subDays(31),
        ]);
        $recent = ThreatLog::factory()->create([
            'site_id' => $site->id,
            'event_at' => now()->subDays(10),
        ]);

        $this->artisan('clockwork:prune-threat-logs')->assertSuccessful();

        expect(ThreatLog::query()->whereKey($old->id)->exists())->toBeFalse()
            ->and(ThreatLog::query()->whereKey($recent->id)->exists())->toBeTrue();
    });

    it('uses the saved days/weeks setting when --days is omitted', function () {
        $site = Site::factory()->create();
        app(Settings::class)->putMany([
            ThreatLogRetention::SETTING_AMOUNT => 2,
            ThreatLogRetention::SETTING_UNIT => ThreatLogRetention::UNIT_WEEKS,
        ]);

        $stale = ThreatLog::factory()->create([
            'site_id' => $site->id,
            'event_at' => now()->subDays(15),
        ]);
        $kept = ThreatLog::factory()->create([
            'site_id' => $site->id,
            'event_at' => now()->subDays(10),
        ]);

        $this->artisan('clockwork:prune-threat-logs')->assertSuccessful();

        expect(ThreatLog::query()->whereKey($stale->id)->exists())->toBeFalse()
            ->and(ThreatLog::query()->whereKey($kept->id)->exists())->toBeTrue();
    });

    it('respects a custom --days override', function () {
        $site = Site::factory()->create();

        $tenDaysOld = ThreatLog::factory()->create([
            'site_id' => $site->id,
            'event_at' => now()->subDays(10),
        ]);
        $twoDaysOld = ThreatLog::factory()->create([
            'site_id' => $site->id,
            'event_at' => now()->subDays(2),
        ]);

        $this->artisan('clockwork:prune-threat-logs', ['--days' => 7])->assertSuccessful();

        expect(ThreatLog::query()->whereKey($tenDaysOld->id)->exists())->toBeFalse()
            ->and(ThreatLog::query()->whereKey($twoDaysOld->id)->exists())->toBeTrue();
    });

    it('does not delete on --dry-run', function () {
        $site = Site::factory()->create();
        $old = ThreatLog::factory()->create([
            'site_id' => $site->id,
            'event_at' => now()->subDays(40),
        ]);

        $this->artisan('clockwork:prune-threat-logs', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run:')
            ->assertSuccessful();

        expect(ThreatLog::query()->whereKey($old->id)->exists())->toBeTrue();
    });

    it('honors --max-rows so a catch-up can nibble', function () {
        $site = Site::factory()->create();
        ThreatLog::factory()->count(3)->create([
            'site_id' => $site->id,
            'event_at' => now()->subDays(40),
        ]);

        $this->artisan('clockwork:prune-threat-logs', [
            '--chunk' => 1,
            '--max-rows' => 1,
        ])->assertSuccessful();

        expect(ThreatLog::query()->count())->toBe(2);
    });

    it('records last-run stats', function () {
        $site = Site::factory()->create();
        ThreatLog::factory()->create([
            'site_id' => $site->id,
            'event_at' => now()->subDays(40),
        ]);

        $this->artisan('clockwork:prune-threat-logs')->assertSuccessful();

        $settings = app(Settings::class);
        expect($settings->get(ThreatLogRetention::SETTING_LAST_DELETED))->toBe(1)
            ->and($settings->get(ThreatLogRetention::SETTING_LAST_RUN_AT))->not->toBeNull();
    });
});
