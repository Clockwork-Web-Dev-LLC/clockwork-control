<?php

namespace Tests\Feature\Console;

use App\Jobs\RunPluginUpdate;
use App\Jobs\RunThemeUpdate;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\PluginVulnerability;
use App\Models\Site;
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
    it('assigns the same nightly batch_id to every tuple queued in one run, with the vulnerable plugin present', function () {
        Queue::fake();

        PluginVulnerability::factory()->create([
            'slug' => 'vuln-plugin',
            'from_version' => '0',
            'to_version' => '2.0.0',
        ]);

        $site = nightlySite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => pluginSnapshot([
                ['slug' => 'safe-plugin', 'name' => 'Safe Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
                ['slug' => 'vuln-plugin', 'name' => 'Vuln Plugin', 'version' => '1.0', 'new_version' => '1.1', 'update_available' => true],
            ]),
        ]);

        $this->artisan('clockwork:run-nightly-plugin-updates')->assertSuccessful();

        $rows = PluginUpdateJob::query()->where('site_id', $site->id)->get();
        expect($rows)->toHaveCount(2);
        $batchIds = $rows->pluck('batch_id')->unique();
        expect($batchIds)->toHaveCount(1);
        Queue::assertPushed(RunPluginUpdate::class, 2);
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
