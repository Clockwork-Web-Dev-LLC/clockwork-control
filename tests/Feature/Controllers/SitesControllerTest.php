<?php

use App\Mail\SiteVulnerabilityReportMail;
use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteIngestExclusion;
use App\Models\SiteUptimeEvent;
use App\Models\User;
use App\Services\Companion\CompanionInstaller;
use App\Services\DigitalOcean\SpacesClient;
use App\Services\Fail2ban\Fail2banClient;
use App\Services\HostingProvider\HostingProviderRegistry;
use App\Services\Security\PluginVulnerabilityMatcher;
use App\Services\Sites\LlarInstaller;
use App\Services\Sites\WpConfigExtractor;
use App\Services\Sites\WpPluginDetector;
use App\Services\Ssl\LiveCertProbe;
use App\Services\Ssl\SiteCertRefresher;
use App\Services\Uptime\UptimeProber;
use App\Services\Uptime\UptimeProbeResult;
use App\Services\Uptime\UptimeStateUpdater;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Core\Contracts\HostingProvider;
use Modules\Pressable\PressableClient;
use Modules\SpinupWp\SpinupWpClient;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| SitesController — HTTP-level coverage (Phase 5)
|--------------------------------------------------------------------------
|
| Every collaborator that shells out, SSHes, or calls an external API is
| mocked. ClockworkCompanionClient is `new`'d directly inside the
| controller rather than container-injected, so its HTTP calls are faked
| via Http::fake() against the real signed-request URLs instead of being
| container-mocked (see tests/Feature/Companion/CompanionHmacAuthTest.php
| for the same pattern).
*/

/**
 * @return array{0: Server, 1: Site} a SpinupWP-shaped site (CAP_SSH true)
 *                                   with a real linked server.
 */
function spinupSite(array $overrides = []): array
{
    $server = Server::factory()->create();
    // spinupwp()'s own state already sets server_id via a fresh
    // Server::factory() — passing server_id explicitly in $overrides (rather
    // than chaining ->for($server)) is what actually wins, since Factory::for()
    // does not take precedence over a state-set attribute here.
    $site = Site::factory()->spinupwp()->create(array_merge(['server_id' => $server->id], $overrides));

    return [$server, $site];
}

function companionSite(array $overrides = []): Site
{
    return Site::factory()->spinupwp()->withCompanionInstalled()->create($overrides);
}

beforeEach(function () {
    $this->mockIssueCounterZero();
});

it('requires authentication', function () {
    $site = Site::factory()->spinupwp()->create();

    $this->get(route('sites.index'))->assertRedirect(route('login'));
    $this->get(route('sites.show', $site))->assertRedirect(route('login'));
    $this->post(route('sites.care-plan', $site))->assertRedirect(route('login'));
});

describe('index', function () {
    it('lists sites and filters by provider + domain query', function () {
        Site::factory()->spinupwp()->create(['domain' => 'alpha-site.test']);
        Site::factory()->pressable()->create(['domain' => 'beta-pressable.test']);

        $response = $this->actingAs(User::factory()->create())->get(route('sites.index'));
        $response->assertOk()->assertSee('alpha-site.test')->assertSee('beta-pressable.test');

        $filtered = $this->actingAs(User::factory()->create())
            ->get(route('sites.index', ['provider' => Site::HOSTING_PROVIDER_PRESSABLE]));
        $filtered->assertOk()->assertSee('beta-pressable.test')->assertDontSee('alpha-site.test');

        $searched = $this->actingAs(User::factory()->create())
            ->get(route('sites.index', ['q' => 'alpha-site']));
        $searched->assertOk()->assertSee('alpha-site.test')->assertDontSee('beta-pressable.test');
    });

    it('only shows tabs/counts/filter for enabled hosting-provider modules — not every provider with data', function () {
        // Regression coverage: an operator running only GridPane + Vultr
        // must never see SpinupWP/Pressable tabs, be able to filter by
        // them, or have their counts included — regardless of what
        // sites.hosting_provider values happen to exist in the DB (e.g.
        // leftover rows from before GridPane was the only enabled panel).
        //
        // HostingProviderRegistry::all() is mocked directly (rather than
        // driving this through real InstalledModule rows + module
        // enablement) because ModuleRegistry's contents are fixed by each
        // module's own one-time ModuleServiceProvider::register() call
        // during this test's application bootstrap — which happens before
        // this test body runs, so it can't be changed by writing
        // installed_modules afterward without manually re-registering
        // every affected module (see ModuleEnableDisableTest.php). Mocking
        // the registry isolates what this test actually cares about: does
        // SitesController correctly build tabs/counts/filter from whatever
        // enabled providers it's given.
        Site::factory()->spinupwp()->create(['domain' => 'legacy-spinupwp.test']);
        Site::factory()->pressable()->create(['domain' => 'legacy-pressable.test']);
        Site::factory()->gridpane()->create(['domain' => 'active-gridpane.test']);

        $gridpane = Mockery::mock(HostingProvider::class);
        $gridpane->shouldReceive('id')->andReturn(Site::HOSTING_PROVIDER_GRIDPANE);
        $gridpane->shouldReceive('label')->andReturn('GridPane');
        $this->mock(HostingProviderRegistry::class, function ($mock) use ($gridpane) {
            $mock->shouldReceive('all')->andReturn([$gridpane]);
        });

        $response = $this->actingAs(User::factory()->create())->get(route('sites.index'));

        // "All" still shows every real site regardless of whether its
        // provider's module is currently enabled — disabling a panel's
        // credentials shouldn't make Clockwork forget infrastructure that
        // genuinely still exists (e.g. mid-migration between panels). Only
        // the filter/tab UI is enablement-gated, not the underlying data.
        $response->assertOk()
            ->assertSee('active-gridpane.test')
            ->assertSee('legacy-spinupwp.test')
            ->assertSee('legacy-pressable.test')
            ->assertDontSee('id="sites-provider-tabs"', false); // only 1 enabled provider — tab bar itself doesn't render

        // Filtering by a disabled provider must not silently apply the
        // filter — falls back to "all" instead of returning zero rows.
        $filtered = $this->actingAs(User::factory()->create())
            ->get(route('sites.index', ['provider' => Site::HOSTING_PROVIDER_SPINUPWP]));
        $filtered->assertOk()
            ->assertSee('legacy-spinupwp.test')
            ->assertSee('active-gridpane.test');
    });

    it('renders the live search input, clear button, and data-search attributes', function () {
        Site::factory()->spinupwp()->create(['domain' => 'gamma-site.test']);

        $response = $this->actingAs(User::factory()->create())->get(route('sites.index'));
        $response->assertOk()
            ->assertSee('id="sites-search"', false)
            ->assertSee('id="sites-search-clear"', false)
            ->assertSee('data-search="gamma-site.test', false);
    });
});

describe('search', function () {
    it('returns an empty result set for a query shorter than 2 chars', function () {
        Site::factory()->spinupwp()->create(['domain' => 'findme.test']);

        $response = $this->actingAs(User::factory()->create())->get(route('sites.search', ['q' => 'f']));

        $response->assertOk()->assertExactJson(['results' => []]);
    });

    it('returns matching sites, prefix matches first', function () {
        [$server, $site] = spinupSite(['domain' => 'findme.test']);
        Site::factory()->spinupwp()->create(['domain' => 'other-findme-suffix.test']);

        $response = $this->actingAs(User::factory()->create())->get(route('sites.search', ['q' => 'findme']));

        $response->assertOk();
        $results = $response->json('results');
        expect($results[0]['domain'])->toBe('findme.test');
        expect($results[0]['server'])->toBe($server->name);
        expect($results[0]['url'])->toBe(route('sites.show', $site));
    });
});

describe('show', function () {
    it('renders the overview tab by default', function () {
        [, $site] = spinupSite(['domain' => 'overview-tab.test']);

        $response = $this->actingAs(User::factory()->create())->get(route('sites.show', $site));

        $response->assertOk()->assertViewHas('tab', 'overview')->assertSee('overview-tab.test');
    });

    it('renders the settings tab when requested', function () {
        [, $site] = spinupSite();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('sites.show', ['site' => $site, 'tab' => 'settings']));

        $response->assertOk()->assertViewHas('tab', 'settings');
    });

    it('falls back the traffic and bans tabs to overview for a provider without CAP_SSH', function () {
        $site = Site::factory()->pressable()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('sites.show', ['site' => $site, 'tab' => 'traffic']))
            ->assertOk()->assertViewHas('tab', 'overview');

        $this->actingAs(User::factory()->create())
            ->get(route('sites.show', ['site' => $site, 'tab' => 'bans']))
            ->assertOk()->assertViewHas('tab', 'overview');
    });

    it('keeps the traffic tab for a provider WITH CAP_SSH', function () {
        [, $site] = spinupSite();

        $this->actingAs(User::factory()->create())
            ->get(route('sites.show', ['site' => $site, 'tab' => 'traffic']))
            ->assertOk()->assertViewHas('tab', 'traffic');
    });
});

describe('updateCert', function () {
    it('updates cert fields and redirects to the settings tab', function () {
        [, $site] = spinupSite(['cert_source' => Site::CERT_SOURCE_NONE]);

        $response = $this->actingAs(User::factory()->create())->patch(route('sites.cert.update', $site), [
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
            'cert_notes' => 'Managed by client DNS host.',
        ]);

        $response->assertRedirect(route('sites.show', ['site' => $site, 'tab' => 'settings']));
        $response->assertSessionHas('status', 'Cert details updated.');
        expect($site->fresh()->cert_source)->toBe(Site::CERT_SOURCE_EXTERNAL);
        expect($site->fresh()->cert_notes)->toBe('Managed by client DNS host.');
    });

    it('rejects an invalid cert_source with a validation error', function () {
        [, $site] = spinupSite();

        $response = $this->actingAs(User::factory()->create())->patch(route('sites.cert.update', $site), [
            'cert_source' => 'not-a-real-source',
        ]);

        $response->assertSessionHasErrors('cert_source');
    });
});

describe('recheckCert', function () {
    it('returns the refreshed state on success', function () {
        [, $site] = spinupSite();

        $this->mock(SiteCertRefresher::class)
            ->shouldReceive('refresh')
            ->once()
            ->withArgs(fn (Site $s) => $s->is($site))
            ->andReturn(['from_state' => 'unknown', 'to_state' => 'valid', 'expires_at' => '2027-01-01 00:00:00', 'renews_at' => null]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.cert.recheck', $site));

        $response->assertOk()->assertJson(['ok' => true, 'state' => 'valid']);
    });

    it('returns 422 with the exception message on failure', function () {
        [, $site] = spinupSite();

        $this->mock(SiteCertRefresher::class)
            ->shouldReceive('refresh')
            ->once()
            ->andThrow(new RuntimeException('SpinupWP API token is not configured.'));

        $response = $this->actingAs(User::factory()->create())->post(route('sites.cert.recheck', $site));

        $response->assertStatus(422)->assertJson(['ok' => false, 'message' => 'SpinupWP API token is not configured.']);
    });

    it('uses LiveCertProbe instead of SiteCertRefresher for a non-CAP_CERT_SYNC (Pressable) site', function () {
        $site = Site::factory()->pressable()->create(['cert_source' => Site::CERT_SOURCE_LIVE_PROBE, 'cert_state' => Site::SSL_STATE_NONE]);

        $this->mock(SiteCertRefresher::class)->shouldReceive('refresh')->never();
        $this->mock(LiveCertProbe::class)
            ->shouldReceive('expiryFor')
            ->once()
            ->withArgs(fn (string $domain) => $domain === $site->domain)
            ->andReturn(CarbonImmutable::now()->addDays(60));

        $response = $this->actingAs(User::factory()->create())->post(route('sites.cert.recheck', $site));

        $response->assertOk()->assertJsonPath('ok', true);
        expect($site->fresh()->cert_source)->toBe(Site::CERT_SOURCE_LIVE_PROBE);
        expect($site->fresh()->cert_expires_at)->not->toBeNull();
    });

    it('returns 422 when the live probe cannot reach the domain', function () {
        $site = Site::factory()->pressable()->create(['cert_source' => Site::CERT_SOURCE_LIVE_PROBE]);

        $this->mock(LiveCertProbe::class)->shouldReceive('expiryFor')->once()->andReturn(null);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.cert.recheck', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('refuses to re-probe a site with a manually overridden cert source', function () {
        $site = Site::factory()->pressable()->create(['cert_source' => Site::CERT_SOURCE_EXTERNAL]);

        $this->mock(LiveCertProbe::class)->shouldReceive('expiryFor')->never();

        $response = $this->actingAs(User::factory()->create())->post(route('sites.cert.recheck', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });
});

describe('recheckUptime', function () {
    it('refuses when uptime monitoring is disabled for the site', function () {
        [, $site] = spinupSite(['uptime_monitoring_enabled' => false]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.uptime.recheck', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('probes and reports up on success', function () {
        [, $site] = spinupSite(['uptime_monitoring_enabled' => true]);

        $this->mock(UptimeProber::class)
            ->shouldReceive('probe')
            ->once()
            ->andReturn(UptimeProbeResult::success(200, 120));

        $this->mock(UptimeStateUpdater::class)->shouldReceive('update')->once();

        $response = $this->actingAs(User::factory()->create())->post(route('sites.uptime.recheck', $site));

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('status_code', 200);
    });
});

describe('refreshWpPlugins', function () {
    it('reports detected state on success', function () {
        [, $site] = spinupSite();

        $this->mock(WpPluginDetector::class)
            ->shouldReceive('detect')
            ->once()
            ->andReturn(['result' => WpPluginDetector::RESULT_DETECTED, 'message' => 'ok', 'llar' => true, 'wordfence' => false]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.wp-plugins.refresh', $site));

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('result', WpPluginDetector::RESULT_DETECTED);
    });

    it('returns 422 when detection fails', function () {
        [, $site] = spinupSite();

        $this->mock(WpPluginDetector::class)
            ->shouldReceive('detect')
            ->once()
            ->andReturn(['result' => WpPluginDetector::RESULT_FAILED, 'message' => 'Missing site_user.', 'llar' => null, 'wordfence' => null]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.wp-plugins.refresh', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });
});

describe('fetchDbCreds', function () {
    it('redirects with a success flash on extraction success', function () {
        [, $site] = spinupSite();

        $this->mock(WpConfigExtractor::class)
            ->shouldReceive('extractAndStore')
            ->once()
            ->withArgs(fn (Site $s) => $s->is($site))
            ->andReturn(['db_name' => 'wp_db', 'db_user' => 'wp_user', 'db_password' => 'secret', 'db_host' => 'localhost', 'db_port' => 3306, 'table_prefix' => 'wp_']);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.fetch-db-creds', $site));

        $response->assertRedirect();
        $response->assertSessionHas('status', "DB credentials fetched for {$site->domain}.");
    });

    it('redirects with an error flash when extraction throws', function () {
        [, $site] = spinupSite();

        $this->mock(WpConfigExtractor::class)
            ->shouldReceive('extractAndStore')
            ->once()
            ->andThrow(new RuntimeException('wp-config.php not found or unreadable'));

        $response = $this->actingAs(User::factory()->create())->post(route('sites.fetch-db-creds', $site));

        $response->assertRedirect();
        $response->assertSessionHas('status_error');
    });
});

describe('installLlar', function () {
    it('reports installed on success', function () {
        [, $site] = spinupSite();

        $this->mock(LlarInstaller::class)
            ->shouldReceive('process')
            ->once()
            ->andReturn(['result' => LlarInstaller::RESULT_INSTALLED, 'message' => 'Installed.']);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.llar.install', $site));

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('result', LlarInstaller::RESULT_INSTALLED);
    });

    it('returns 422 on failure', function () {
        [, $site] = spinupSite();

        $this->mock(LlarInstaller::class)
            ->shouldReceive('process')
            ->once()
            ->andReturn(['result' => LlarInstaller::RESULT_FAILED, 'message' => 'Site has no linked server.']);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.llar.install', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });
});

describe('unbanIp', function () {
    it('404s when the BlockedIp belongs to a different site', function () {
        [, $site] = spinupSite();
        [$otherServer, $otherSite] = spinupSite();
        $blockedIp = BlockedIp::factory()->for($otherSite)->for($otherServer)->create();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('sites.bans.unban', [$site, $blockedIp]));

        $response->assertNotFound();
    });

    it('unbans on success and marks unbanned_at', function () {
        [$server, $site] = spinupSite();
        $blockedIp = BlockedIp::factory()->for($site)->for($server)->create(['ip' => '198.51.100.10']);

        $this->mock(Fail2banClient::class)
            ->shouldReceive('unbanIp')
            ->once()
            ->withArgs(fn (Server $s, string $ip) => $s->is($server) && $ip === '198.51.100.10')
            ->andReturn(['ok' => true, 'output' => 'ok', 'message' => 'Unbanned']);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('sites.bans.unban', [$site, $blockedIp]));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Unbanned 198.51.100.10.');
        expect($blockedIp->fresh()->unbanned_at)->not->toBeNull();
    });

    it('flashes an error and does not call fail2ban when the ban has no server', function () {
        [, $site] = spinupSite();
        $blockedIp = BlockedIp::factory()->for($site)->create(['server_id' => null]);

        $this->mock(Fail2banClient::class)->shouldNotReceive('unbanIp');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('sites.bans.unban', [$site, $blockedIp]));

        $response->assertSessionHas('status_error');
        expect($blockedIp->fresh()->unbanned_at)->toBeNull();
    });
});

describe('unbanAll', function () {
    it('flashes "no active bans" when there is nothing to clear', function () {
        [, $site] = spinupSite();

        $this->mock(Fail2banClient::class)->shouldNotReceive('unbanIps');

        $response = $this->actingAs(User::factory()->create())->post(route('sites.bans.unban-all', $site));

        $response->assertSessionHas('status', 'No active bans to clear.');
    });

    it('unbans every active ban on the site\'s server in one call', function () {
        [$server, $site] = spinupSite();
        $a = BlockedIp::factory()->for($site)->for($server)->create(['ip' => '198.51.100.20']);
        $b = BlockedIp::factory()->for($site)->for($server)->create(['ip' => '198.51.100.21']);

        $this->mock(Fail2banClient::class)
            ->shouldReceive('unbanIps')
            ->once()
            ->withArgs(fn (Server $s, array $ips) => $s->is($server) && count($ips) === 2)
            ->andReturn(['results' => [
                '198.51.100.20' => ['ok' => true, 'output' => 'ok'],
                '198.51.100.21' => ['ok' => true, 'output' => 'ok'],
            ]]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.bans.unban-all', $site));

        $response->assertSessionHas('status', "Unbanned 2 of 2 for {$site->domain}.");
        expect($a->fresh()->unbanned_at)->not->toBeNull();
        expect($b->fresh()->unbanned_at)->not->toBeNull();
    });
});

describe('installCompanion', function () {
    it('installs and records an action_log entry on success', function () {
        [, $site] = spinupSite();

        $this->mock(CompanionInstaller::class)
            ->shouldReceive('installOrUpdate')
            ->once()
            ->andReturn(['result' => CompanionInstaller::RESULT_INSTALLED, 'message' => 'Installed Companion.', 'version' => '1.30.4']);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.install', $site));

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('version', '1.30.4');
        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_COMPANION_INSTALL)->exists())->toBeTrue();
    });

    it('returns 422 and includes output on failure', function () {
        [, $site] = spinupSite();

        $this->mock(CompanionInstaller::class)
            ->shouldReceive('installOrUpdate')
            ->once()
            ->andReturn(['result' => CompanionInstaller::RESULT_FAILED, 'message' => 'SSH connect failed.', 'output' => 'Connection refused']);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.install', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('output', 'Connection refused');
    });

    it('returns 422 with a clear message instead of a 500 when the hosting provider is in View-Only mode', function () {
        // GridPane defaults view_only=true with no credentials configured (the
        // normal state in a fresh test env) — companionInstaller() returns
        // null by contract, and this must not crash with "member function
        // installOrUpdate() on null" the way it did before this guard existed.
        $site = Site::factory()->gridpane()->create();

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.install', $site));

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'View-Only mode'));
        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_COMPANION_INSTALL)->exists())->toBeFalse();
    });
});

describe('pushCompanionData', function () {
    it('returns 422 when Companion is not installed', function () {
        [, $site] = spinupSite(['companion_installed' => false]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.push-update', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('reports per-leg failures cleanly when no capabilities are advertised, skipping backups cleanly on a non-SpinupWP site', function () {
        $site = Site::factory()->pressable()->withCompanionInstalled()->create(['companion_capabilities' => []]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.push-update', $site));

        // Pressable has no SpinupWP backup config to fetch — that's not a
        // failure, so the backups leg alone makes the overall response ok.
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('results.snapshot.ok', false);
        $response->assertJsonPath('results.backups.ok', true);
        $response->assertJsonPath('results.backups.skipped', true);
        $response->assertJsonPath('results.traffic.ok', false);
    });

    it('pushes snapshot + backups successfully when both capabilities are advertised', function () {
        [, $site] = spinupSite([
            'companion_installed' => true,
            'companion_secret' => Str::random(40),
            'companion_capabilities' => ['snapshot', 'backups-report'],
            'domain' => 'push-companion-data.test',
        ]);

        $this->mock(SpinupWpClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('siteBackupConfig')->once()->andReturn(['enabled' => true]);
        });
        $this->mock(SpacesClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/snapshot" => Http::response(
                ['plugins' => ['counts' => ['total' => 5, 'updates_available' => 1]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(
                ['ok' => true],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.push-update', $site));

        $response->assertOk();
        $response->assertJsonPath('results.snapshot.ok', true);
        $response->assertJsonPath('results.backups.ok', true);
        $response->assertJsonPath('results.traffic.ok', false);
        expect($site->fresh()->companion_snapshot['plugins']['counts']['total'])->toBe(5);
    });
});

describe('refreshCompanionSnapshot', function () {
    it('returns 422 when Companion is not installed', function () {
        [, $site] = spinupSite(['companion_installed' => false]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.refresh-snapshot', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('returns 422 when the installed Companion lacks the snapshot capability', function () {
        $site = companionSite(['companion_capabilities' => []]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.refresh-snapshot', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('pulls and persists a fresh snapshot', function () {
        $site = companionSite(['companion_capabilities' => ['snapshot']]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/snapshot" => Http::response(
                ['plugins' => ['counts' => ['total' => 3, 'updates_available' => 2]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.refresh-snapshot', $site));

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('updates', 2);
        expect($site->fresh()->companion_snapshot_at)->not->toBeNull();
    });
});

describe('ssoLaunch', function () {
    it('redirects back with an error when Companion is not installed', function () {
        [, $site] = spinupSite(['companion_installed' => false]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.sso', $site));

        $response->assertRedirect();
        $response->assertSessionHas('status_error', 'Companion is not installed on this site.');
    });

    it('redirects back with an error when the snapshot has no admins', function () {
        $site = companionSite(['companion_snapshot' => ['admins' => ['admins' => []]]]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.sso', $site));

        $response->assertRedirect();
        $response->assertSessionHas('status_error');
    });

    it('mints a magic link and redirects the operator away to it', function () {
        $site = companionSite(['companion_snapshot' => ['admins' => ['admins' => [['login' => 'siteadmin']]]]]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/sso/magic-link" => Http::response(
                ['url' => 'https://'.$site->domain.'/wp-login-magic-abc123', 'expires_at' => now()->addMinute()->toIso8601String()],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.sso', $site));

        $response->assertRedirect('https://'.$site->domain.'/wp-login-magic-abc123');
        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_SSO_LOGIN)->exists())->toBeTrue();
    });
});

describe('updatePlugin', function () {
    it('returns 422 when Companion is not installed', function () {
        [, $site] = spinupSite(['companion_installed' => false]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.plugin-update', $site), ['slug' => 'akismet']);

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('returns 422 when the installed Companion lacks the updates capability', function () {
        $site = companionSite(['companion_capabilities' => []]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.plugin-update', $site), ['slug' => 'akismet']);

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('returns 422 when slug is missing', function () {
        $site = companionSite(['companion_capabilities' => ['updates']]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.companion.plugin-update', $site), []);

        $response->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('error', 'Missing `slug`.');
    });

    it('updates the plugin and logs the action on success', function () {
        $site = companionSite(['companion_capabilities' => ['updates']]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/update" => Http::response([
                'ok' => true,
                'slug' => 'akismet',
                'before_version' => '5.3',
                'after_version' => '5.4',
                'was_active' => true,
                'reactivated' => true,
                'messages' => [],
                'elapsed_ms' => 1500,
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('sites.companion.plugin-update', $site), ['slug' => 'akismet']);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('after_version', '5.4');
        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_PLUGIN_UPDATE)->exists())->toBeTrue();
    });
});

describe('flushPressableObjectCache', function () {
    it('returns 422 for a non-Pressable site', function () {
        [, $site] = spinupSite();

        $response = $this->actingAs(User::factory()->create())->post(route('sites.pressable.flush-object-cache', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('schedules a flush for a Pressable site', function () {
        $site = Site::factory()->pressable()->create();

        $this->mock(PressableClient::class)
            ->shouldReceive('flushObjectCache')
            ->once()
            ->with($site->pressable_site_id)
            ->andReturn(null);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.pressable.flush-object-cache', $site));

        $response->assertOk()->assertJsonPath('ok', true);
    });
});

describe('pressableResourceMetrics', function () {
    it('returns 422 for a non-Pressable site', function () {
        [, $site] = spinupSite();

        $response = $this->actingAs(User::factory()->create())->get(route('sites.pressable.resource-metrics', $site));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('returns cpu + mysql metrics for a Pressable site', function () {
        $site = Site::factory()->pressable()->create();

        $this->mock(PressableClient::class, function ($mock) use ($site) {
            $mock->shouldReceive('siteMetrics')
                ->once()
                ->with($site->pressable_site_id, ['cgroup_cpu_usage'], ['server'], 'Past 1 day')
                ->andReturn(['series' => []]);
            $mock->shouldReceive('siteMetrics')
                ->once()
                ->with($site->pressable_site_id, ['mysql_cpu_time', 'mysql_busy_time', 'mysql_total_connections'], ['server'], 'Past 1 day')
                ->andReturn(['series' => []]);
        });

        $response = $this->actingAs(User::factory()->create())->get(route('sites.pressable.resource-metrics', $site));

        $response->assertOk()->assertJsonPath('ok', true);
    });
});

describe('emailVulnerabilityReport', function () {
    it('rejects an invalid email address', function () {
        [, $site] = spinupSite();

        // postJson (not post) — this route's real caller is an AJAX modal that
        // sends Accept: application/json, so $request->validate()'s failure
        // renders as a 422 JSON error response rather than a redirect-back.
        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('sites.email-vuln-report', $site), ['recipient' => 'not-an-email']);

        $response->assertStatus(422);
    });

    it('returns 422 when there are no matching vulnerabilities', function () {
        [, $site] = spinupSite();

        $this->mock(PluginVulnerabilityMatcher::class)->shouldReceive('forSite')->once()->andReturn([]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('sites.email-vuln-report', $site), ['recipient' => 'client@example.com']);

        $response->assertStatus(422)->assertJsonPath('ok', false);
    });

    it('emails the report and logs the action on success', function () {
        [, $site] = spinupSite();
        Mail::fake();

        $this->mock(PluginVulnerabilityMatcher::class)
            ->shouldReceive('forSite')
            ->once()
            ->andReturn([['plugin_slug' => 'akismet', 'plugin_name' => 'Akismet', 'current_version' => '5.3', 'active' => true, 'patch_available' => true]]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('sites.email-vuln-report', $site), ['recipient' => 'client@example.com']);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('count', 1);
        Mail::assertSent(SiteVulnerabilityReportMail::class, fn ($mail) => $mail->hasTo('client@example.com'));
        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', 'site.email-vuln-report')->exists())->toBeTrue();
    });
});

describe('toggle actions', function () {
    // Only toggleCarePlan() and togglePauseAutoUpdates() branch on
    // $request->expectsJson() — toggleUptimeMonitoring/toggleUptimeIgnore/
    // toggleInactive always return a RedirectResponse regardless of the
    // Accept header (read straight from SitesController), so only those
    // two get a JSON-request sub-test.
    $cases = [
        'care-plan' => ['route' => 'sites.care-plan', 'param' => 'enabled', 'column' => 'care_plan_enabled', 'json' => true],
        'auto-updates' => ['route' => 'sites.auto-updates.toggle', 'param' => 'paused', 'column' => 'auto_updates_paused', 'json' => true],
        'uptime-monitoring' => ['route' => 'sites.uptime-monitoring.toggle', 'param' => 'enabled', 'column' => 'uptime_monitoring_enabled', 'json' => false],
        'uptime-body-check' => ['route' => 'sites.uptime-body-check.toggle', 'param' => 'skip', 'column' => 'uptime_skip_body_check', 'json' => false],
        'uptime-ignore' => ['route' => 'sites.uptime-ignore.toggle', 'param' => 'ignore', 'column' => 'uptime_ignored_at', 'nonBoolColumn' => true, 'json' => false],
        'inactive' => ['route' => 'sites.inactive.toggle', 'param' => 'inactive', 'column' => 'is_inactive', 'json' => false],
    ];

    foreach ($cases as $name => $case) {
        it("flips {$name} on and off via a redirect (form submit)", function () use ($case) {
            [, $site] = spinupSite();

            $this->actingAs(User::factory()->create())
                ->post(route($case['route'], $site), [$case['param'] => '1'])
                ->assertRedirect();

            $fresh = $site->fresh();
            if ($case['nonBoolColumn'] ?? false) {
                expect($fresh->{$case['column']})->not->toBeNull();
            } else {
                expect((bool) $fresh->{$case['column']})->toBeTrue();
            }

            $this->actingAs(User::factory()->create())
                ->post(route($case['route'], $site), [$case['param'] => '0'])
                ->assertRedirect();

            $fresh = $site->fresh();
            if ($case['nonBoolColumn'] ?? false) {
                expect($fresh->{$case['column']})->toBeNull();
            } else {
                expect((bool) $fresh->{$case['column']})->toBeFalse();
            }
        });

        if ($case['json']) {
            it("flips {$name} via a JSON request and returns the new state", function () use ($case) {
                [, $site] = spinupSite();

                $response = $this->actingAs(User::factory()->create())
                    ->postJson(route($case['route'], $site), [$case['param'] => '1']);

                $response->assertOk()->assertJsonPath('ok', true);
            });
        }
    }

    it('sets uptime_sla_exempt and retroactively excuses active down event on toggleUptimeIgnore', function () {
        [, $site] = spinupSite([
            'uptime_state' => 'down',
            'uptime_down_since' => now()->subHours(10),
        ]);

        $downEvent = SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'event_at' => now()->subHours(10),
            'is_sla_exempt' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('sites.uptime-ignore.toggle', $site), [
                'ignore' => '1',
                'is_sla_exempt' => '1',
                'exemption_reason' => SiteUptimeEvent::REASON_CLIENT_DNS,
                'reason' => 'Client pointed nameservers away',
            ])
            ->assertRedirect();

        $freshSite = $site->fresh();
        $freshEvent = $downEvent->fresh();

        expect($freshSite->uptime_ignored_at)->not->toBeNull()
            ->and($freshSite->uptime_sla_exempt)->toBeTrue()
            ->and($freshSite->uptime_exemption_reason)->toBe(SiteUptimeEvent::REASON_CLIENT_DNS)
            ->and($freshEvent->is_sla_exempt)->toBeTrue()
            ->and($freshEvent->exemption_reason)->toBe(SiteUptimeEvent::REASON_CLIENT_DNS);
    });

    it('rejects an unknown exemption reason on toggleUptimeIgnore', function () {
        [, $site] = spinupSite();

        $this->actingAs(User::factory()->create())
            ->from(route('sites.show', $site))
            ->post(route('sites.uptime-ignore.toggle', $site), [
                'ignore' => '1',
                'is_sla_exempt' => '1',
                'exemption_reason' => 'not_a_real_reason',
            ])
            ->assertSessionHasErrors('exemption_reason');
    });
});

describe('clearCarePlanOverride', function () {
    it('nulls the override and redirects with a flash', function () {
        [, $site] = spinupSite(['care_plan_override' => true]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.care-plan.clear-override', $site));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Care plan override cleared. The next Bill.com sync will re-derive the flag.');
        expect($site->fresh()->care_plan_override)->toBeNull();
    });
});

describe('archive', function () {
    it('rejects a confirmation that does not match the domain', function () {
        [, $site] = spinupSite();

        $response = $this->actingAs(User::factory()->create())->post(route('sites.archive', $site), [
            'confirm_domain' => 'wrong-domain.test',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status_error', 'Confirmation text did not match the site domain.');
        expect($site->fresh()->archived_at)->toBeNull();
    });

    it('requires confirm_domain (validation error)', function () {
        [, $site] = spinupSite();

        $response = $this->actingAs(User::factory()->create())->post(route('sites.archive', $site), []);

        $response->assertSessionHasErrors('confirm_domain');
    });

    it('archives the site, clears spinupwp_id, records an ingest exclusion, and redirects to the server page', function () {
        [$server, $site] = spinupSite(['domain' => 'to-archive.test', 'spinupwp_id' => 5150]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.archive', $site), [
            'confirm_domain' => 'to-archive.test',
            'reason' => 'client cancelled',
        ]);

        $response->assertRedirect(route('servers.show', $server));
        $response->assertSessionHas('status', 'Archived to-archive.test. It is hidden from Clockwork and will not be re-imported. The WordPress site on the host was not deleted.');
        $fresh = $site->fresh();
        expect($fresh->archived_at)->not->toBeNull();
        expect($fresh->spinupwp_id)->toBeNull();
        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', 'site.archive')->exists())->toBeTrue();

        $exclusion = SiteIngestExclusion::query()->where('site_id', $site->id)->first();
        expect($exclusion)->not->toBeNull()
            ->and($exclusion->hosting_provider)->toBe(Site::HOSTING_PROVIDER_SPINUPWP)
            ->and($exclusion->domain)->toBe('to-archive.test')
            ->and($exclusion->provider_site_id)->toBe('5150')
            ->and($exclusion->reason)->toBe('client cancelled');
    });

    it('redirects to sites.index when the site has no server (e.g. Pressable) and clears pressable_site_id', function () {
        $site = Site::factory()->pressable()->create([
            'domain' => 'to-archive-pressable.test',
            'pressable_site_id' => '999000',
        ]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.archive', $site), [
            'confirm_domain' => 'to-archive-pressable.test',
        ]);

        $response->assertRedirect(route('sites.index'));
        $fresh = $site->fresh();
        expect($fresh->archived_at)->not->toBeNull()
            ->and($fresh->pressable_site_id)->toBeNull();
        expect(SiteIngestExclusion::query()->where('domain', 'to-archive-pressable.test')->exists())->toBeTrue();
    });

    it('archives a custom site without writing an ingest exclusion', function () {
        $site = Site::factory()->custom()->create(['domain' => 'to-archive-custom.test']);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.archive', $site), [
            'confirm_domain' => 'to-archive-custom.test',
        ]);

        $response->assertRedirect(route('sites.index'));
        $response->assertSessionHas('status', 'Archived to-archive-custom.test. The row is hidden from all listings; historical data is retained.');
        expect($site->fresh()->archived_at)->not->toBeNull();
        expect(SiteIngestExclusion::query()->count())->toBe(0);
    });

    it('404s for an already-archived site — the notArchived global scope hides it from route-model binding before the controller\'s own already-archived check can run', function () {
        [, $site] = spinupSite(['domain' => 'already-archived.test', 'archived_at' => now()]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.archive', $site), [
            'confirm_domain' => 'already-archived.test',
        ]);

        $response->assertNotFound();
    });
});

describe('unarchive', function () {
    it('restores an archived site, clears its ingest exclusion, and redirects to its show page', function () {
        [, $site] = spinupSite(['archived_at' => now(), 'spinupwp_id' => null]);
        SiteIngestExclusion::factory()->create([
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'domain' => $site->domain,
            'site_id' => $site->id,
            'provider_site_id' => '5150',
        ]);

        $response = $this->actingAs(User::factory()->create())->post(route('sites.unarchive', ['siteId' => $site->id]));

        $response->assertRedirect(route('sites.show', $site->id));
        expect($site->fresh()->archived_at)->toBeNull();
        expect(SiteIngestExclusion::query()->where('site_id', $site->id)->exists())->toBeFalse();
        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', 'site.unarchive')->exists())->toBeTrue();
    });

    it('flashes "not archived" for a site that is not archived', function () {
        [, $site] = spinupSite();

        $response = $this->actingAs(User::factory()->create())->post(route('sites.unarchive', ['siteId' => $site->id]));

        $response->assertSessionHas('status', "{$site->domain} is not archived.");
    });
});
