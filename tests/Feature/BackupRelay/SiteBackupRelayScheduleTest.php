<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\User;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use Illuminate\Support\Carbon;

describe('per-site backup relay schedule', function () {
    beforeEach(function () {
        Carbon::setTestNow(Carbon::parse('2026-09-11 15:00:00'));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('defaults custom enroll cadence to daily and inherits weekly when unset', function () {
        $custom = Site::factory()->custom()->create([
            'backup_relay_enabled' => true,
            'backup_relay_frequency' => 'daily',
        ]);
        $inherited = Site::factory()->custom()->create([
            'backup_relay_enabled' => true,
            'backup_relay_frequency' => null,
        ]);

        expect($custom->backupRelayFrequency())->toBe('daily')
            ->and($inherited->backupRelayFrequency())->toBe('weekly');
    });

    it('computes next daily slot as tomorrow morning after a same-day archive', function () {
        $site = Site::factory()->custom()->create([
            'backup_relay_enabled' => true,
            'backup_relay_frequency' => 'daily',
            'backup_relay_last_archived_at' => now(),
        ]);

        expect($site->backupRelayNextScheduledAt()?->format('Y-m-d H:i'))->toBe('2026-09-12 04:58');
    });

    it('returns null next slot when backups are off', function () {
        $site = Site::factory()->custom()->create([
            'backup_relay_enabled' => false,
            'backup_relay_frequency' => 'daily',
        ]);

        expect($site->backupRelayNextScheduledAt())->toBeNull();
    });

    it('saves per-site frequency and toggle on a custom site', function () {
        $site = Site::factory()->custom()->create([
            'backup_relay_enabled' => true,
            'backup_relay_frequency' => 'daily',
        ]);

        $this->patchJson(route('sites.backup-relay.update', $site), [
            'enabled' => true,
            'frequency' => 'weekly',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('frequency', 'weekly');

        expect($site->fresh()->backup_relay_frequency)->toBe('weekly')
            ->and(ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_BACKUP_RELAY_TOGGLED)->exists())->toBeTrue();
    });

    it('rejects backup-relay updates on hosted SpinupWP sites', function () {
        $site = Site::factory()->spinupwp()->create();

        $this->patchJson(route('sites.backup-relay.update', $site), [
            'enabled' => true,
            'frequency' => 'daily',
        ])->assertForbidden();
    });

    it('starts Backup Now for one custom site via artisan --force', function () {
        $site = Site::factory()->custom()->create([
            'backup_relay_enabled' => true,
            'backup_relay_frequency' => 'daily',
        ]);

        $this->mock(BackgroundArtisan::class, function ($mock) use ($site) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (string $key, array $cmds) => $key === 'backup_relay.run.site.'.$site->id
                    && $cmds === ['clockwork:backup-relay-run --site='.$site->id.' --force'])
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $this->postJson(route('sites.backup-relay.run-now', $site))
            ->assertOk()
            ->assertJsonPath('ok', true);

        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_BACKUP_RELAY_RUN_NOW)->exists())->toBeTrue();
    });

    it('refuses Backup Now when the site relay is off', function () {
        $site = Site::factory()->custom()->create([
            'backup_relay_enabled' => false,
            'backup_relay_frequency' => 'daily',
        ]);

        $this->postJson(route('sites.backup-relay.run-now', $site))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    });
});
