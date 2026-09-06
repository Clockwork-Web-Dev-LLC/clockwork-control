<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunPluginUpdate;
use App\Models\ActionLog;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Regression coverage for AbstractRunUpdate's shared lifecycle (locking,
 * status transitions, ActionLog writes, chat notification gating), driven
 * through its RunPluginUpdate subclass. See AbstractRunUpdate's class
 * docblock for the real 2026-08-28 incident this guards against: a job
 * timeout kills the worker via SIGALRM before handle()'s finally{} can run,
 * so the per-site Cache::lock is never released and every subsequent update
 * for that site piles up in 'pending' behind a lock nobody will ever free.
 *
 * Jobs are driven via direct ->handle()/->failed() invocation rather than
 * dispatch()/dispatchSync() — the base class's handle() takes its
 * ActionLogger dependency as a plain method parameter, so there is nothing
 * the queue's method-injection buys us here, and invoking directly keeps
 * the lock-contention and failed() scenarios (which simulate state the
 * queue itself can't easily be coaxed into) deterministic.
 */
function makeCompanionSite(array $overrides = []): Site
{
    // companion_secret (not companion_installed) is all ClockworkCompanionClient's
    // signedRequest() requires. Deliberately leaving companion_installed=false
    // (the SiteFactory default) keeps AbstractRunUpdate::maybeRefreshSnapshot()
    // from firing a real Artisan::queue('clockwork:refresh-companion-snapshot')
    // side call after every job — that command is out of scope for this test
    // and would otherwise need its own Http::fake coverage.
    return Site::factory()->create(array_merge([
        'companion_secret' => Str::random(40),
    ], $overrides));
}

function fakePluginUpdateEndpoint(Site $site, array $response, int $status = 200): void
{
    Http::fake([
        "https://{$site->domain}/wp-json/clockwork/v1/plugins/update" => Http::response(
            $response,
            $status,
            ['Content-Type' => 'application/json'],
        ),
    ]);
}

describe('AbstractRunUpdate happy path (via RunPluginUpdate)', function () {
    it('marks the job running then complete, writes an ok ActionLog row, and never notifies chat', function () {
        $site = makeCompanionSite();
        $job = PluginUpdateJob::factory()->for($site)->create([
            'target_slug' => 'contact-form-7',
            'target_name' => 'Contact Form 7',
            'before_version' => '5.9',
            'target_version' => '6.0',
        ]);

        fakePluginUpdateEndpoint($site, [
            'ok' => true,
            'slug' => 'contact-form-7',
            'before_version' => '5.9',
            'after_version' => '6.0',
            'was_active' => true,
            'reactivated' => true,
            'elapsed_ms' => 1234,
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('pluginUpdateFailed')->never();
        });

        (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));

        $job->refresh();
        expect($job->status)->toBe(PluginUpdateJob::STATUS_COMPLETE)
            ->and($job->started_at)->not->toBeNull()
            ->and($job->completed_at)->not->toBeNull()
            ->and($job->before_version)->toBe('5.9')
            ->and($job->after_version)->toBe('6.0')
            ->and($job->error)->toBeNull();

        $log = ActionLog::where('action_type', ActionLog::TYPE_PLUGIN_UPDATE)
            ->where('site_id', $site->id)
            ->first();
        expect($log)->not->toBeNull()
            ->and($log->ok)->toBeTrue()
            ->and($log->target)->toBe('contact-form-7')
            ->and($log->error)->toBeNull();

        // The finally{} block released the per-site lock on the success path —
        // a fresh lock instance on the same key must be free to acquire.
        $fresh = Cache::lock("site_update:{$site->id}", 5);
        expect($fresh->get())->toBeTrue();
        $fresh->release();
    });
});

describe('AbstractRunUpdate failure path (via RunPluginUpdate)', function () {
    it('marks the job failed, writes a not-ok ActionLog row, and fires ChatNotifier for a nightly batch', function () {
        $site = makeCompanionSite();
        $job = PluginUpdateJob::factory()->for($site)->create([
            'target_slug' => 'js_composer',
            'target_name' => 'WPBakery',
            'before_version' => '8.7.2',
            'target_version' => '9.0.1',
            'batch_id' => 'nightly-'.substr((string) Str::uuid(), 0, 28),
        ]);

        // A genuine transport/HTTP failure (guard() throws RuntimeException),
        // exercising the outer catch (Throwable $e) branch of handle().
        fakePluginUpdateEndpoint($site, ['ok' => false, 'error' => 'internal server error'], 500);

        $this->mock(ChatNotifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('pluginUpdateFailed')
                ->once()
                ->withArgs(fn (Site $s, PluginUpdateJob $j) => $s->is($site) && $j->id !== null)
                ->andReturn(true);
        });

        (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));

        $job->refresh();
        expect($job->status)->toBe(PluginUpdateJob::STATUS_FAILED)
            ->and($job->completed_at)->not->toBeNull()
            ->and($job->error)->toContain('Clockwork Companion POST /plugins/update');

        $log = ActionLog::where('action_type', ActionLog::TYPE_PLUGIN_UPDATE)
            ->where('site_id', $site->id)
            ->first();
        expect($log)->not->toBeNull()
            ->and($log->ok)->toBeFalse()
            ->and($log->summary)->toContain('Update threw:')
            ->and($log->error)->not->toBeNull();

        // failed-path also releases the lock via the same finally{} — confirm
        // it isn't left held just because the job itself failed.
        $fresh = Cache::lock("site_update:{$site->id}", 5);
        expect($fresh->get())->toBeTrue();
        $fresh->release();
    });

    it('does NOT notify chat for a non-nightly (manual bulk) batch failure', function () {
        $site = makeCompanionSite();
        $job = PluginUpdateJob::factory()->for($site)->create([
            'target_slug' => 'js_composer',
            'batch_id' => (string) Str::uuid(), // no 'nightly-' prefix
        ]);

        fakePluginUpdateEndpoint($site, ['ok' => false, 'error' => 'boom'], 500);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('pluginUpdateFailed')->never();
        });

        (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));

        expect($job->refresh()->status)->toBe(PluginUpdateJob::STATUS_FAILED);
    });

    it('marks the job failed when Companion reports ok=false without throwing', function () {
        $site = makeCompanionSite();
        $job = PluginUpdateJob::factory()->for($site)->create([
            'target_slug' => 'some-plugin',
            'batch_id' => (string) Str::uuid(),
        ]);

        fakePluginUpdateEndpoint($site, [
            'ok' => false,
            'error' => 'wp_filesystem could not be initialized',
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('pluginUpdateFailed')->never();
        });

        (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));

        $job->refresh();
        expect($job->status)->toBe(PluginUpdateJob::STATUS_FAILED)
            ->and($job->error)->toBe('wp_filesystem could not be initialized');

        $log = ActionLog::where('site_id', $site->id)->first();
        expect($log->ok)->toBeFalse()
            ->and($log->summary)->toContain('Plugin update failed');
    });
});

describe('AbstractRunUpdate per-site locking', function () {
    it('re-queues with a 15s delay instead of running when another update already holds the site lock', function () {
        $site = makeCompanionSite();
        $blockedJob = PluginUpdateJob::factory()->for($site)->create([
            'target_slug' => 'plugin-b',
        ]);

        // Simulate another update job already in flight for this site by
        // holding the real lock key it contends on.
        $holder = Cache::lock("site_update:{$site->id}", 200);
        expect($holder->get())->toBeTrue();

        Http::fake(); // any Companion call here would mean locking failed to short-circuit.

        $runner = new RunPluginUpdate($blockedJob->id);
        $runner->withFakeQueueInteractions();
        $runner->handle(app(ActionLogger::class));

        $runner->assertReleased(15);
        Http::assertNothingSent();

        // The row must be untouched — handle() returned before doing anything
        // to it, so it's still exactly the 'pending' row it started as.
        expect($blockedJob->refresh()->status)->toBe(PluginUpdateJob::STATUS_PENDING)
            ->and($blockedJob->started_at)->toBeNull();

        $holder->release();
    });

    it('skips rows that are not pending (defends against double-pickup)', function () {
        $site = makeCompanionSite();
        $job = PluginUpdateJob::factory()->for($site)->running()->create([
            'target_slug' => 'plugin-c',
        ]);

        Http::fake();

        (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));

        Http::assertNothingSent();
        expect($job->refresh()->status)->toBe(PluginUpdateJob::STATUS_RUNNING);
    });
});

describe('AbstractRunUpdate::failed() — the SIGALRM timeout regression guard', function () {
    it('force-releases the per-site lock and marks a live row failed, even though handle() never reached its finally{}', function () {
        $site = makeCompanionSite();
        $row = PluginUpdateJob::factory()->for($site)->running()->create([
            'target_slug' => 'slow-plugin',
        ]);

        // Reproduce the exact state at the moment SIGALRM fires: handle()
        // acquired the lock and is mid-flight (status=running), and — because
        // the worker process is about to be SIGKILLed — never reaches its
        // `finally { $lock->release(); }`. We simulate that by acquiring the
        // lock ourselves and never releasing it, then invoking failed()
        // exactly as Laravel does synchronously before the kill.
        $held = Cache::lock("site_update:{$site->id}", 200);
        expect($held->get())->toBeTrue();

        (new RunPluginUpdate($row->id))->failed(new \RuntimeException('SIGALRM: job exceeded 180s'));

        $row->refresh();
        expect($row->status)->toBe(PluginUpdateJob::STATUS_FAILED)
            ->and($row->completed_at)->not->toBeNull()
            ->and($row->error)->toContain('Job timed out after 180s')
            ->and($row->error)->toContain('SIGALRM: job exceeded 180s');

        // The real regression: a lock a dead process was holding must be
        // force-releasable by failed(), not just release()-able by its
        // original (now-vanished) owner. A brand new lock instance on the
        // same key — which has no owner-token relationship to $held — must
        // be able to acquire it.
        $next = Cache::lock("site_update:{$site->id}", 5);
        expect($next->get())->toBeTrue();
        $next->release();
    });

    it('does not overwrite an already-terminal row, but still force-releases the lock', function () {
        $site = makeCompanionSite();
        $row = PluginUpdateJob::factory()->for($site)->complete()->create([
            'target_slug' => 'already-done-plugin',
        ]);

        $held = Cache::lock("site_update:{$site->id}", 200);
        expect($held->get())->toBeTrue();

        (new RunPluginUpdate($row->id))->failed(new \RuntimeException('late/irrelevant timeout'));

        // Status untouched — failed() only overwrites rows still in a LIVE
        // status (pending/running); a row that already finished normally
        // keeps its real outcome.
        expect($row->refresh()->status)->toBe(PluginUpdateJob::STATUS_COMPLETE);

        // But the lock is unconditionally force-released regardless of the
        // row's status — see failed()'s own code, which force-releases
        // outside the `if ($row && in_array(...))` status-guard block.
        $next = Cache::lock("site_update:{$site->id}", 5);
        expect($next->get())->toBeTrue();
        $next->release();
    });

    it('no-ops harmlessly when the job row no longer exists', function () {
        $missingId = 999999;

        expect(fn () => (new RunPluginUpdate($missingId))->failed(new \RuntimeException('irrelevant')))
            ->not->toThrow(\Throwable::class);
    });
});
