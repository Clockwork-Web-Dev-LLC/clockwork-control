<?php

namespace Tests\Feature\Updates;

use App\Jobs\RunPluginUpdate;
use App\Models\ActionLog;
use App\Models\PluginUpdateFailureStreak;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\ActionLog\ActionLogger;
use App\Services\Updates\UpdateFailureStreakRecorder;
use App\Support\IssueCounter;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function makeExampleSite(array $overrides = []): Site
{
    $server = Server::factory()->create(['is_ignored' => false]);

    return Site::factory()->for($server)->create(array_merge([
        'domain' => 'example.com',
        'companion_secret' => Str::random(40),
        'companion_installed' => false, // Prevents async snapshot/cache refresh during unit tests
        'companion_capabilities' => ['update-exceptions'],
        'care_plan_enabled' => true,
        'auto_updates_paused' => false,
        'wp_plugin_updates' => true,
        'wp_theme_updates' => true,
    ], $overrides));
}

function fakeNightlyJob(Site $site, string $slug = 'stubborn-plugin', array $overrides = []): PluginUpdateJob
{
    return PluginUpdateJob::factory()->for($site)->create(array_merge([
        'batch_id' => 'nightly-'.now()->format('Y-m-d'),
        'requested_by_user_id' => null,
        'target_kind' => PluginUpdateJob::KIND_PLUGIN,
        'target_slug' => $slug,
        'target_name' => 'Stubborn Plugin',
        'before_version' => '1.0.0',
        'target_version' => '1.1.0',
        'status' => PluginUpdateJob::STATUS_PENDING,
    ], $overrides));
}

describe('Update failure streaks and auto-ignore', function () {
    it('increments streak on nightly failure with companion error response, does not auto-ignore before threshold', function () {
        $site = makeExampleSite();

        for ($i = 1; $i <= 4; $i++) {
            $job = fakeNightlyJob($site, 'stubborn-plugin');

            Http::fake([
                "https://{$site->domain}/wp-json/clockwork/v1/plugins/update" => Http::response([
                    'ok' => false,
                    'error' => 'Update package not available.',
                    'slug' => 'stubborn-plugin',
                    'before_version' => '1.0.0',
                    'after_version' => '1.0.0',
                    'was_active' => true,
                    'reactivated' => true,
                ], 200),
            ]);

            (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));

            $streak = PluginUpdateFailureStreak::where('site_id', $site->id)
                ->where('target_kind', PluginUpdateJob::KIND_PLUGIN)
                ->where('target_slug', 'stubborn-plugin')
                ->first();

            expect($streak)->not->toBeNull()
                ->and($streak->consecutive_failures)->toBe($i)
                ->and($streak->ignored_at)->toBeNull()
                ->and($streak->ignore_id)->toBeNull();

            expect(PluginUpdateIgnore::where('site_id', $site->id)
                ->where('target_slug', 'stubborn-plugin')
                ->exists())->toBeFalse();
        }
    });

    it('auto-ignores at threshold of 5 failures, sets source=auto_failure and client_visible=true, and fires action log', function () {
        $site = makeExampleSite();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/update" => Http::response([
                'ok' => false,
                'error' => 'Plugin update failed.',
                'slug' => 'stubborn-plugin',
                'before_version' => '1.0.0',
                'after_version' => '1.0.0',
                'was_active' => true,
                'reactivated' => true,
            ], 200),
            "https://{$site->domain}/wp-json/clockwork/v1/update-exceptions" => Http::response(['ok' => true], 200),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $job = fakeNightlyJob($site, 'stubborn-plugin');
            (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));
        }

        $streak = PluginUpdateFailureStreak::where('site_id', $site->id)
            ->where('target_slug', 'stubborn-plugin')
            ->first();

        expect($streak)->not->toBeNull()
            ->and($streak->consecutive_failures)->toBe(5)
            ->and($streak->ignored_at)->not->toBeNull()
            ->and($streak->ignore_id)->not->toBeNull();

        $ignore = PluginUpdateIgnore::where('site_id', $site->id)
            ->where('target_slug', 'stubborn-plugin')
            ->first();

        expect($ignore)->not->toBeNull()
            ->and($ignore->source)->toBe(PluginUpdateIgnore::SOURCE_AUTO_FAILURE)
            ->and($ignore->client_visible)->toBeTrue()
            ->and($ignore->failure_count)->toBe(5)
            ->and($ignore->isAutoFailure())->toBeTrue()
            ->and($ignore->isManual())->toBeFalse();

        // Check ActionLog was recorded for auto-ignore
        $actionLog = ActionLog::where('action_type', ActionLog::TYPE_PLUGIN_UPDATE_AUTO_IGNORED)
            ->where('site_id', $site->id)
            ->where('target', 'stubborn-plugin')
            ->first();

        expect($actionLog)->not->toBeNull()
            ->and($actionLog->ok)->toBeTrue()
            ->and($actionLog->details['streak'])->toBe(5);
    });

    it('nightly updates loop skips auto-ignored plugins on subsequent runs', function () {
        Queue::fake();

        $site = makeExampleSite([
            'companion_installed' => true,
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'stubborn-plugin', 'name' => 'Stubborn Plugin', 'version' => '1.0.0', 'new_version' => '1.1.0', 'update_available' => true],
                    ],
                ],
                'themes' => ['items' => []],
            ],
        ]);

        PluginUpdateIgnore::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'stubborn-plugin',
            'source' => PluginUpdateIgnore::SOURCE_AUTO_FAILURE,
            'failure_count' => 5,
            'client_visible' => true,
            'note' => 'Auto-ignored',
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertNothingPushed();
        expect(PluginUpdateJob::where('site_id', $site->id)->where('target_slug', 'stubborn-plugin')->count())->toBe(0);
    });

    it('resets streak to 0 on any successful update after prior failures', function () {
        $site = makeExampleSite();

        // 3 failures then 1 success
        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/update" => Http::sequence()
                ->push([
                    'ok' => false,
                    'error' => 'Temporary failure',
                    'slug' => 'stubborn-plugin',
                    'before_version' => '1.0.0',
                    'after_version' => '1.0.0',
                    'was_active' => true,
                    'reactivated' => true,
                ], 200)
                ->push([
                    'ok' => false,
                    'error' => 'Temporary failure',
                    'slug' => 'stubborn-plugin',
                    'before_version' => '1.0.0',
                    'after_version' => '1.0.0',
                    'was_active' => true,
                    'reactivated' => true,
                ], 200)
                ->push([
                    'ok' => false,
                    'error' => 'Temporary failure',
                    'slug' => 'stubborn-plugin',
                    'before_version' => '1.0.0',
                    'after_version' => '1.0.0',
                    'was_active' => true,
                    'reactivated' => true,
                ], 200)
                ->push([
                    'ok' => true,
                    'slug' => 'stubborn-plugin',
                    'before_version' => '1.0.0',
                    'after_version' => '1.1.0',
                    'was_active' => true,
                    'reactivated' => true,
                    'elapsed_ms' => 500,
                ], 200),
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $job = fakeNightlyJob($site, 'stubborn-plugin');
            (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));
        }

        $streak = PluginUpdateFailureStreak::where('site_id', $site->id)
            ->where('target_slug', 'stubborn-plugin')
            ->first();
        expect($streak->consecutive_failures)->toBe(3);

        $successJob = fakeNightlyJob($site, 'stubborn-plugin');
        (new RunPluginUpdate($successJob->id))->handle(app(ActionLogger::class));

        $streak->refresh();
        expect($streak->consecutive_failures)->toBe(0)
            ->and($streak->ignored_at)->toBeNull();

        expect(PluginUpdateIgnore::where('site_id', $site->id)
            ->where('target_slug', 'stubborn-plugin')
            ->exists())->toBeFalse();
    });

    it('does not increment streak on transport timeout / connection exception', function () {
        $site = makeExampleSite();

        $job = fakeNightlyJob($site, 'stubborn-plugin');

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/update" => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
            },
        ]);

        (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));

        $job->refresh();
        expect($job->status)->toBe(PluginUpdateJob::STATUS_FAILED)
            ->and($job->error)->toContain('timed out');

        // Streak table should have no row, or consecutive_failures remains 0
        $streak = PluginUpdateFailureStreak::where('site_id', $site->id)
            ->where('target_slug', 'stubborn-plugin')
            ->first();

        expect($streak)->toBeNull();
    });

    it('does not increment streak on manual update failure, but manual success resets streak', function () {
        $site = makeExampleSite();
        $user = User::factory()->create();

        // Seed an existing streak of 2
        $streak = PluginUpdateFailureStreak::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'stubborn-plugin',
            'consecutive_failures' => 2,
        ]);

        // Manual failure (requested_by_user_id is not null)
        $manualFailJob = PluginUpdateJob::factory()->for($site)->create([
            'batch_id' => (string) Str::uuid(),
            'requested_by_user_id' => $user->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'stubborn-plugin',
            'target_name' => 'Stubborn Plugin',
            'before_version' => '1.0.0',
            'target_version' => '1.1.0',
            'status' => PluginUpdateJob::STATUS_PENDING,
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/update" => Http::sequence()
                ->push([
                    'ok' => false,
                    'error' => 'Manual run failed',
                    'slug' => 'stubborn-plugin',
                    'before_version' => '1.0.0',
                    'after_version' => '1.0.0',
                    'was_active' => true,
                    'reactivated' => true,
                ], 200)
                ->push([
                    'ok' => true,
                    'slug' => 'stubborn-plugin',
                    'before_version' => '1.0.0',
                    'after_version' => '1.1.0',
                    'was_active' => true,
                    'reactivated' => true,
                    'elapsed_ms' => 450,
                ], 200),
        ]);

        (new RunPluginUpdate($manualFailJob->id))->handle(app(ActionLogger::class));

        // Streak must remain 2 (manual failures do not increment streak)
        $streak->refresh();
        expect($streak->consecutive_failures)->toBe(2);

        // Manual success resets streak to 0
        $manualSuccessJob = PluginUpdateJob::factory()->for($site)->create([
            'batch_id' => (string) Str::uuid(),
            'requested_by_user_id' => $user->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'stubborn-plugin',
            'target_name' => 'Stubborn Plugin',
            'before_version' => '1.0.0',
            'target_version' => '1.1.0',
            'status' => PluginUpdateJob::STATUS_PENDING,
        ]);

        (new RunPluginUpdate($manualSuccessJob->id))->handle(app(ActionLogger::class));

        $streak->refresh();
        expect($streak->consecutive_failures)->toBe(0);
    });

    it('manual ignore sets source=manual and client_visible=false', function () {
        $site = makeExampleSite();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('updates.bulkIgnore'), [
            'targets' => ["plugin:{$site->id}:manual-ignore-plugin"],
            'note' => 'Operator testing pause',
        ]);

        $response->assertRedirect();

        $ignore = PluginUpdateIgnore::where('site_id', $site->id)
            ->where('target_slug', 'manual-ignore-plugin')
            ->first();

        expect($ignore)->not->toBeNull()
            ->and($ignore->source)->toBe(PluginUpdateIgnore::SOURCE_MANUAL)
            ->and($ignore->client_visible)->toBeFalse()
            ->and($ignore->isManual())->toBeTrue()
            ->and($ignore->isAutoFailure())->toBeFalse();

        // Not included in buildExceptionsPayload because client_visible is false
        $recorder = app(UpdateFailureStreakRecorder::class);
        $payload = $recorder->buildExceptionsPayload($site);
        expect($payload['items'])->toBeEmpty();
    });

    it('bulk unignore / resume deletes ignore, resets streak to 0, and pushes updated exceptions', function () {
        $site = makeExampleSite(['companion_installed' => true]);
        $user = User::factory()->create();

        $ignore = PluginUpdateIgnore::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'resumed-plugin',
            'source' => PluginUpdateIgnore::SOURCE_AUTO_FAILURE,
            'failure_count' => 5,
            'client_visible' => true,
            'note' => 'Paused after 5 failures',
        ]);

        $streak = PluginUpdateFailureStreak::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'resumed-plugin',
            'consecutive_failures' => 5,
            'ignored_at' => now(),
            'ignore_id' => $ignore->id,
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/update-exceptions" => Http::response(['ok' => true], 200),
        ]);

        $response = $this->actingAs($user)->post(route('updates.bulkUnignore'), [
            'targets' => ["plugin:{$site->id}:resumed-plugin"],
        ]);

        $response->assertRedirect();

        // Ignore deleted
        expect(PluginUpdateIgnore::where('id', $ignore->id)->exists())->toBeFalse();

        // Streak reset
        $streak->refresh();
        expect($streak->consecutive_failures)->toBe(0)
            ->and($streak->ignored_at)->toBeNull()
            ->and($streak->ignore_id)->toBeNull();

        // Empty exceptions pushed to site
        Http::assertSent(function ($request) use ($site) {
            return $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/update-exceptions"
                && isset($request['items'])
                && count($request['items']) === 0;
        });
    });

    it('honors configurable threshold setting (e.g. 3 failures instead of 5)', function () {
        $site = makeExampleSite();

        app(Settings::class)->put('updates.auto_ignore_after_failures', 3);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/update" => Http::response([
                'ok' => false,
                'error' => 'Failure',
                'slug' => 'fast-ignore-plugin',
                'before_version' => '1.0.0',
                'after_version' => '1.0.0',
                'was_active' => true,
                'reactivated' => true,
            ], 200),
            "https://{$site->domain}/wp-json/clockwork/v1/update-exceptions" => Http::response(['ok' => true], 200),
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $job = fakeNightlyJob($site, 'fast-ignore-plugin');
            (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));
        }

        $ignore = PluginUpdateIgnore::where('site_id', $site->id)
            ->where('target_slug', 'fast-ignore-plugin')
            ->first();

        expect($ignore)->not->toBeNull()
            ->and($ignore->source)->toBe(PluginUpdateIgnore::SOURCE_AUTO_FAILURE)
            ->and($ignore->failure_count)->toBe(3);
    });

    it('honors kill switch updates.auto_ignore_enabled=false', function () {
        $site = makeExampleSite();

        app(Settings::class)->put('updates.auto_ignore_enabled', false);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/update" => Http::response([
                'ok' => false,
                'error' => 'Failure',
                'slug' => 'no-ignore-plugin',
                'before_version' => '1.0.0',
                'after_version' => '1.0.0',
                'was_active' => true,
                'reactivated' => true,
            ], 200),
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $job = fakeNightlyJob($site, 'no-ignore-plugin');
            (new RunPluginUpdate($job->id))->handle(app(ActionLogger::class));
        }

        $streak = PluginUpdateFailureStreak::where('site_id', $site->id)
            ->where('target_slug', 'no-ignore-plugin')
            ->first();

        // Consecutive failures still counts up for operator visibility
        expect($streak->consecutive_failures)->toBe(6)
            ->and($streak->ignored_at)->toBeNull();

        // But no ignore row is created because kill switch is disabled
        expect(PluginUpdateIgnore::where('site_id', $site->id)
            ->where('target_slug', 'no-ignore-plugin')
            ->exists())->toBeFalse();
    });

    it('excludes sites from urgent issues count when all pending updates are ignored', function () {
        $site = makeExampleSite([
            'companion_snapshot' => [
                'plugins' => [
                    'counts' => ['updates_available' => 1],
                    'plugins' => [
                        ['slug' => 'ignored-plugin', 'name' => 'Ignored Plugin', 'version' => '1.0.0', 'new_version' => '1.1.0', 'update_available' => true],
                    ],
                ],
                'themes' => ['items' => []],
            ],
        ]);

        $counter = app(IssueCounter::class);

        // Before ignore: countPluginsOutdated should be 1
        expect($counter->countPluginsOutdated())->toBe(1);

        // Add auto-ignore for the only pending plugin
        PluginUpdateIgnore::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'ignored-plugin',
            'source' => PluginUpdateIgnore::SOURCE_AUTO_FAILURE,
            'failure_count' => 5,
            'client_visible' => true,
        ]);

        // After ignore: the site has no unignored pending updates, so countPluginsOutdated drops to 0
        expect($counter->countPluginsOutdated())->toBe(0);
    });

    it('does not increment when Companion succeeded but the job was later marked failed', function () {
        $site = makeExampleSite();
        $job = fakeNightlyJob($site, 'ok-plugin', [
            'status' => PluginUpdateJob::STATUS_FAILED,
            'error' => 'Action log write failed after a successful upgrade',
        ]);

        app(UpdateFailureStreakRecorder::class)->record($job, [
            'ok' => true,
            'slug' => 'ok-plugin',
            'before_version' => '1.0.0',
            'after_version' => '1.1.0',
        ]);

        expect(PluginUpdateFailureStreak::where('site_id', $site->id)
            ->where('target_slug', 'ok-plugin')
            ->exists())->toBeFalse();
    });

    it('increments streak on a stalled no-op even when Companion reported ok=true', function () {
        $site = makeExampleSite();
        $job = fakeNightlyJob($site, 'stalled-plugin', [
            'status' => PluginUpdateJob::STATUS_FAILED,
            'target_version' => '9.0.1',
            'error' => "WordPress's own update-checker no longer offered this update when the job ran.",
        ]);

        app(UpdateFailureStreakRecorder::class)->record($job, [
            'ok' => true,
            'slug' => 'stalled-plugin',
            'before_version' => '8.7.2',
            'after_version' => '8.7.2',
            'error' => "WordPress's own update-checker no longer offered this update when the job ran.",
        ]);

        $streak = PluginUpdateFailureStreak::where('site_id', $site->id)
            ->where('target_slug', 'stalled-plugin')
            ->first();

        expect($streak)->not->toBeNull()
            ->and($streak->consecutive_failures)->toBe(1);
    });

    it('does not convert a manual ignore into a client-visible auto-ignore', function () {
        $site = makeExampleSite();

        PluginUpdateIgnore::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'held-plugin',
            'source' => PluginUpdateIgnore::SOURCE_MANUAL,
            'client_visible' => false,
            'note' => 'Hold this one',
        ]);

        $job = fakeNightlyJob($site, 'held-plugin', [
            'status' => PluginUpdateJob::STATUS_FAILED,
            'error' => 'Plugin update failed.',
        ]);

        $recorder = app(UpdateFailureStreakRecorder::class);
        for ($i = 1; $i <= 5; $i++) {
            $recorder->record($job, [
                'ok' => false,
                'error' => 'Plugin update failed.',
                'slug' => 'held-plugin',
            ]);
        }

        $ignore = PluginUpdateIgnore::where('site_id', $site->id)
            ->where('target_slug', 'held-plugin')
            ->first();

        expect($ignore)->not->toBeNull()
            ->and($ignore->source)->toBe(PluginUpdateIgnore::SOURCE_MANUAL)
            ->and($ignore->client_visible)->toBeFalse();

        $payload = $recorder->buildExceptionsPayload($site);
        expect($payload['items'])->toBeEmpty();
    });

    it('pushes an updated exceptions list when an auto-ignore is converted to a manual ignore', function () {
        $site = makeExampleSite(['companion_installed' => true]);
        $user = User::factory()->create();

        PluginUpdateIgnore::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'was-auto',
            'source' => PluginUpdateIgnore::SOURCE_AUTO_FAILURE,
            'failure_count' => 5,
            'client_visible' => true,
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/update-exceptions" => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($user)->post(route('updates.bulkIgnore'), [
            'targets' => ["plugin:{$site->id}:was-auto"],
            'note' => 'Taking this private',
        ])->assertRedirect();

        $ignore = PluginUpdateIgnore::where('site_id', $site->id)
            ->where('target_slug', 'was-auto')
            ->first();

        expect($ignore->source)->toBe(PluginUpdateIgnore::SOURCE_MANUAL)
            ->and($ignore->client_visible)->toBeFalse();

        Http::assertSent(function ($request) use ($site) {
            return $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/update-exceptions"
                && isset($request['items'])
                && count($request['items']) === 0;
        });
    });
});
