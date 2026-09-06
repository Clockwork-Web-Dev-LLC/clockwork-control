<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ReapStaleUpdateJobs;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| ReapStaleUpdateJobs — notification call-site coverage
|--------------------------------------------------------------------------
|
| This file is only about whether ReapStaleUpdateJobs' single shared
| notification loop (at the end of handle()) fires ChatNotifier::
| pluginUpdateFailed for the right reaped rows, per the class's own
| docblock: "Mirrors AbstractRunUpdate's same nightly-only gate so manual
| bulk-update reaps stay quiet." It is NOT about ChatNotifierDispatcher's
| own is_inactive gating (see tests/Feature/Chat/ChatNotifierGatingTest.php)
| and it does not exhaustively re-test the reap/unlock mechanics — just
| enough of each pass to reach the point where the notification either
| should or should not fire.
|
| Both passes (stuck-running via started_at, stuck-pending via queued_at)
| feed the same loop, so the nightly-prefix gate is exercised once per
| pass plus one combined case proving both passes share the one loop.
*/

describe('pass 1: stuck-running rows past the 10 min threshold', function () {
    it('fires pluginUpdateFailed for a nightly-batch row reaped from running', function () {
        $site = Site::factory()->create(['is_inactive' => false]);
        $job = PluginUpdateJob::factory()->for($site)->running()->create([
            'started_at' => now()->subMinutes(ReapStaleUpdateJobs::STUCK_RUNNING_THRESHOLD_MINUTES + 5),
            'batch_id' => 'nightly-'.substr((string) Str::uuid(), 0, 28),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) use ($site, $job) {
            $mock->shouldReceive('pluginUpdateFailed')
                ->once()
                ->withArgs(fn (Site $s, PluginUpdateJob $j) => $s->is($site) && $j->id === $job->id)
                ->andReturn(true);
        });

        $this->artisan('clockwork:reap-stale-update-jobs')->assertSuccessful();

        expect($job->refresh()->status)->toBe(PluginUpdateJob::STATUS_FAILED);
    });

    it('does NOT fire pluginUpdateFailed for a non-nightly (manual bulk) row reaped from running, though it is still reaped', function () {
        $site = Site::factory()->create(['is_inactive' => false]);
        $job = PluginUpdateJob::factory()->for($site)->running()->create([
            'started_at' => now()->subMinutes(ReapStaleUpdateJobs::STUCK_RUNNING_THRESHOLD_MINUTES + 5),
            'batch_id' => (string) Str::uuid(), // no 'nightly-' prefix
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('pluginUpdateFailed')->never();
        });

        $this->artisan('clockwork:reap-stale-update-jobs')->assertSuccessful();

        expect($job->refresh()->status)->toBe(PluginUpdateJob::STATUS_FAILED);
    });

    it('does not reap or notify a running row still inside the 10 min threshold', function () {
        $site = Site::factory()->create(['is_inactive' => false]);
        $job = PluginUpdateJob::factory()->for($site)->running()->create([
            'started_at' => now()->subMinutes(2),
            'batch_id' => 'nightly-'.substr((string) Str::uuid(), 0, 28),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('pluginUpdateFailed')->never();
        });

        $this->artisan('clockwork:reap-stale-update-jobs')->assertSuccessful();

        expect($job->refresh()->status)->toBe(PluginUpdateJob::STATUS_RUNNING);
    });
});

describe('pass 2: stuck-pending rows past the 60 min threshold', function () {
    it('fires pluginUpdateFailed for a nightly-batch row reaped from pending', function () {
        $site = Site::factory()->create(['is_inactive' => false]);
        $job = PluginUpdateJob::factory()->for($site)->create([
            'status' => PluginUpdateJob::STATUS_PENDING,
            'queued_at' => now()->subMinutes(ReapStaleUpdateJobs::STUCK_PENDING_THRESHOLD_MINUTES + 10),
            'batch_id' => 'nightly-'.substr((string) Str::uuid(), 0, 28),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) use ($site, $job) {
            $mock->shouldReceive('pluginUpdateFailed')
                ->once()
                ->withArgs(fn (Site $s, PluginUpdateJob $j) => $s->is($site) && $j->id === $job->id)
                ->andReturn(true);
        });

        $this->artisan('clockwork:reap-stale-update-jobs')->assertSuccessful();

        expect($job->refresh()->status)->toBe(PluginUpdateJob::STATUS_FAILED);
    });

    it('does NOT fire pluginUpdateFailed for a non-nightly (manual bulk) row reaped from pending, though it is still reaped', function () {
        $site = Site::factory()->create(['is_inactive' => false]);
        $job = PluginUpdateJob::factory()->for($site)->create([
            'status' => PluginUpdateJob::STATUS_PENDING,
            'queued_at' => now()->subMinutes(ReapStaleUpdateJobs::STUCK_PENDING_THRESHOLD_MINUTES + 10),
            'batch_id' => (string) Str::uuid(), // no 'nightly-' prefix
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('pluginUpdateFailed')->never();
        });

        $this->artisan('clockwork:reap-stale-update-jobs')->assertSuccessful();

        expect($job->refresh()->status)->toBe(PluginUpdateJob::STATUS_FAILED);
    });

    it('does not reap or notify a pending row still inside the 60 min threshold', function () {
        $site = Site::factory()->create(['is_inactive' => false]);
        $job = PluginUpdateJob::factory()->for($site)->create([
            'status' => PluginUpdateJob::STATUS_PENDING,
            'queued_at' => now()->subMinutes(20),
            'batch_id' => 'nightly-'.substr((string) Str::uuid(), 0, 28),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('pluginUpdateFailed')->never();
        });

        $this->artisan('clockwork:reap-stale-update-jobs')->assertSuccessful();

        expect($job->refresh()->status)->toBe(PluginUpdateJob::STATUS_PENDING);
    });
});

describe('shared notification loop: both passes feed the same nightly-prefix gate', function () {
    it('fires once for a nightly-running row and once for a nightly-pending row in the same run, and stays quiet for a manual row reaped alongside them', function () {
        $site = Site::factory()->create(['is_inactive' => false]);

        $nightlyRunning = PluginUpdateJob::factory()->for($site)->running()->create([
            'started_at' => now()->subMinutes(ReapStaleUpdateJobs::STUCK_RUNNING_THRESHOLD_MINUTES + 1),
            'batch_id' => 'nightly-'.substr((string) Str::uuid(), 0, 28),
        ]);
        $nightlyPending = PluginUpdateJob::factory()->for($site)->create([
            'status' => PluginUpdateJob::STATUS_PENDING,
            'queued_at' => now()->subMinutes(ReapStaleUpdateJobs::STUCK_PENDING_THRESHOLD_MINUTES + 1),
            'batch_id' => 'nightly-'.substr((string) Str::uuid(), 0, 28),
        ]);
        $manualRunning = PluginUpdateJob::factory()->for($site)->running()->create([
            'started_at' => now()->subMinutes(ReapStaleUpdateJobs::STUCK_RUNNING_THRESHOLD_MINUTES + 1),
            'batch_id' => (string) Str::uuid(),
        ]);

        $notifiedJobIds = [];
        $this->mock(ChatNotifier::class, function ($mock) use (&$notifiedJobIds) {
            $mock->shouldReceive('pluginUpdateFailed')
                ->twice()
                ->withArgs(function (Site $s, PluginUpdateJob $j) use (&$notifiedJobIds) {
                    $notifiedJobIds[] = $j->id;

                    return true;
                })
                ->andReturn(true);
        });

        $this->artisan('clockwork:reap-stale-update-jobs')->assertSuccessful();

        expect($notifiedJobIds)->toEqualCanonicalizing([$nightlyRunning->id, $nightlyPending->id])
            ->and($manualRunning->refresh()->status)->toBe(PluginUpdateJob::STATUS_FAILED)
            ->and($nightlyRunning->refresh()->status)->toBe(PluginUpdateJob::STATUS_FAILED)
            ->and($nightlyPending->refresh()->status)->toBe(PluginUpdateJob::STATUS_FAILED);
    });
});

describe('no-op run', function () {
    it('calls no notifier and exits successfully when nothing is stale', function () {
        $site = Site::factory()->create(['is_inactive' => false]);
        $job = PluginUpdateJob::factory()->for($site)->complete()->create([
            'completed_at' => now()->subMinutes(200),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('pluginUpdateFailed')->never();
        });

        $this->artisan('clockwork:reap-stale-update-jobs')->assertSuccessful();

        expect($job->refresh()->status)->toBe(PluginUpdateJob::STATUS_COMPLETE);
    });
});
