<?php

namespace Tests\Feature\Gatekeeper;

use App\Models\Server;
use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Companion\CompanionProtectedPlugins;
use App\Services\Sites\LlarInstaller;
use App\Services\Stats\WeirdStatsAggregator;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| "Protected" now includes Gatekeeper
|--------------------------------------------------------------------------
|
| As the fleet moves off Limit Login Attempts Reloaded, every surface that
| used `llar_enabled` as the login-protection test has to accept Gatekeeper
| too — otherwise the migration itself makes the fleet look unprotected
| and nudges operators to re-install LLAR. These tests pin that contract
| on the model helper and each consumer.
*/

function gatekeeperSite(array $attrs = []): Site
{
    return Site::factory()->create(array_merge([
        'is_wordpress' => true,
        'companion_installed' => true,
        'companion_secret' => 'test-secret',
        'companion_capabilities' => ['gatekeeper', 'plugins'],
        'gatekeeper_settings' => ['enabled' => true],
        'llar_enabled' => false,
        'wordfence_enabled' => false,
    ], $attrs));
}

describe('Site::gatekeeperEnabled()', function () {
    it('is true with the capability and a per-site enabled override', function () {
        expect(gatekeeperSite()->gatekeeperEnabled())->toBeTrue();
    });

    it('is false without the gatekeeper capability even if the override says enabled', function () {
        $site = gatekeeperSite(['companion_capabilities' => ['plugins']]);

        expect($site->gatekeeperEnabled())->toBeFalse();
    });

    it('is false when Companion is not installed', function () {
        $site = gatekeeperSite(['companion_installed' => false]);

        expect($site->gatekeeperEnabled())->toBeFalse();
    });

    it('inherits the fleet default when there is no per-site override', function () {
        $site = gatekeeperSite(['gatekeeper_settings' => null]);

        expect($site->gatekeeperEnabled())->toBeFalse();

        app(Settings::class)->put('gatekeeper.enabled', true);

        expect($site->fresh()->gatekeeperEnabled())->toBeTrue();
    });

    it('lets an explicit per-site false override beat a fleet-on default', function () {
        app(Settings::class)->put('gatekeeper.enabled', true);
        $site = gatekeeperSite(['gatekeeper_settings' => ['enabled' => false]]);

        expect($site->gatekeeperEnabled())->toBeFalse();
    });
});

describe('Site::gatekeeperProtected() scope', function () {
    it('matches the same sites as gatekeeperEnabled() across override and fleet states', function () {
        $overrideOn = gatekeeperSite(['domain' => 'on.example']);
        $overrideOff = gatekeeperSite(['domain' => 'off.example', 'gatekeeper_settings' => ['enabled' => false]]);
        $inherit = gatekeeperSite(['domain' => 'inherit.example', 'gatekeeper_settings' => null]);
        $inheritOtherKeys = gatekeeperSite(['domain' => 'inherit2.example', 'gatekeeper_settings' => ['threshold' => 6]]);
        $noCap = gatekeeperSite(['domain' => 'nocap.example', 'companion_capabilities' => ['plugins']]);

        // Fleet off: only the explicit override counts.
        expect(Site::query()->gatekeeperProtected()->pluck('domain')->all())->toBe(['on.example']);

        app(Settings::class)->put('gatekeeper.enabled', true);

        expect(Site::query()->gatekeeperProtected()->orderBy('domain')->pluck('domain')->all())
            ->toBe(['inherit.example', 'inherit2.example', 'on.example']);

        // Cross-check the PHP twin agrees on every row.
        foreach ([$overrideOn, $overrideOff, $inherit, $inheritOtherKeys, $noCap] as $s) {
            $inScope = Site::query()->gatekeeperProtected()->whereKey($s->id)->exists();
            expect($inScope)->toBe($s->fresh()->gatekeeperEnabled(), "scope/helper disagree on {$s->domain}");
        }
    });

    it('loginUnprotected excludes Gatekeeper-protected sites', function () {
        gatekeeperSite(['domain' => 'gk.example']);
        Site::factory()->create(['domain' => 'bare.example', 'is_wordpress' => true, 'llar_enabled' => false, 'wordfence_enabled' => false]);
        Site::factory()->create(['domain' => 'llar.example', 'is_wordpress' => true, 'llar_enabled' => true, 'wordfence_enabled' => false]);

        expect(Site::query()->loginUnprotected()->pluck('domain')->all())->toBe(['bare.example']);
    });
});

describe('CompanionProtectedPlugins guard', function () {
    it('still refuses to delete LLAR on a site without Gatekeeper', function () {
        $site = gatekeeperSite(['gatekeeper_settings' => null]);

        expect(fn () => CompanionProtectedPlugins::guardDestructive(
            'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php',
            'delete',
            $site,
        ))->toThrow(RuntimeException::class);
    });

    it('exempts LLAR once Gatekeeper is enabled on the site', function () {
        $site = gatekeeperSite();

        CompanionProtectedPlugins::guardDestructive(
            'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php',
            'deactivate',
            $site,
        );

        expect(true)->toBeTrue();
    });

    it('does not exempt other protected plugins on a Gatekeeper site', function () {
        $site = gatekeeperSite();

        expect(fn () => CompanionProtectedPlugins::guardDestructive('wordfence/wordfence.php', 'delete', $site))
            ->toThrow(RuntimeException::class);
    });

    it('keeps refusing LLAR when no site context is given', function () {
        expect(fn () => CompanionProtectedPlugins::guardDestructive(
            'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php',
            'delete',
        ))->toThrow(RuntimeException::class);
    });

    it('lets the Companion client delete LLAR on a Gatekeeper site', function () {
        $site = gatekeeperSite(['domain' => 'gk.example']);

        Http::fake([
            'https://gk.example/wp-json/clockwork/v1/plugins/delete' => Http::response(['ok' => true]),
        ]);

        $response = (new ClockworkCompanionClient($site))
            ->deletePlugin('limit-login-attempts-reloaded/limit-login-attempts-reloaded.php');

        expect($response['ok'])->toBeTrue();
        Http::assertSentCount(1);
    });
});

describe('LlarInstaller refuses Gatekeeper sites', function () {
    it('returns RESULT_SKIPPED_GATEKEEPER without touching SSH', function () {
        $site = gatekeeperSite();

        $result = app(LlarInstaller::class)->process($site);

        expect($result['result'])->toBe(LlarInstaller::RESULT_SKIPPED_GATEKEEPER)
            ->and($result['message'])->toContain('Gatekeeper');
    });

    it('--all-missing skips Gatekeeper-protected sites', function () {
        gatekeeperSite(['domain' => 'gk.example']);
        $bare = Site::factory()->create(['domain' => 'bare.example', 'is_wordpress' => true, 'llar_enabled' => false]);

        $this->mock(LlarInstaller::class, function ($mock) use ($bare) {
            $mock->shouldReceive('process')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($bare))
                ->andReturn(['result' => LlarInstaller::RESULT_INSTALLED, 'message' => 'ok']);
        });

        $this->artisan('clockwork:install-llar', ['--all-missing' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('installed=1');
    });
});

describe('WeirdStatsAggregator counts Gatekeeper as protection', function () {
    it('excludes Gatekeeper sites from unprotected_count and neither, and reports them in the matrix', function () {
        $server = Server::factory()->create(['is_ignored' => false]);

        gatekeeperSite(['domain' => 'gk.example', 'server_id' => $server->id, 'cloudflare_state' => 'proxied']);
        Site::factory()->create(['domain' => 'bare.example', 'is_wordpress' => true, 'server_id' => $server->id, 'cloudflare_state' => 'proxied', 'llar_enabled' => false, 'wordfence_enabled' => false]);
        Site::factory()->create(['domain' => 'both.example', 'is_wordpress' => true, 'server_id' => $server->id, 'cloudflare_state' => 'proxied', 'llar_enabled' => true, 'wordfence_enabled' => true]);
        gatekeeperSite(['domain' => 'gkwf.example', 'server_id' => $server->id, 'cloudflare_state' => 'proxied', 'wordfence_enabled' => true]);

        $agg = app(WeirdStatsAggregator::class);

        expect($agg->summaryTiles()['unprotected_count'])->toBe(1);
        expect($agg->unprotectedSitesByTraffic()->pluck('domain')->all())->toBe(['bare.example']);

        $matrix = collect($agg->pluginCoverageMatrix())->keyBy('cf_state');
        $row = $matrix['proxied'];

        expect($row['total'])->toBe(4)
            ->and($row['gatekeeper'])->toBe(2)
            ->and($row['llar'])->toBe(1)
            ->and($row['wf'])->toBe(2)
            ->and($row['both'])->toBe(2)   // llar+wf, gatekeeper+wf
            ->and($row['neither'])->toBe(1);
    });
});
