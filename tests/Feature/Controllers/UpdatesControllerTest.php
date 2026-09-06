<?php

use App\Jobs\RunPluginUpdate;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/**
 * bulkUpdate() dispatches RunPluginUpdate/RunThemeUpdate/RunCoreUpdate/
 * RunTranslationsUpdate onto the 'plugin-updates' queue, which — because
 * phpunit.xml sets QUEUE_CONNECTION=sync — would otherwise execute for
 * real in-process, hitting ClockworkCompanionClient (real HTTP to a site's
 * Companion endpoint). Queue::fake() is required in every bulkUpdate test.
 */
function pluginSnapshot(string $slug = 'akismet', string $name = 'Akismet'): array
{
    return [
        'plugins' => [
            'plugins' => [
                [
                    'slug' => $slug,
                    'name' => $name,
                    'version' => '4.0',
                    'new_version' => '5.0',
                    'update_available' => true,
                ],
            ],
            'counts' => ['updates_available' => 1],
        ],
        'themes' => ['items' => [], 'counts' => ['updates_available' => 0]],
        'wp_core' => ['update_available' => false],
        'translations' => ['count' => 0],
    ];
}

describe('UpdatesController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('redirects guests to login on every route', function () {
        $this->get(route('updates.index'))->assertRedirect(route('login'));
        $this->get(route('updates.carePlan'))->assertRedirect(route('login'));
        $this->post(route('updates.bulkUpdate'), [])->assertRedirect(route('login'));
        $this->post(route('updates.bulkIgnore'), [])->assertRedirect(route('login'));
        $this->post(route('updates.bulkUnignore'), [])->assertRedirect(route('login'));
        $this->get(route('updates.batches.status', ['batchId' => (string) Str::uuid()]))
            ->assertRedirect(route('login'));
    });

    it('renders the fleet updates page with a pending plugin grouped from the Companion snapshot', function () {
        // index() defaults the care_plan filter to 'on' (UpdateGrouping::applyFilters),
        // so the site must be care-plan-enabled to appear without extra query params.
        $site = Site::factory()->carePlan()->withCompanionInstalled()->create([
            'domain' => 'plugin-pending.example.com',
            'companion_snapshot' => pluginSnapshot(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('updates.index'));

        $response->assertOk()
            ->assertSee('Akismet')
            ->assertSee($site->domain);
    });

    it('renders the care-plan curation page listing only care-plan sites', function () {
        $covered = Site::factory()->carePlan()->create(['domain' => 'covered.example.com']);
        Site::factory()->create(['domain' => 'not-covered.example.com', 'care_plan_enabled' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('updates.carePlan'));

        $response->assertOk()
            ->assertSee('covered.example.com')
            ->assertDontSee('not-covered.example.com');
    });

    it('bulk-queues a plugin update, creating the job row and dispatching the worker job without running it', function () {
        Queue::fake();

        $site = Site::factory()->withCompanionInstalled()->create([
            'companion_snapshot' => pluginSnapshot('akismet', 'Akismet'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('updates.bulkUpdate'), [
                'targets' => ["plugin:{$site->id}:akismet"],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash', function ($flash) {
            return str_contains($flash, 'Queued 1 update');
        });

        $row = PluginUpdateJob::query()->where('site_id', $site->id)->where('target_slug', 'akismet')->first();
        expect($row)->not->toBeNull();
        expect($row->status)->toBe(PluginUpdateJob::STATUS_PENDING);
        expect($row->target_kind)->toBe(PluginUpdateJob::KIND_PLUGIN);
        expect($row->before_version)->toBe('4.0');
        expect($row->target_version)->toBe('5.0');

        Queue::assertPushed(RunPluginUpdate::class, fn ($job) => $job->jobRowId === $row->id);
    });

    it('rejects a bulkUpdate request missing the required targets field', function () {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('updates.bulkUpdate'), []);

        $response->assertSessionHasErrors('targets');
        expect(PluginUpdateJob::query()->count())->toBe(0);
    });

    it('silently drops a malformed target instead of queuing anything for it', function () {
        Queue::fake();

        $site = Site::factory()->withCompanionInstalled()->create([
            'companion_snapshot' => pluginSnapshot(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('updates.bulkUpdate'), [
                // Unknown kind — parseTarget() returns null and drops it.
                'targets' => ["bogus-kind:{$site->id}:akismet"],
            ]);

        $response->assertSessionHas('flash', function ($flash) {
            return str_contains($flash, 'Queued 0 update');
        });
        expect(PluginUpdateJob::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('bulk-ignores a target, creating a PluginUpdateIgnore row', function () {
        $site = Site::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('updates.bulkIgnore'), [
                'targets' => ["plugin:{$site->id}:akismet"],
                'note' => 'client asked us to hold off',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash', 'Ignored 1 update.');

        $ignore = PluginUpdateIgnore::query()->where('site_id', $site->id)->first();
        expect($ignore)->not->toBeNull();
        expect($ignore->target_kind)->toBe('plugin');
        expect($ignore->target_slug)->toBe('akismet');
        expect($ignore->note)->toBe('client asked us to hold off');
    });

    it('bulk-unignores a target, removing the matching PluginUpdateIgnore row', function () {
        $site = Site::factory()->create();
        $ignore = PluginUpdateIgnore::factory()->create([
            'site_id' => $site->id,
            'target_kind' => 'plugin',
            'target_slug' => 'akismet',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('updates.bulkUnignore'), [
                'targets' => ["plugin:{$site->id}:akismet"],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash', 'Removed 1 ignore entry.');
        expect(PluginUpdateIgnore::query()->whereKey($ignore->id)->exists())->toBeFalse();
    });

    it('returns live batch status as JSON for a real UUID batch id', function () {
        $site = Site::factory()->create();
        $batchId = (string) Str::uuid();
        PluginUpdateJob::factory()->count(2)->create([
            'site_id' => $site->id,
            'batch_id' => $batchId,
            'status' => PluginUpdateJob::STATUS_PENDING,
        ]);
        PluginUpdateJob::factory()->complete()->create([
            'site_id' => $site->id,
            'batch_id' => $batchId,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('updates.batches.status', ['batchId' => $batchId]));

        $response->assertOk()
            ->assertJson([
                'batch_id' => $batchId,
                'total' => 3,
                'pending' => 2,
                'complete' => 1,
                'complete_flag' => false,
            ]);
    });

    it('rejects a non-UUID batchId at the routing layer', function () {
        $url = route('updates.batches.status', ['batchId' => 'not-a-real-uuid']);

        $response = $this->actingAs(User::factory()->create())->get($url);

        $response->assertNotFound();
    });
});
