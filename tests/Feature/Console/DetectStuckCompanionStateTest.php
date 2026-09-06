<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DetectStuckCompanionState;
use App\Models\ActionLog;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;

/*
|--------------------------------------------------------------------------
| Call-site coverage for DetectStuckCompanionState
|--------------------------------------------------------------------------
|
| This is Phase 4 "notification event call-site" coverage: confirming
| companionUnreachable/companionReachable fire under the command's real
| triggering conditions and stay silent otherwise. It is NOT re-testing
| ChatNotifierDispatcher's own is_inactive gating (see
| tests/Feature/Chat/ChatNotifierGatingTest.php for that) — every site
| below is left at its factory default of is_inactive=false so that gating
| layer never interferes with what's being asserted here.
*/

describe('companionUnreachable — stuck install path', function () {
    it('fires once and marks the site stuck when the latest companion_install attempt failed and is past the grace period', function () {
        $site = Site::factory()->create([
            'companion_installed' => false,
            'companion_stuck_since' => null,
        ]);

        ActionLog::factory()->failed('SSH connection refused')->create([
            'site_id' => $site->id,
            'action_type' => ActionLog::TYPE_COMPANION_INSTALL,
            'ran_at' => now()->subHours(DetectStuckCompanionState::INSTALL_FAILURE_GRACE_HOURS + 1),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('companionUnreachable')
                ->once()
                ->withArgs(fn (Site $s, string $reason) => $s->is($site)
                    && str_contains($reason, (string) DetectStuckCompanionState::INSTALL_FAILURE_GRACE_HOURS))
                ->andReturn(true);
            $mock->shouldReceive('companionReachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state')->assertSuccessful();

        $site->refresh();
        expect($site->companion_stuck_since)->not->toBeNull()
            ->and($site->companion_stuck_reason)->toBe(DetectStuckCompanionState::REASON_INSTALL_FAILED);
    });

    it('does NOT fire while the failed attempt is still inside the grace period', function () {
        $site = Site::factory()->create([
            'companion_installed' => false,
            'companion_stuck_since' => null,
        ]);

        ActionLog::factory()->failed()->create([
            'site_id' => $site->id,
            'action_type' => ActionLog::TYPE_COMPANION_INSTALL,
            'ran_at' => now()->subHours(1),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('companionUnreachable')->never();
            $mock->shouldReceive('companionReachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state')->assertSuccessful();

        expect($site->refresh()->companion_stuck_since)->toBeNull();
    });

    it('does NOT fire when the latest attempt for the site actually succeeded', function () {
        $site = Site::factory()->create([
            'companion_installed' => false,
            'companion_stuck_since' => null,
        ]);

        // An old failure followed by a more recent success — the command
        // only looks at the LATEST attempt, which is fine.
        ActionLog::factory()->failed()->create([
            'site_id' => $site->id,
            'action_type' => ActionLog::TYPE_COMPANION_INSTALL,
            'ran_at' => now()->subHours(DetectStuckCompanionState::INSTALL_FAILURE_GRACE_HOURS + 5),
        ]);
        ActionLog::factory()->create([
            'site_id' => $site->id,
            'action_type' => ActionLog::TYPE_COMPANION_INSTALL,
            'ok' => true,
            'ran_at' => now()->subHours(1),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('companionUnreachable')->never();
            $mock->shouldReceive('companionReachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state')->assertSuccessful();

        expect($site->refresh()->companion_stuck_since)->toBeNull();
    });

    it('stays silent for a site that is already stuck for the same reason (no re-notification)', function () {
        $stuckSince = now()->subDays(2);
        $site = Site::factory()->create([
            'companion_installed' => false,
            'companion_stuck_since' => $stuckSince,
            'companion_stuck_reason' => DetectStuckCompanionState::REASON_INSTALL_FAILED,
        ]);

        ActionLog::factory()->failed()->create([
            'site_id' => $site->id,
            'action_type' => ActionLog::TYPE_COMPANION_INSTALL,
            'ran_at' => now()->subHours(DetectStuckCompanionState::INSTALL_FAILURE_GRACE_HOURS + 1),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('companionUnreachable')->never();
            $mock->shouldReceive('companionReachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state')->assertSuccessful();

        $site->refresh();
        expect($site->companion_stuck_since->timestamp)->toBe($stuckSince->timestamp)
            ->and($site->companion_stuck_reason)->toBe(DetectStuckCompanionState::REASON_INSTALL_FAILED);
    });
});

describe('companionUnreachable — stale snapshot path', function () {
    it('fires once when companion_snapshot_at is older than the stale threshold', function () {
        $site = Site::factory()->withCompanionInstalled()->create([
            'companion_stuck_since' => null,
            'companion_snapshot_at' => now()->subDays(DetectStuckCompanionState::SNAPSHOT_STALE_DAYS + 1),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('companionUnreachable')
                ->once()
                ->withArgs(fn (Site $s, string $reason) => $s->is($site)
                    && str_contains($reason, (string) DetectStuckCompanionState::SNAPSHOT_STALE_DAYS))
                ->andReturn(true);
            $mock->shouldReceive('companionReachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state')->assertSuccessful();

        $site->refresh();
        expect($site->companion_stuck_since)->not->toBeNull()
            ->and($site->companion_stuck_reason)->toBe(DetectStuckCompanionState::REASON_SNAPSHOT_STALE);
    });

    it('falls back to companion_last_seen_at when companion_snapshot_at is null, and fires when that is stale', function () {
        $site = Site::factory()->withCompanionInstalled()->create([
            'companion_stuck_since' => null,
            'companion_snapshot_at' => null,
            'companion_last_seen_at' => now()->subDays(DetectStuckCompanionState::SNAPSHOT_STALE_DAYS + 1),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('companionUnreachable')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn(true);
        });

        $this->artisan('clockwork:detect-stuck-companion-state')->assertSuccessful();

        expect($site->refresh()->companion_stuck_reason)->toBe(DetectStuckCompanionState::REASON_SNAPSHOT_STALE);
    });

    it('does NOT fire when the snapshot is fresh', function () {
        $site = Site::factory()->withCompanionInstalled()->create([
            'companion_stuck_since' => null,
            'companion_snapshot_at' => now()->subDays(1),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('companionUnreachable')->never();
            $mock->shouldReceive('companionReachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state')->assertSuccessful();

        expect($site->refresh()->companion_stuck_since)->toBeNull();
    });

    it('does NOT fire when both companion_snapshot_at and companion_last_seen_at are null (fresh install)', function () {
        $site = Site::factory()->create([
            'companion_installed' => true,
            'companion_stuck_since' => null,
            'companion_snapshot_at' => null,
            'companion_last_seen_at' => null,
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('companionUnreachable')->never();
            $mock->shouldReceive('companionReachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state')->assertSuccessful();

        expect($site->refresh()->companion_stuck_since)->toBeNull();
    });
});

describe('companionReachable — recovery', function () {
    it('fires once with the elapsed stuck duration when a previously stuck site no longer matches either stuck condition', function () {
        $stuckSince = now()->subHours(5);
        $site = Site::factory()->withCompanionInstalled()->create([
            'companion_stuck_since' => $stuckSince,
            'companion_stuck_reason' => DetectStuckCompanionState::REASON_INSTALL_FAILED,
            'companion_snapshot_at' => now(), // fresh — no longer stale
        ]);

        $this->mock(ChatNotifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('companionReachable')
                ->once()
                ->withArgs(fn (Site $s, ?int $stuckForSeconds) => $s->is($site)
                    && $stuckForSeconds !== null
                    && $stuckForSeconds >= 5 * 3600 - 5
                    && $stuckForSeconds <= 5 * 3600 + 5)
                ->andReturn(true);
            $mock->shouldReceive('companionUnreachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state')->assertSuccessful();

        $site->refresh();
        expect($site->companion_stuck_since)->toBeNull()
            ->and($site->companion_stuck_reason)->toBeNull();
    });
});

describe('--dry-run', function () {
    it('does not write to the database or notify chat for a newly-stuck site', function () {
        $site = Site::factory()->create([
            'companion_installed' => false,
            'companion_stuck_since' => null,
        ]);

        ActionLog::factory()->failed()->create([
            'site_id' => $site->id,
            'action_type' => ActionLog::TYPE_COMPANION_INSTALL,
            'ran_at' => now()->subHours(DetectStuckCompanionState::INSTALL_FAILURE_GRACE_HOURS + 1),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('companionUnreachable')->never();
            $mock->shouldReceive('companionReachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state', ['--dry-run' => true])->assertSuccessful();

        $site->refresh();
        expect($site->companion_stuck_since)->toBeNull()
            ->and($site->companion_stuck_reason)->toBeNull();
    });

    it('does not write to the database or notify chat for a recovering site', function () {
        $stuckSince = now()->subHours(5);
        $site = Site::factory()->withCompanionInstalled()->create([
            'companion_stuck_since' => $stuckSince,
            'companion_stuck_reason' => DetectStuckCompanionState::REASON_INSTALL_FAILED,
            'companion_snapshot_at' => now(),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('companionUnreachable')->never();
            $mock->shouldReceive('companionReachable')->never();
        });

        $this->artisan('clockwork:detect-stuck-companion-state', ['--dry-run' => true])->assertSuccessful();

        $site->refresh();
        expect($site->companion_stuck_since->timestamp)->toBe($stuckSince->timestamp)
            ->and($site->companion_stuck_reason)->toBe(DetectStuckCompanionState::REASON_INSTALL_FAILED);
    });
});
