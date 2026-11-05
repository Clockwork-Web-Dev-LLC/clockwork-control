<?php

use App\Models\ActionLog;
use App\Models\PluginUpdateFailureStreak;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Updates\ObservedUpdateRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
| ObservedUpdateRecorder: when a new Companion snapshot replaces the previous
| one, version increases Control didn't make are written as
| *_update_observed action logs (informational, local-only). The hook is the
| Site model's updated event, so every place that writes a snapshot is
| covered; these tests save the snapshot through the model the way those
| writers do. Slugs, names and versions are invented.
*/

function observedSnapshot(array $plugins = [], array $themes = [], ?string $core = null): array
{
    $snapshot = [
        'plugins' => ['plugins' => array_map(fn ($p) => ['slug' => $p[0], 'name' => $p[1], 'version' => $p[2], 'active' => true], $plugins)],
        'themes' => ['items' => array_map(fn ($t) => ['slug' => $t[0], 'name' => $t[1], 'version' => $t[2], 'active' => false], $themes)],
    ];
    if ($core !== null) {
        $snapshot['wp_core'] = ['current_version' => $core, 'update_available' => false];
    }

    return $snapshot;
}

function observedSite(array $snapshot, ?Carbon $at = null): Site
{
    return Site::factory()->withCompanionInstalled()->create([
        'companion_snapshot' => $snapshot,
        'companion_snapshot_at' => $at ?? now()->subDay(),
    ]);
}

function storeSnapshot(Site $site, array $snapshot, ?Carbon $at = null): void
{
    $site->forceFill([
        'companion_snapshot' => $snapshot,
        'companion_snapshot_at' => $at ?? now(),
    ])->save();
}

function observedRows(Site $site): Collection
{
    return ActionLog::query()
        ->where('site_id', $site->id)
        ->whereIn('action_type', ActionLog::OBSERVED_UPDATE_TYPES)
        ->orderBy('id')
        ->get();
}

function controlJob(Site $site, string $kind, ?string $slug, array $overrides = []): PluginUpdateJob
{
    return PluginUpdateJob::factory()->for($site)->create(array_merge([
        'target_kind' => $kind,
        'target_slug' => $slug,
        'status' => PluginUpdateJob::STATUS_COMPLETE,
        'before_version' => '1.0.0',
        'target_version' => '1.1.0',
        'after_version' => '1.1.0',
        'batch_id' => 'nightly-'.now()->format('Y-m-d'),
    ], $overrides));
}

beforeEach(function () {
    Http::fake();
});

describe('ObservedUpdateRecorder', function () {
    it('logs one row per plugin, theme and core version Control did not change', function () {
        $site = observedSite(observedSnapshot(
            [['example-forms/example-forms.php', 'Example Forms', '2.9.0'], ['example-seo/example-seo.php', 'Example SEO', '5.1']],
            [['example-theme', 'Example Theme', '3.2']],
            '6.8.2',
        ));
        $at = Carbon::parse('2026-10-20 06:00:00');

        storeSnapshot($site, observedSnapshot(
            [['example-forms/example-forms.php', 'Example Forms', '2.9.1'], ['example-seo/example-seo.php', 'Example SEO', '5.1']],
            [['example-theme', 'Example Theme', '3.3']],
            '6.8.3',
        ), $at);

        $rows = observedRows($site);
        expect($rows->pluck('action_type')->all())->toBe([
            ActionLog::TYPE_PLUGIN_UPDATE_OBSERVED,
            ActionLog::TYPE_THEME_UPDATE_OBSERVED,
            ActionLog::TYPE_CORE_UPDATE_OBSERVED,
        ]);

        $plugin = $rows[0];
        expect($plugin->target)->toBe('example-forms/example-forms.php')
            ->and($plugin->actor)->toBe(ObservedUpdateRecorder::ACTOR)
            ->and($plugin->ok)->toBeTrue()
            ->and($plugin->ran_at->equalTo($at))->toBeTrue()
            ->and($plugin->summary)->toBe('Plugin updated outside Control: Example Forms 2.9.0 → 2.9.1')
            ->and($plugin->details)->toMatchArray([
                'slug' => 'example-forms/example-forms.php',
                'name' => 'Example Forms',
                'from_version' => '2.9.0',
                'to_version' => '2.9.1',
                'observed_at' => $at->toIso8601String(),
            ])
            ->and($plugin->details['previous_snapshot_at'])->not->toBeNull();

        expect($rows[1]->details)->toMatchArray(['slug' => 'example-theme', 'name' => 'Example Theme', 'from_version' => '3.2', 'to_version' => '3.3'])
            ->and($rows[2]->target)->toBe(ObservedUpdateRecorder::CORE_SLUG)
            ->and($rows[2]->details)->toMatchArray(['slug' => 'wordpress', 'name' => 'WordPress', 'from_version' => '6.8.2', 'to_version' => '6.8.3']);
    });

    it('is informational only: no Companion push, chat alert, failure streak or pause', function () {
        $this->mock(ChatNotifier::class, fn ($mock) => $mock->shouldNotReceive(
            'send', 'pluginUpdateFailed', 'pluginUpdateAutoIgnored',
        ));
        $site = observedSite(observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '2.9.0']]));
        $site->forceFill(['auto_updates_paused' => false])->save();

        storeSnapshot($site, observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '2.9.1']]));

        expect(observedRows($site))->toHaveCount(1)
            ->and(observedRows($site)->first()->companion_pushed_at)->toBeNull();
        Http::assertNothingSent();
        expect(PluginUpdateFailureStreak::query()->count())->toBe(0)
            ->and(PluginUpdateIgnore::query()->count())->toBe(0)
            ->and(PluginUpdateJob::query()->count())->toBe(0)
            ->and($site->refresh()->auto_updates_paused)->toBeFalse();
    });

    it('skips a change a recent Control job made, and still logs other changes', function () {
        $site = observedSite(observedSnapshot([
            ['example-forms/example-forms.php', 'Example Forms', '1.0.0'],
            ['example-seo/example-seo.php', 'Example SEO', '5.1'],
        ], [['example-theme', 'Example Theme', '3.2']], '6.8.2'));
        controlJob($site, PluginUpdateJob::KIND_PLUGIN, 'example-forms/example-forms.php');
        controlJob($site, PluginUpdateJob::KIND_THEME, 'example-theme', ['after_version' => '3.3']);
        controlJob($site, PluginUpdateJob::KIND_CORE, null, ['after_version' => '6.8.3']);

        storeSnapshot($site, observedSnapshot([
            ['example-forms/example-forms.php', 'Example Forms', '1.1.0'],
            ['example-seo/example-seo.php', 'Example SEO', '5.2'],
        ], [['example-theme', 'Example Theme', '3.3']], '6.8.3'));

        expect(observedRows($site)->pluck('target')->all())->toBe(['example-seo/example-seo.php']);
    });

    it('counts a failed Control job only when it left the site on the new version', function () {
        $site = observedSite(observedSnapshot([
            ['example-forms/example-forms.php', 'Example Forms', '1.0.0'],
            ['example-seo/example-seo.php', 'Example SEO', '5.1'],
        ]));
        // Files swapped, then reactivation failed: Control made this change.
        controlJob($site, PluginUpdateJob::KIND_PLUGIN, 'example-forms/example-forms.php', ['status' => PluginUpdateJob::STATUS_FAILED, 'after_version' => '1.1.0']);
        // Nothing changed when Control tried: the new version came from elsewhere.
        controlJob($site, PluginUpdateJob::KIND_PLUGIN, 'example-seo/example-seo.php', ['status' => PluginUpdateJob::STATUS_FAILED, 'before_version' => '5.1', 'after_version' => '5.1']);

        storeSnapshot($site, observedSnapshot([
            ['example-forms/example-forms.php', 'Example Forms', '1.1.0'],
            ['example-seo/example-seo.php', 'Example SEO', '5.2'],
        ]));

        expect(observedRows($site)->pluck('target')->all())->toBe(['example-seo/example-seo.php']);
    });

    it('only credits Control jobs from the last 48 hours, or since the previous snapshot when that is older', function () {
        $recent = observedSite(observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '1.0.0']]), now()->subDay());
        controlJob($recent, PluginUpdateJob::KIND_PLUGIN, 'example-forms/example-forms.php', ['created_at' => now()->subHours(ObservedUpdateRecorder::CONTROL_WINDOW_HOURS + 2)]);
        storeSnapshot($recent, observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '1.1.0']]));

        $stale = observedSite(observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '1.0.0']]), now()->subDays(5));
        controlJob($stale, PluginUpdateJob::KIND_PLUGIN, 'example-forms/example-forms.php', ['created_at' => now()->subDays(3)]);
        storeSnapshot($stale, observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '1.1.0']]));

        expect(observedRows($recent))->toHaveCount(1)
            ->and(observedRows($stale))->toHaveCount(0);
    });

    it('ignores downgrades, items added or removed, and unchanged versions', function () {
        $site = observedSite(observedSnapshot([
            ['rolled-back/rolled-back.php', 'Rolled Back', '2.0.0'],
            ['removed/removed.php', 'Removed', '1.0'],
            ['same/same.php', 'Same', '3.0'],
        ], [], '6.8.3'));

        storeSnapshot($site, observedSnapshot([
            ['rolled-back/rolled-back.php', 'Rolled Back', '1.9.0'],
            ['added/added.php', 'Added', '1.0'],
            ['same/same.php', 'Same', '3.0'],
        ], [], '6.8.3'));

        expect(observedRows($site))->toHaveCount(0);
    });

    it('logs a change once when the snapshot flips back and forth', function () {
        $old = observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '1.0.0']]);
        $new = observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '1.1.0']]);
        $site = observedSite($old);

        storeSnapshot($site, $new, now()->subHours(3));
        storeSnapshot($site, $old, now()->subHours(2));
        storeSnapshot($site, $new, now()->subHour());

        expect(observedRows($site))->toHaveCount(1);
    });

    it('does nothing for a first snapshot or a save that leaves the snapshot alone', function () {
        $site = Site::factory()->withCompanionInstalled()->create(['companion_snapshot' => null]);
        storeSnapshot($site, observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '1.0.0']]));

        $site->forceFill(['auto_updates_paused_reason' => 'unrelated edit'])->save();

        expect(observedRows($site))->toHaveCount(0);
    });

    it('saves a malformed snapshot without logging anything', function () {
        $site = observedSite(observedSnapshot([['example-forms/example-forms.php', 'Example Forms', '1.0.0']], [], '6.8.2'));

        storeSnapshot($site, ['plugins' => 'not-a-list', 'themes' => ['items' => [['name' => 'No slug']]], 'wp_core' => ['current_version' => ['bad']]]);

        expect($site->refresh()->companion_snapshot['plugins'])->toBe('not-a-list')
            ->and(observedRows($site))->toHaveCount(0);
    });
});
