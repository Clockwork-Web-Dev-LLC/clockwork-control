<?php

use App\Models\Site;
use Illuminate\Support\Facades\DB;

/*
| 2026_11_03_120000_backfill_is_wordpress_on_custom_companion_sites: standalone
| (custom-host) sites enrolled before SitesController::store set is_wordpress
| were left with the flag off even though Companion reports their WordPress
| core. The Site saving hook would heal these rows on create, so the legacy
| state is written straight to the table after creating them.
*/

function legacyNonWordpressSite(array $attributes): Site
{
    $site = Site::factory()->create($attributes);
    DB::table('sites')->where('id', $site->id)->update(['is_wordpress' => false]);

    return $site;
}

function runIsWordpressBackfill(): void
{
    $migration = require database_path('migrations/2026_11_03_120000_backfill_is_wordpress_on_custom_companion_sites.php');
    $migration->up();
}

describe('is_wordpress backfill for standalone Companion sites', function () {
    $snapshot = ['wp_core' => ['current_version' => '6.8.3'], 'plugins' => ['plugins' => []]];

    it('flags custom-host sites whose Companion snapshot reports a WordPress core', function () use ($snapshot) {
        $enrolled = legacyNonWordpressSite(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null, 'spinupwp_id' => null, 'companion_installed' => true, 'companion_snapshot' => $snapshot]);
        $archived = legacyNonWordpressSite(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null, 'spinupwp_id' => null, 'companion_installed' => true, 'companion_snapshot' => $snapshot, 'archived_at' => now()]);

        runIsWordpressBackfill();

        expect((bool) DB::table('sites')->where('id', $enrolled->id)->value('is_wordpress'))->toBeTrue()
            ->and((bool) DB::table('sites')->where('id', $archived->id)->value('is_wordpress'))->toBeTrue();
    });

    it('leaves sites it has no proof for alone', function () use ($snapshot) {
        $noCore = legacyNonWordpressSite(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null, 'spinupwp_id' => null, 'companion_installed' => true, 'companion_snapshot' => ['plugins' => ['plugins' => []]]]);
        $noSnapshot = legacyNonWordpressSite(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null, 'spinupwp_id' => null, 'companion_installed' => true, 'companion_snapshot' => null]);
        $noCompanion = legacyNonWordpressSite(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null, 'spinupwp_id' => null, 'companion_installed' => false, 'companion_snapshot' => $snapshot]);
        $otherHost = legacyNonWordpressSite(['companion_installed' => true, 'companion_snapshot' => $snapshot]);

        runIsWordpressBackfill();

        foreach ([$noCore, $noSnapshot, $noCompanion, $otherHost] as $site) {
            expect((bool) DB::table('sites')->where('id', $site->id)->value('is_wordpress'))->toBeFalse();
        }
    });
});
