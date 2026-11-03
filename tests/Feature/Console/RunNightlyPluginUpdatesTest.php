<?php

namespace Tests\Feature\Console;

use App\Jobs\RunPluginUpdate;
use App\Jobs\RunThemeUpdate;
use App\Models\PluginUpdateFailureStreak;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\PluginVulnerability;
use App\Models\Site;
use Illuminate\Support\Carbon;
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
function pauseAfterFailures(Site $site, string $slug, ?string $failedVersion, string $kind = PluginUpdateJob::KIND_PLUGIN): PluginUpdateIgnore
{
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
            'last_failed_at' => now()->subDay(),
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

    it('retries an auto-paused vulnerable plugin when the offered version contains the fix, even if that version failed before', function () {
        Queue::fake();

        vulnerableAdvisory('vuln-plugin', '1.0.0', '1.1.0');

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'vuln-plugin/vuln-plugin.php', 'name' => 'Vuln Plugin', 'version' => '1.0.0', 'new_version' => '1.1.0', 'update_available' => true],
            ]),
        ]);
        pauseAfterFailures($site, 'vuln-plugin/vuln-plugin.php', '1.1.0');

        $this->artisan('clockwork:run-nightly-plugin-updates')
            ->expectsOutputToContain('Retrying 1 auto-paused update (0 new release, 1 security fix)')
            ->assertSuccessful();

        $row = PluginUpdateJob::query()->where('site_id', $site->id)->sole();
        expect($row->target_version)->toBe('1.1.0');
        Queue::assertPushed(RunPluginUpdate::class, 1);
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
