<?php

use App\Models\Site;
use App\Support\Settings;

/*
|--------------------------------------------------------------------------
| CompanionCanarySet — Phase 6 console coverage
|--------------------------------------------------------------------------
|
| Mechanism: the canary cohort is a plain JSON array of site IDs stored
| under the `companion.canary_site_ids` app_settings key (see Settings).
| No collaborator here shells out or hits the network, so nothing needs
| mocking — this is pure DB + settings-table behavior.
*/

describe('clockwork:companion-canary-set', function () {
    it('resolves domains to site IDs and saves them as the canary set', function () {
        $a = Site::factory()->spinupwp()->create(['domain' => 'canary-a.test']);
        $b = Site::factory()->spinupwp()->create(['domain' => 'canary-b.test']);

        $this->artisan('clockwork:companion-canary-set', ['sites' => ['canary-a.test', 'canary-b.test']])
            ->assertSuccessful();

        $ids = app(Settings::class)->get('companion.canary_site_ids');
        expect($ids)->toEqualCanonicalizing([$a->id, $b->id]);
    });

    it('also accepts raw numeric site IDs as tokens', function () {
        $a = Site::factory()->spinupwp()->create();

        $this->artisan('clockwork:companion-canary-set', ['sites' => [(string) $a->id]])
            ->assertSuccessful();

        expect(app(Settings::class)->get('companion.canary_site_ids'))->toBe([$a->id]);
    });

    it('de-duplicates repeated tokens that resolve to the same site', function () {
        $a = Site::factory()->spinupwp()->create(['domain' => 'canary-dup.test']);

        $this->artisan('clockwork:companion-canary-set', ['sites' => ['canary-dup.test', 'canary-dup.test']])
            ->assertSuccessful();

        expect(app(Settings::class)->get('companion.canary_site_ids'))->toBe([$a->id]);
    });

    it('fails without writing anything when any token cannot be resolved to a site', function () {
        $a = Site::factory()->spinupwp()->create(['domain' => 'canary-real.test']);

        $this->artisan('clockwork:companion-canary-set', ['sites' => ['canary-real.test', 'no-such-domain.test']])
            ->assertFailed();

        expect(app(Settings::class)->get('companion.canary_site_ids', []))->toBe([]);
    });

    it('fails when called with no sites and neither --list nor --clear', function () {
        $this->artisan('clockwork:companion-canary-set')->assertFailed();
    });

    it('--clear empties an existing canary set', function () {
        $a = Site::factory()->spinupwp()->create();
        app(Settings::class)->put('companion.canary_site_ids', [$a->id]);

        $this->artisan('clockwork:companion-canary-set', ['--clear' => true])->assertSuccessful();

        expect(app(Settings::class)->get('companion.canary_site_ids'))->toBe([]);
    });

    it('--list prints the current canary set without modifying it', function () {
        $a = Site::factory()->spinupwp()->create([
            'domain' => 'canary-list.test',
            'companion_version' => '1.30.4',
            'companion_capabilities' => ['malware-scan'],
        ]);
        app(Settings::class)->put('companion.canary_site_ids', [$a->id]);

        $this->artisan('clockwork:companion-canary-set', ['--list' => true])
            ->expectsOutputToContain('canary-list.test')
            ->assertSuccessful();

        expect(app(Settings::class)->get('companion.canary_site_ids'))->toBe([$a->id]);
    });

    it('--list prints "(empty)" when the canary set has never been populated', function () {
        $this->artisan('clockwork:companion-canary-set', ['--list' => true])
            ->expectsOutputToContain('(empty)')
            ->assertSuccessful();
    });
});
