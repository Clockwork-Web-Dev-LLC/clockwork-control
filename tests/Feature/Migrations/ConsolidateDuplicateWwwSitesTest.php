<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteTrafficDaily;
use App\Support\Sites\WwwDuplicateConsolidator;
use Illuminate\Support\Facades\DB;

describe('WwwDuplicateConsolidator', function () {
    it('folds a live www duplicate into the apex site on the same server', function () {
        $server = Server::factory()->create();
        $apex = Site::factory()->create([
            'domain' => 'example.com',
            'server_id' => $server->id,
            'spinupwp_id' => '111',
            'is_inactive' => false,
            'archived_at' => null,
        ]);
        $www = Site::factory()->create([
            'domain' => 'www.example.com',
            'server_id' => $server->id,
            'spinupwp_id' => '222',
            'is_inactive' => false,
            'archived_at' => null,
        ]);
        SiteTrafficDaily::factory()->create([
            'site_id' => $www->id,
            'date' => now()->toDateString(),
            'visits' => 100,
        ]);

        (new WwwDuplicateConsolidator)->consolidate();

        $www->refresh();
        $apex->refresh();

        expect($www->consolidated_into_site_id)->toBe($apex->id)
            ->and($www->spinupwp_id)->toBeNull()
            ->and($www->is_inactive)->toBeTrue()
            ->and($www->archived_at)->not->toBeNull()
            ->and($apex->spinupwp_id)->toBe('111')
            ->and(SiteTrafficDaily::query()->where('site_id', $www->id)->count())->toBe(0)
            ->and(SiteTrafficDaily::query()->where('site_id', $apex->id)->count())->toBe(0);
    });

    it('keeps a live www row when the apex sibling is already archived', function () {
        $server = Server::factory()->create();
        $apex = Site::factory()->create([
            'domain' => 'archived-apex.com',
            'server_id' => $server->id,
            'spinupwp_id' => null,
            'is_inactive' => true,
            'archived_at' => now()->subMonth(),
        ]);
        $www = Site::factory()->create([
            'domain' => 'www.archived-apex.com',
            'server_id' => $server->id,
            'spinupwp_id' => '301',
            'is_inactive' => false,
            'archived_at' => null,
        ]);
        SiteTrafficDaily::factory()->create([
            'site_id' => $apex->id,
            'date' => now()->subDay()->toDateString(),
            'visits' => 50,
        ]);
        SiteTrafficDaily::factory()->create([
            'site_id' => $www->id,
            'date' => now()->toDateString(),
            'visits' => 75,
        ]);

        (new WwwDuplicateConsolidator)->consolidate();

        $apex->refresh();
        $www->refresh();

        expect($apex->consolidated_into_site_id)->toBe($www->id)
            ->and($apex->spinupwp_id)->toBeNull()
            ->and($www->consolidated_into_site_id)->toBeNull()
            ->and($www->spinupwp_id)->toBe('301')
            ->and(SiteTrafficDaily::query()->where('site_id', $www->id)->count())->toBe(2)
            ->and(SiteTrafficDaily::query()->where('site_id', $apex->id)->count())->toBe(0);
    });

    it('is idempotent', function () {
        $server = Server::factory()->create();
        Site::factory()->create([
            'domain' => 'once.com',
            'server_id' => $server->id,
        ]);
        Site::factory()->create([
            'domain' => 'www.once.com',
            'server_id' => $server->id,
        ]);

        (new WwwDuplicateConsolidator)->consolidate();
        (new WwwDuplicateConsolidator)->consolidate();

        expect(Site::withoutGlobalScopes()->where('domain', 'www.once.com')->value('consolidated_into_site_id'))
            ->not->toBeNull()
            ->and(Site::withoutGlobalScopes()->whereNotNull('consolidated_into_site_id')->count())->toBe(1);
    });

    it('does not pair www/root sites on different servers', function () {
        $a = Server::factory()->create();
        $b = Server::factory()->create();
        Site::factory()->create(['domain' => 'split.com', 'server_id' => $a->id]);
        Site::factory()->create(['domain' => 'www.split.com', 'server_id' => $b->id]);

        (new WwwDuplicateConsolidator)->consolidate();

        expect(DB::table('sites')->whereNotNull('consolidated_into_site_id')->count())->toBe(0);
    });
});
