<?php

namespace Tests\Feature\Models;

use App\Models\Server;
use App\Models\Site;
use App\Models\Tag;

/**
 * Site::scopeHostMonitored() in isolation, as a query scope: which Site IDs
 * does it return for a given DB fixture. SiteProviderGatingTest already pins
 * a fixture-fleet characterization of this scope (via domain assertions) for
 * the WpCoreChecksumVerifier transport-selection refactor; these tests don't
 * duplicate that — they exercise scopeHostMonitored() directly, one rule at
 * a time plus the composition, asserting on Site::id sets.
 */
describe('Site::scopeHostMonitored', function () {
    it('includes a SpinupWP site whose server is not ignored and has no staging tag', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create(['server_id' => $server->id]);

        $ids = Site::query()->hostMonitored()->pluck('id')->all();

        expect($ids)->toBe([$site->id]);
    });

    it('excludes a SpinupWP site whose server is ignored', function () {
        $server = Server::factory()->ignored()->create();
        Site::factory()->spinupwp()->create(['server_id' => $server->id]);

        $ids = Site::query()->hostMonitored()->pluck('id')->all();

        expect($ids)->toBe([]);
    });

    it('excludes a SpinupWP site whose server has the staging tag attached', function () {
        $server = Server::factory()->create();
        $stagingTag = Tag::factory()->create(['slug' => 'staging']);
        $server->tags()->attach($stagingTag);

        Site::factory()->spinupwp()->create(['server_id' => $server->id]);

        $ids = Site::query()->hostMonitored()->pluck('id')->all();

        expect($ids)->toBe([]);
    });

    it('always includes a Pressable site regardless of any server state', function () {
        $site = Site::factory()->pressable()->create();

        expect($site->server_id)->toBeNull();

        $ids = Site::query()->hostMonitored()->pluck('id')->all();

        expect($ids)->toBe([$site->id]);
    });

    it('returns exactly the right set of site IDs when all scenarios are mixed together', function () {
        $activeServer = Server::factory()->create();
        $ignoredServer = Server::factory()->ignored()->create();
        $stagingServer = Server::factory()->create();

        $stagingTag = Tag::factory()->create(['slug' => 'staging']);
        $stagingServer->tags()->attach($stagingTag);

        $onActive = Site::factory()->spinupwp()->create(['server_id' => $activeServer->id]);
        $onIgnored = Site::factory()->spinupwp()->create(['server_id' => $ignoredServer->id]);
        $onStaging = Site::factory()->spinupwp()->create(['server_id' => $stagingServer->id]);
        $pressable = Site::factory()->pressable()->create();

        $ids = Site::query()->hostMonitored()->pluck('id')->sort()->values()->all();

        expect($ids)->toBe(collect([$onActive->id, $pressable->id])->sort()->values()->all())
            ->and($ids)->toHaveCount(2)
            ->and($ids)->not->toContain($onIgnored->id)
            ->and($ids)->not->toContain($onStaging->id);
    });
});
