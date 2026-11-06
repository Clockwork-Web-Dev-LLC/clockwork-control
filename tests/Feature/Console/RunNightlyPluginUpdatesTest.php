<?php

namespace Tests\Feature\Console;

use App\Console\Commands\RunNightlyPluginUpdates;
use App\Jobs\RunPluginUpdate;
use App\Jobs\RunThemeUpdate;
use App\Models\ActionLog;
use App\Models\PluginUpdateFailureStreak;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\PluginVulnerability;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Services\Updates\ThemeIdentityGuard;
use App\Support\UpgraderOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:run-nightly-plugin-updates
|--------------------------------------------------------------------------
|
| RunPluginUpdate/RunThemeUpdate's own execution lifecycle (locking, status
| transitions, ActionLog writes, chat gating) is already covered via
| tests/Feature/Jobs/UpdateJobPipelineTest.php — this file is only about
| the orchestrator: does it correctly enumerate eligible care-plan sites
| from companion_snapshot, build the right (site, kind, slug) tuples, filter
| ignored/already-live ones, order vulnerable-first, and dispatch the right
| job class per kind. Queue::fake() throughout so no job body ever runs
| (queue connection is `sync` in phpunit.xml, so an unfaked dispatch would
| execute inline and try to hit ClockworkCompanionClient for real).
*/

function nightlySite(array $overrides = []): Site
{
    return Site::factory()->withCompanionInstalled()->create(array_merge([
        'care_plan_enabled' => true,
        'auto_updates_paused' => false,
        'wp_plugin_updates' => false,
        'wp_theme_updates' => false,
        'auto_updates_last_run_at' => null,
    ], $overrides));
}

function pluginSnapshot(array $plugins): array
{
    return [
        'plugins' => ['plugins' => $plugins],
        'themes' => ['items' => []],
    ];
}

function themeSnapshot(array $themes): array
{
    return [
        'plugins' => ['plugins' => []],
        'themes' => ['items' => $themes],
    ];
}

// Theme tuples are checked against the wordpress.org theme directory
// (ThemeIdentityGuard). A test lists a theme there by adding its slug to
// $this->themeDirectory; any other slug is not listed, as for a premium
// theme with its own updater. $this->themeDirectoryDown makes it fail.
beforeEach(function () {
    $this->themeDirectory = [];
    $this->themeDirectoryDown = false;
    Http::fake(['api.wordpress.org/themes/*' => function ($request) {
        if ($this->themeDirectoryDown) {
            return Http::response('', 503);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $listing = $this->themeDirectory[$query['request']['slug'] ?? ''] ?? null;

        return $listing === null
            ? Http::response(['error' => 'Theme not found'], 404)
            : Http::response($listing);
    }]);
});

describe('happy path: enumerating and dispatching', function () {
    it('queues a PluginUpdateJob and dispatches RunPluginUpdate for a site with a pending plugin update', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'contact-form-7', 'name' => 'Contact Form 7', 'version' => '5.9', 'new_version' => '6.0', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        $row = PluginUpdateJob::query()->where('site_id', $site->id)->first();
        expect($row)->not->toBeNull()
            ->and($row->target_kind)->toBe(PluginUpdateJob::KIND_PLUGIN)
            ->and($row->target_slug)->toBe('contact-form-7')
            ->and($row->status)->toBe(PluginUpdateJob::STATUS_PENDING)
            ->and($row->batch_id)->toStartWith('nightly-');

        Queue::assertPushed(RunPluginUpdate::class, fn ($job) => $job->jobRowId === $row->id);
        Queue::assertNotPushed(RunThemeUpdate::class);

        expect($site->refresh()->auto_updates_last_run_at)->not->toBeNull();
    });

    it('queues a PluginUpdateJob and dispatches RunThemeUpdate for a site with a pending theme update', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'twentytwentyfour', 'name' => 'Twenty Twenty-Four', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        $row = PluginUpdateJob::query()->where('site_id', $site->id)->first();
        expect($row)->not->toBeNull()
            ->and($row->target_kind)->toBe(PluginUpdateJob::KIND_THEME)
            ->and($row->target_slug)->toBe('twentytwentyfour');

        Queue::assertPushed(RunThemeUpdate::class, fn ($job) => $job->jobRowId === $row->id);
        Queue::assertNotPushed(RunPluginUpdate::class);
    });

    it('is a no-op that still stamps eligible sites when nothing is pending', function () {
        Queue::fake();

        $site = nightlySite();

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        expect(PluginUpdateJob::query()->count())->toBe(0);
        Queue::assertNothingPushed();
        expect($site->refresh()->auto_updates_last_run_at)->not->toBeNull();
    });

    it('excludes sites that are not care-plan enabled, paused, or without Companion installed', function () {
        Queue::fake();

        $snapshot = pluginSnapshot([
            ['slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.0', 'new_version' => '5.1', 'update_available' => true],
        ]);

        $notCarePlan = nightlySite(['care_plan_enabled' => false, 'wp_plugin_updates' => true, 'companion_snapshot' => $snapshot]);
        $paused = nightlySite(['auto_updates_paused' => true, 'wp_plugin_updates' => true, 'companion_snapshot' => $snapshot]);
        $noCompanion = Site::factory()->create([
            'care_plan_enabled' => true,
            'auto_updates_paused' => false,
            'companion_installed' => false,
            'wp_plugin_updates' => true,
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        expect(PluginUpdateJob::query()->count())->toBe(0);
        Queue::assertNothingPushed();
        // Excluded sites are also excluded from the stamping query (same
        // base filters), so none of them should get a heartbeat either.
        expect($notCarePlan->refresh()->auto_updates_last_run_at)->toBeNull()
            ->and($paused->refresh()->auto_updates_last_run_at)->toBeNull()
            ->and($noCompanion->refresh()->auto_updates_last_run_at)->toBeNull();
    });
});

describe('filtering', function () {
    it('drops a tuple that has a matching PluginUpdateIgnore row and does not dispatch it', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'ignored-plugin', 'name' => 'Ignored Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);

        PluginUpdateIgnore::factory()->create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'ignored-plugin',
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('drops a tuple that already has a live (pending/running) PluginUpdateJob for the same site/kind/slug', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'already-live', 'name' => 'Already Live', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);

        $existing = PluginUpdateJob::factory()->for($site)->create([
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'already-live',
            'status' => PluginUpdateJob::STATUS_PENDING,
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        // Only the pre-existing row exists — nothing new was queued.
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(1)
            ->and(PluginUpdateJob::query()->where('site_id', $site->id)->first()->id)->toBe($existing->id);
        Queue::assertNothingPushed();
    });
});

describe('CVE-priority ordering', function () {
    it('dispatches the vulnerable plugin first, matching the snapshot\'s dir/file.php slug to the advisory\'s directory slug', function () {
        Queue::fake();

        // Advisories key on the plugin directory; Companion snapshots use
        // "directory/file.php". The vulnerable plugin sits on the
        // alphabetically later site and after a safe plugin, so only the
        // CVE priority can put it first.
        PluginVulnerability::factory()->create([
            'slug' => 'vuln-plugin',
            'from_version' => '0',
            'to_version' => '1.0',
            'to_inclusive' => true,
            'patched_in' => '1.1',
        ]);

        $first = nightlySite([
            'domain' => 'aaa.example.com',
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'safe-plugin/safe-plugin.php', 'name' => 'Safe Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);
        $second = nightlySite([
            'domain' => 'zzz.example.com',
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'other-plugin/other-plugin.php', 'name' => 'Other Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
                ['slug' => 'vuln-plugin/vuln-plugin.php', 'name' => 'Vuln Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')
            ->expectsOutputToContain('(1 vulnerable-first)')
            ->assertSuccessful();

        $dispatched = Queue::pushed(RunPluginUpdate::class)
            ->map(fn ($job) => PluginUpdateJob::find($job->jobRowId))
            ->values();

        expect($dispatched->map(fn ($row) => [$row->site_id, $row->target_slug])->all())->toBe([
            [$second->id, 'vuln-plugin/vuln-plugin.php'],
            [$first->id, 'safe-plugin/safe-plugin.php'],
            [$second->id, 'other-plugin/other-plugin.php'],
        ]);

        // One batch for the whole night.
        expect($dispatched->pluck('batch_id')->unique())->toHaveCount(1);
    });

    it('marks the vulnerable plugin in the dry-run listing', function () {
        Queue::fake();

        PluginVulnerability::factory()->create([
            'slug' => 'vuln-plugin',
            'from_version' => '0',
            'to_version' => '1.0',
            'to_inclusive' => true,
            'patched_in' => '1.1',
        ]);

        nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'vuln-plugin/vuln-plugin.php', 'name' => 'Vuln Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates', ['--dry-run' => true])
            ->expectsOutputToContain('!CVE')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    });
});

/**
 * Seed an auto-failure pause the way UpdateFailureStreakRecorder leaves it:
 * an auto_failure ignore row plus a streak recording the version that kept
 * failing. Pass null to leave the streak out.
 */
function pauseAfterFailures(
    Site $site,
    string $slug,
    ?string $failedVersion,
    string $kind = PluginUpdateJob::KIND_PLUGIN,
    ?Carbon $lastFailedAt = null,
    ?string $reasonCode = null,
): PluginUpdateIgnore {
    $ignore = PluginUpdateIgnore::create([
        'site_id' => $site->id,
        'target_kind' => $kind,
        'target_slug' => $slug,
        'source' => PluginUpdateIgnore::SOURCE_AUTO_FAILURE,
        'failure_count' => 5,
        'client_visible' => true,
        'note' => 'Automatically paused after 5 consecutive nightly update failures.',
    ]);

    if ($failedVersion !== null) {
        PluginUpdateFailureStreak::create([
            'site_id' => $site->id,
            'target_kind' => $kind,
            'target_slug' => $slug,
            'consecutive_failures' => 5,
            'last_from_version' => '1.0.0',
            'last_target_version' => $failedVersion,
            'last_failed_at' => $lastFailedAt ?? now()->subDay(),
            'reason_code' => $reasonCode,
            'ignored_at' => now()->subDay(),
            'ignore_id' => $ignore->id,
        ]);
    }

    return $ignore;
}

function vulnerableAdvisory(string $dirSlug, string $affectedUpTo, string $patchedIn): PluginVulnerability
{
    return PluginVulnerability::factory()->create([
        'slug' => $dirSlug,
        'from_version' => '0',
        'from_inclusive' => true,
        'to_version' => $affectedUpTo,
        'to_inclusive' => true,
        'patched_in' => $patchedIn,
    ]);
}

describe('auto-failure pauses', function () {
    it('keeps an auto-paused plugin paused while the version that kept failing is still the one offered', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'stuck-plugin/stuck-plugin.php', 'name' => 'Stuck Plugin', 'version' => '1.0.0', 'new_version' => '1.1.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'stuck-plugin/stuck-plugin.php', '1.1.0');

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('retries an auto-paused plugin once a newer release than the failing version is offered, leaving the pause in place', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'stuck-plugin/stuck-plugin.php', 'name' => 'Stuck Plugin', 'version' => '1.0.0', 'new_version' => '1.2.0', 'update_available' => true],
            ]),
        ]);
        $ignore = pauseAfterFailures($site, 'stuck-plugin/stuck-plugin.php', '1.1.0');

        $this->artisan('clockwork:run-nightly-plugin-updates')
            ->expectsOutputToContain('Retrying 1 auto-paused update (1 new release, 0 security fix)')
            ->assertSuccessful();

        $row = PluginUpdateJob::query()->where('site_id', $site->id)->sole();
        expect($row->target_slug)->toBe('stuck-plugin/stuck-plugin.php')
            ->and($row->target_version)->toBe('1.2.0')
            ->and($row->batch_id)->toStartWith('nightly-');
        Queue::assertPushed(RunPluginUpdate::class, fn ($job) => $job->jobRowId === $row->id);

        // Only a successful update lifts the pause.
        expect(PluginUpdateIgnore::query()->whereKey($ignore->id)->exists())->toBeTrue();
    });

    it('retries an auto-paused theme once a newer release is offered', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'stuck-theme', 'name' => 'Stuck Theme', 'version' => '2.0', 'new_version' => '2.2', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'stuck-theme', '2.1', PluginUpdateJob::KIND_THEME);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        $row = PluginUpdateJob::query()->where('site_id', $site->id)->sole();
        expect($row->target_kind)->toBe(PluginUpdateJob::KIND_THEME)
            ->and($row->target_version)->toBe('2.2');
        Queue::assertPushed(RunThemeUpdate::class, fn ($job) => $job->jobRowId === $row->id);
    });

    it('retries an auto-paused vulnerable plugin when the offered version contains the fix, even if that version failed before, once the back-off has passed', function () {
        Queue::fake();

        vulnerableAdvisory('vuln-plugin', '1.0.0', '1.1.0');

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'vuln-plugin/vuln-plugin.php', 'name' => 'Vuln Plugin', 'version' => '1.0.0', 'new_version' => '1.1.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'vuln-plugin/vuln-plugin.php', '1.1.0', lastFailedAt: now()->subDays(RunNightlyPluginUpdates::SECURITY_RETRY_BACKOFF_DAYS + 1));

        $this->artisan('clockwork:run-nightly-plugin-updates')
            ->expectsOutputToContain('Retrying 1 auto-paused update (0 new release, 1 security fix)')
            ->assertSuccessful();

        $row = PluginUpdateJob::query()->where('site_id', $site->id)->sole();
        expect($row->target_version)->toBe('1.1.0');
        Queue::assertPushed(RunPluginUpdate::class, 1);
    });

    it('keeps a security fix paused when that same version already failed within the back-off', function () {
        Queue::fake();

        vulnerableAdvisory('vuln-plugin', '1.0.0', '1.1.0');
        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'vuln-plugin/vuln-plugin.php', 'name' => 'Vuln Plugin', 'version' => '1.0.0', 'new_version' => '1.1.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'vuln-plugin/vuln-plugin.php', '1.1.0', lastFailedAt: now()->subDay());

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('never retries the same security fix version that failed for a licence or offer reason, however long ago', function (string $reason) {
        Queue::fake();

        vulnerableAdvisory('vuln-plugin', '1.0.0', '1.1.0');
        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'vuln-plugin/vuln-plugin.php', 'name' => 'Vuln Plugin', 'version' => '1.0.0', 'new_version' => '1.1.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'vuln-plugin/vuln-plugin.php', '1.1.0', lastFailedAt: now()->subDays(30), reasonCode: $reason);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertNothingPushed();
    })->with([UpgraderOutput::REASON_DOWNLOAD_UNAUTHORIZED, UpgraderOutput::REASON_NO_PACKAGE, UpgraderOutput::REASON_NOT_OFFERED]);

    it('tries a newer security fix version at once, whatever the last failure was', function () {
        Queue::fake();

        vulnerableAdvisory('vuln-plugin', '1.0.0', '1.1.0');
        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'vuln-plugin/vuln-plugin.php', 'name' => 'Vuln Plugin', 'version' => '1.0.0', 'new_version' => '1.2.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'vuln-plugin/vuln-plugin.php', '1.1.0', lastFailedAt: now()->subDay(), reasonCode: UpgraderOutput::REASON_DOWNLOAD_UNAUTHORIZED);

        $this->artisan('clockwork:run-nightly-plugin-updates')
            ->expectsOutputToContain('Retrying 1 auto-paused update (0 new release, 1 security fix)')
            ->assertSuccessful();

        expect(PluginUpdateJob::query()->where('site_id', $site->id)->sole()->target_version)->toBe('1.2.0');
    });

    it('tries a security fix only once a week when every retry fails, not every night', function () {
        Queue::fake();

        vulnerableAdvisory('vuln-plugin', '1.0.0', '1.1.0');
        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'vuln-plugin/vuln-plugin.php', 'name' => 'Vuln Plugin', 'version' => '1.0.0', 'new_version' => '1.1.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'vuln-plugin/vuln-plugin.php', '1.0.5', lastFailedAt: now()->subDay());

        $queuedOn = [];
        for ($night = 0; $night < 9; $night++) {
            $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();
            if (PluginUpdateJob::query()->where('site_id', $site->id)->where('status', PluginUpdateJob::STATUS_PENDING)->exists()) {
                $queuedOn[] = $night;
            }
            failAllNightlyJobs();
            $this->travel(1)->days();
        }

        // Night 0 is the first try of 1.1.0; the next is a week later.
        expect($queuedOn)->toBe([0, RunNightlyPluginUpdates::SECURITY_RETRY_BACKOFF_DAYS]);
    });

    it('keeps a vulnerable plugin paused when the offered version is below the highest patched_in', function () {
        Queue::fake();

        // Two advisories hit 1.0.0; the offered 1.5.0 fixes only the first.
        vulnerableAdvisory('vuln-plugin', '1.4.0', '1.5.0');
        vulnerableAdvisory('vuln-plugin', '1.9.0', '2.0.0');

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'vuln-plugin/vuln-plugin.php', 'name' => 'Vuln Plugin', 'version' => '1.0.0', 'new_version' => '1.5.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'vuln-plugin/vuln-plugin.php', '1.5.0');

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('keeps an auto-pause that has no recorded failing version', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'stuck-plugin/stuck-plugin.php', 'name' => 'Stuck Plugin', 'version' => '1.0.0', 'new_version' => '1.2.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'stuck-plugin/stuck-plugin.php', null);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('records a queued new-release retry as that version\'s attempt', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'stuck-plugin/stuck-plugin.php', 'name' => 'Stuck Plugin', 'version' => '1.0.0', 'new_version' => '1.2.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'stuck-plugin/stuck-plugin.php', '1.1.0');

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        $streak = PluginUpdateFailureStreak::query()->where('site_id', $site->id)->sole();
        expect($streak->last_target_version)->toBe('1.2.0')
            ->and($streak->last_from_version)->toBe('1.0.0')
            ->and($streak->last_retry_queued_at)->not->toBeNull()
            // Queueing isn't a failure.
            ->and($streak->consecutive_failures)->toBe(5);
    });

    it('tries a new release once, even when the retry ends without a counted failure', function (string $ending) {
        Queue::fake();
        $this->mock(ChatNotifier::class)->shouldIgnoreMissing();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_secret' => 'example-secret-for-tests',
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'stuck-plugin/stuck-plugin.php', 'name' => 'Stuck Plugin', 'version' => '1.0.0', 'new_version' => '2.1.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'stuck-plugin/stuck-plugin.php', '2.0.0');

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();
        $row = PluginUpdateJob::query()->where('site_id', $site->id)->sole();

        match ($ending) {
            // Companion answered 504: a transport error, never counted.
            'transport' => (function () use ($site, $row) {
                Http::fake(["https://{$site->domain}/wp-json/clockwork/v1/plugins/update*" => Http::response(['message' => 'gateway timeout'], 504)]);
                (new RunPluginUpdate($row->id))->handle(app(ActionLogger::class));
            })(),
            // The worker timed out: only failed() runs.
            'timeout' => (new RunPluginUpdate($row->id))->failed(new \RuntimeException('timed out')),
            // Never picked up: the reaper marks it failed.
            'reaped' => (function () use ($row) {
                $row->forceFill(['queued_at' => now()->subHours(2)])->save();
                $this->artisan('clockwork:reap-stale-update-jobs')->assertSuccessful();
            })(),
        };

        expect($row->fresh()->status)->toBe(PluginUpdateJob::STATUS_FAILED);

        $this->travel(1)->days();
        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(1);
    })->with(['transport', 'timeout', 'reaped']);

    it('never retries a manually ignored plugin, even for a new release that fixes a vulnerability', function () {
        Queue::fake();

        vulnerableAdvisory('held-plugin', '1.0.0', '1.1.0');

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'held-plugin/held-plugin.php', 'name' => 'Held Plugin', 'version' => '1.0.0', 'new_version' => '1.2.0', 'update_available' => true],
            ]),
        ]);
        PluginUpdateIgnore::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'held-plugin/held-plugin.php',
            'source' => PluginUpdateIgnore::SOURCE_MANUAL,
            'client_visible' => false,
            'note' => 'Hold for now',
        ]);
        PluginUpdateFailureStreak::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'held-plugin/held-plugin.php',
            'consecutive_failures' => 5,
            'last_target_version' => '1.1.0',
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(0);
        Queue::assertNothingPushed();
    });
});

/**
 * Mark every queued job failed so nothing is live: a second run would then
 * re-queue everything if it were allowed to.
 */
function failAllNightlyJobs(): void
{
    PluginUpdateJob::query()->update(['status' => PluginUpdateJob::STATUS_FAILED]);
}

function pendingUpdateSite(): Site
{
    return nightlySite([
        'wp_plugin_updates' => true,
        'companion_snapshot' => pluginSnapshot([
            ['slug' => 'some-plugin/some-plugin.php', 'name' => 'Some Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
        ]),
    ]);
}

describe('once per local night', function () {
    it('runs only once on the DST fall-back night, when 01:30 local happens twice', function () {
        Queue::fake();
        $site = pendingUpdateSite();

        // 01:45 EDT, before the clocks go back.
        $this->travelTo(Carbon::parse('2026-11-01 05:45:00', 'UTC'));
        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();
        $firstBatch = PluginUpdateJob::query()->where('site_id', $site->id)->sole()->batch_id;
        expect($firstBatch)->toStartWith('nightly-2026-11-01-');
        failAllNightlyJobs();

        // 01:45 EST, an hour later: the scheduler fires the chain again.
        $this->travelTo(Carbon::parse('2026-11-01 06:45:00', 'UTC'));
        $this->artisan('clockwork:run-nightly-plugin-updates')
            ->expectsOutputToContain("Nightly batch {$firstBatch} already ran for 2026-11-01")
            ->assertSuccessful();
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(1);

        // The next night runs normally.
        $this->travelTo(Carbon::parse('2026-11-02 06:45:00', 'UTC'));
        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(2)
            ->and(PluginUpdateJob::query()->latest('id')->first()->batch_id)->toStartWith('nightly-2026-11-02-');
        Queue::assertPushed(RunPluginUpdate::class, 2);
    });

    it('decides "today" by the local date, not the UTC date', function () {
        Queue::fake();
        $site = pendingUpdateSite();

        // 21:30 EST on Nov 2 is already Nov 3 in UTC.
        $this->travelTo(Carbon::parse('2026-11-03 02:30:00', 'UTC'));
        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->sole()->batch_id)->toStartWith('nightly-2026-11-02-');
        failAllNightlyJobs();

        // 01:40 EST on Nov 3 is a new local night, so it runs.
        $this->travelTo(Carbon::parse('2026-11-03 06:40:00', 'UTC'));
        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(2)
            ->and(PluginUpdateJob::query()->latest('id')->first()->batch_id)->toStartWith('nightly-2026-11-03-');
    });

    it('re-runs with --force, and only reports the skip under --dry-run', function () {
        Queue::fake();
        $site = pendingUpdateSite();

        $this->travelTo(Carbon::parse('2026-11-04 06:40:00', 'UTC'));
        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();
        failAllNightlyJobs();

        $this->travelTo(Carbon::parse('2026-11-04 15:00:00', 'UTC'));
        $this->artisan('clockwork:run-nightly-plugin-updates', ['--dry-run' => true])
            ->expectsOutputToContain('a real run would be skipped')
            ->assertSuccessful();
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(1);

        $this->artisan('clockwork:run-nightly-plugin-updates', ['--force' => true])->assertSuccessful();
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(2);
    });

    it('does not guard a --site run, and a --site run does not block the fleet-wide nightly', function () {
        Queue::fake();
        $site = pendingUpdateSite();

        // Operator runs one site just after local midnight.
        $this->travelTo(Carbon::parse('2026-11-04 05:10:00', 'UTC'));
        $this->artisan('clockwork:run-nightly-plugin-updates', ['--site' => (string) $site->id])->assertSuccessful();
        $siteBatch = PluginUpdateJob::query()->where('site_id', $site->id)->sole()->batch_id;
        expect($siteBatch)->toStartWith('nightly-2026-11-04-site-');
        failAllNightlyJobs();

        // The scheduled fleet run that night still goes ahead.
        $this->travelTo(Carbon::parse('2026-11-04 06:40:00', 'UTC'));
        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();
        $fleetBatch = PluginUpdateJob::query()->latest('id')->first()->batch_id;
        expect($fleetBatch)->toStartWith('nightly-2026-11-04-')
            ->and($fleetBatch)->not->toContain('-site-');
        failAllNightlyJobs();

        // A later --site run is still allowed.
        $this->artisan('clockwork:run-nightly-plugin-updates', ['--site' => (string) $site->id])->assertSuccessful();
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(3);
    });
});

describe('--dry-run', function () {
    it('prints candidates but writes nothing to the DB and dispatches nothing', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'dry-run-plugin', 'name' => 'Dry Run Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates', ['--dry-run' => true])->assertSuccessful();

        expect(PluginUpdateJob::query()->count())->toBe(0);
        Queue::assertNothingPushed();
        expect($site->refresh()->auto_updates_last_run_at)->toBeNull();
    });
});

describe('--site filter', function () {
    it('limits the run to a single site given by domain, ignoring other eligible sites', function () {
        Queue::fake();

        $snapshot = pluginSnapshot([
            ['slug' => 'some-plugin', 'name' => 'Some Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
        ]);
        $target = nightlySite(['wp_plugin_updates' => true, 'companion_snapshot' => $snapshot, 'domain' => 'target-site.example.com']);
        $other = nightlySite(['wp_plugin_updates' => true, 'companion_snapshot' => $snapshot]);

        $this->artisan('clockwork:run-nightly-plugin-updates', ['--site' => 'target-site.example.com'])->assertSuccessful();

        expect(PluginUpdateJob::query()->where('site_id', $target->id)->count())->toBe(1)
            ->and(PluginUpdateJob::query()->where('site_id', $other->id)->count())->toBe(0);
    });

    it('limits the run to a single site given by id', function () {
        Queue::fake();

        $snapshot = pluginSnapshot([
            ['slug' => 'some-plugin', 'name' => 'Some Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
        ]);
        $target = nightlySite(['wp_plugin_updates' => true, 'companion_snapshot' => $snapshot]);
        $other = nightlySite(['wp_plugin_updates' => true, 'companion_snapshot' => $snapshot]);

        $this->artisan('clockwork:run-nightly-plugin-updates', ['--site' => (string) $target->id])->assertSuccessful();

        expect(PluginUpdateJob::query()->where('site_id', $target->id)->count())->toBe(1)
            ->and(PluginUpdateJob::query()->where('site_id', $other->id)->count())->toBe(0);
    });
});

describe('theme slug collisions', function () {
    it('holds back a custom child theme whose folder name matches an unrelated wordpress.org theme', function () {
        Queue::fake();
        $this->themeDirectory['studio'] = ['name' => 'Studio', 'slug' => 'studio', 'version' => '2.3.0'];
        $this->mock(ChatNotifier::class, fn ($mock) => $mock->shouldReceive('send')
            ->once()
            ->withArgs(fn (string $title) => str_contains($title, 'Held back a theme update') && str_contains($title, 'Studio Child Theme')));

        $site = nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'studio', 'name' => 'Studio Child Theme', 'version' => '1.0.0', 'new_version' => '2.3.0', 'update_available' => true, 'active' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')
            ->expectsOutputToContain('Holding back 1 theme update')
            ->expectsOutputToContain('held_back=1')
            ->assertSuccessful();

        Queue::assertNotPushed(RunThemeUpdate::class);

        // A skipped row with the reason, in tonight's batch for the summary mail.
        $job = PluginUpdateJob::query()->where('site_id', $site->id)->sole();
        expect($job->status)->toBe(PluginUpdateJob::STATUS_SKIPPED)
            ->and($job->reason_code)->toBe(ThemeIdentityGuard::REASON_CODE)
            ->and($job->batch_id)->toStartWith('nightly-')
            ->and($job->error)->toContain('"Studio Child Theme"')
            ->and($job->error)->toContain('"Studio"');

        // Held from now on, privately, and not as a failure.
        $ignore = PluginUpdateIgnore::query()->where('site_id', $site->id)->sole();
        expect($ignore->source)->toBe(PluginUpdateIgnore::SOURCE_IDENTITY_MISMATCH)
            ->and($ignore->target_kind)->toBe(PluginUpdateJob::KIND_THEME)
            ->and($ignore->target_slug)->toBe('studio')
            ->and($ignore->client_visible)->toBeFalse()
            ->and(mb_strlen((string) $ignore->note))->toBeLessThanOrEqual(255);
        expect(PluginUpdateFailureStreak::query()->count())->toBe(0);

        $log = ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_THEME_UPDATE_REFUSED)->sole();
        expect($log->target)->toBe('studio')
            ->and($log->details['reason_code'])->toBe(ThemeIdentityGuard::REASON_CODE)
            ->and($log->companion_pushed_at)->toBeNull();
    });

    it('tells ops once: the next night the held theme is skipped quietly', function () {
        Queue::fake();
        $this->themeDirectory['studio'] = ['name' => 'Studio', 'slug' => 'studio', 'version' => '2.3.0'];
        $this->mock(ChatNotifier::class, fn ($mock) => $mock->shouldReceive('send')->once());

        $site = nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'studio', 'name' => 'Studio Child Theme', 'version' => '1.0.0', 'new_version' => '2.3.0', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();
        $this->travel(1)->days();
        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertNothingPushed();
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->count())->toBe(1)
            ->and(ActionLog::query()->where('action_type', ActionLog::TYPE_THEME_UPDATE_REFUSED)->count())->toBe(1);
    });

    it('holds back a custom theme whose name differs from the wordpress.org theme in the same folder', function () {
        Queue::fake();
        $this->themeDirectory['example-custom'] = ['name' => 'Example Magazine', 'slug' => 'example-custom', 'version' => '3.0.0'];
        $this->mock(ChatNotifier::class, fn ($mock) => $mock->shouldReceive('send')->once());

        $site = nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'example-custom', 'name' => 'Example Client Site', 'version' => '1.0', 'new_version' => '3.0.0', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertNotPushed(RunThemeUpdate::class);
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->sole()->status)->toBe(PluginUpdateJob::STATUS_SKIPPED);
    });

    it('queues a wordpress.org theme whose installed name matches the directory listing', function () {
        Queue::fake();
        $this->themeDirectory['twentytwentyfour'] = ['name' => 'Twenty Twenty-Four', 'slug' => 'twentytwentyfour', 'version' => '1.1'];
        $this->mock(ChatNotifier::class, fn ($mock) => $mock->shouldNotReceive('send'));

        $site = nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'twentytwentyfour', 'name' => 'Twenty Twenty-Four', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->expectsOutputToContain('held_back=0')->assertSuccessful();

        $row = PluginUpdateJob::query()->where('site_id', $site->id)->sole();
        expect($row->status)->toBe(PluginUpdateJob::STATUS_PENDING);
        Queue::assertPushed(RunThemeUpdate::class, fn ($job) => $job->jobRowId === $row->id);
        expect(PluginUpdateIgnore::query()->count())->toBe(0);
    });

    it('queues a premium theme that is not on wordpress.org even when its name differs from its folder', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'example-builder-theme', 'name' => 'Example Page Builder', 'version' => '1.7.19', 'new_version' => '1.7.20', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertPushed(RunThemeUpdate::class);
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->sole()->status)->toBe(PluginUpdateJob::STATUS_PENDING);
    });

    it('holds back a child theme offered an update by something other than wordpress.org', function () {
        Queue::fake();
        $this->mock(ChatNotifier::class, fn ($mock) => $mock->shouldReceive('send')->once());

        $site = nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'example-child', 'name' => 'Example Child', 'version' => '1.0', 'new_version' => '2.0', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertNotPushed(RunThemeUpdate::class);
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->sole()->error)->toContain('child theme');
    });

    it('stays quiet in chat for an inactive site but still holds the theme back', function () {
        Queue::fake();
        $this->mock(ChatNotifier::class, fn ($mock) => $mock->shouldNotReceive('send'));

        $site = nightlySite([
            'is_inactive' => true,
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'example-child', 'name' => 'Example Child', 'version' => '1.0', 'new_version' => '2.0', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertNotPushed(RunThemeUpdate::class);
        expect(PluginUpdateIgnore::query()->where('site_id', $site->id)->count())->toBe(1);
    });

    it('queues the theme when the directory cannot be reached and nothing marks it as a child theme', function () {
        Queue::fake();
        $this->themeDirectoryDown = true;

        $site = nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'example-theme', 'name' => 'Example Theme', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertPushed(RunThemeUpdate::class);
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->sole()->status)->toBe(PluginUpdateJob::STATUS_PENDING);
    });

    it('lists held-back themes in a dry run without writing anything', function () {
        Queue::fake();
        $this->mock(ChatNotifier::class, fn ($mock) => $mock->shouldNotReceive('send'));

        nightlySite([
            'wp_theme_updates' => true,
            'companion_snapshot' => themeSnapshot([
                ['slug' => 'example-child', 'name' => 'Example Child', 'version' => '1.0', 'new_version' => '2.0', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates', ['--dry-run' => true])
            ->expectsOutputToContain('HOLD')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        expect(PluginUpdateJob::query()->count())->toBe(0)
            ->and(PluginUpdateIgnore::query()->count())->toBe(0)
            ->and(ActionLog::query()->where('action_type', ActionLog::TYPE_THEME_UPDATE_REFUSED)->count())->toBe(0);
    });

    it('never holds back plugin updates', function () {
        Queue::fake();

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'example-child/example-child.php', 'name' => 'Example Child Plugin', 'version' => '1.0', 'new_version' => '2.0', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        Queue::assertPushed(RunPluginUpdate::class);
        expect(PluginUpdateJob::query()->where('site_id', $site->id)->sole()->status)->toBe(PluginUpdateJob::STATUS_PENDING);
    });
});
