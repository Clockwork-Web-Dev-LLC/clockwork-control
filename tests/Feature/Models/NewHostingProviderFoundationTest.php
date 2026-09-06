<?php

use App\Models\Server;
use App\Models\Site;

/**
 * Foundation for the WP Engine/Kinsta/Cloudways modules: the new
 * hosting_provider values, per-provider site-identifier columns, and
 * factory states. The scopeHostMonitored() test is the important one —
 * without that fix, WP Engine/Kinsta sites (server_id always null) would
 * be silently excluded from every host-agnostic monitoring loop, since
 * they'd never satisfy whereHas('server', ...) and weren't in the
 * "no server, always eligible" branch before this fix.
 */
describe('new hosting provider factory states', function () {
    it('builds a WP Engine site with no server and a real install name', function () {
        $site = Site::factory()->wpEngine()->create();

        expect($site->hosting_provider)->toBe(Site::HOSTING_PROVIDER_WPENGINE)
            ->and($site->server_id)->toBeNull()
            ->and($site->wpengine_install_name)->not->toBeNull()
            ->and($site->isWpEngine())->toBeTrue();
    });

    it('builds a Kinsta site with no server and a real environment id', function () {
        $site = Site::factory()->kinsta()->create();

        expect($site->hosting_provider)->toBe(Site::HOSTING_PROVIDER_KINSTA)
            ->and($site->server_id)->toBeNull()
            ->and($site->kinsta_environment_id)->not->toBeNull()
            ->and($site->isKinsta())->toBeTrue();
    });

    it('builds a Cloudways site with a real server row and app id', function () {
        $site = Site::factory()->cloudways()->create();

        expect($site->hosting_provider)->toBe(Site::HOSTING_PROVIDER_CLOUDWAYS)
            ->and($site->server_id)->not->toBeNull()
            ->and($site->server->provider)->toBe(Server::PROVIDER_CLOUDWAYS)
            ->and($site->cloudways_app_id)->not->toBeNull()
            ->and($site->isCloudways())->toBeTrue();
    });
});

it('includes WP Engine and Kinsta sites in scopeHostMonitored despite having no server', function () {
    $wpEngineSite = Site::factory()->wpEngine()->create();
    $kinstaSite = Site::factory()->kinsta()->create();

    $ids = Site::query()->hostMonitored()->pluck('id');

    expect($ids)->toContain($wpEngineSite->id)->toContain($kinstaSite->id);
});
