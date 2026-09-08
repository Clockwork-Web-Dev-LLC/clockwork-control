<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\Tag;
use App\Services\Security\WpCoreChecksumVerifier;
use App\Services\Ssh\SshClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pressable\PressableCommandRunner;
use Tests\TestCase;

/**
 * Characterization tests for site-level hosting-provider gating, written
 * ahead of the HostingProvider-contract refactor (modularization Phase 0).
 * Pins scopeHostMonitored()'s current SpinupWP-vs-Pressable rules and
 * WpCoreChecksumVerifier's transport choice, so the contract conversion
 * (Phase 5) can prove the fixture-fleet result is unchanged.
 */
class SiteProviderGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_monitored_scope_fixture_fleet(): void
    {
        $activeServer = Server::create([
            'name' => 'active.example.com', 'hostname' => '203.0.113.1', 'ssh_user' => 'clockwork-deploy',
        ]);
        $ignoredServer = Server::create([
            'name' => 'ignored.example.com', 'hostname' => '203.0.113.2', 'ssh_user' => 'clockwork-deploy',
            'is_ignored' => true,
        ]);
        $stagingServer = Server::create([
            'name' => 'staging.example.com', 'hostname' => '203.0.113.3', 'ssh_user' => 'clockwork-deploy',
        ]);
        $stagingTag = Tag::create(['name' => 'Staging', 'slug' => 'staging']);
        $stagingServer->tags()->attach($stagingTag);

        // SpinupWP sites: eligibility follows the server.
        $onActive = Site::create(['domain' => 'on-active.example.com', 'server_id' => $activeServer->id, 'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP]);
        $onIgnored = Site::create(['domain' => 'on-ignored.example.com', 'server_id' => $ignoredServer->id, 'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP]);
        $onStaging = Site::create(['domain' => 'on-staging.example.com', 'server_id' => $stagingServer->id, 'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP]);

        // Pressable sites: always eligible regardless of server_id (which is
        // always null for Pressable — no server-level ignore/staging concept).
        $pressableSite = Site::create(['domain' => 'on-pressable.example.com', 'server_id' => null, 'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE, 'pressable_site_id' => '12345']);

        $monitored = Site::query()->hostMonitored()->pluck('domain')->sort()->values()->all();

        $this->assertSame([
            'on-active.example.com',
            'on-pressable.example.com',
        ], $monitored);

        $this->assertNotContains($onIgnored->domain, $monitored);
        $this->assertNotContains($onStaging->domain, $monitored);
    }

    public function test_is_pressable_and_is_spinupwp_predicates(): void
    {
        $pressable = new Site(['domain' => 'x.example.com', 'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE]);
        $spinup = new Site(['domain' => 'y.example.com', 'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP]);

        $this->assertTrue($pressable->isPressable());
        $this->assertFalse($pressable->isSpinupWp());
        $this->assertTrue($spinup->isSpinupWp());
        $this->assertFalse($spinup->isPressable());
    }

    public function test_checksum_verifier_uses_ssh_transport_for_spinupwp_site(): void
    {
        $server = Server::create([
            'name' => 'srv.example.com', 'hostname' => '203.0.113.9', 'ssh_user' => 'clockwork-deploy',
            'ssh_password' => 'secret',
        ]);
        $site = Site::create([
            'domain' => 'web-site.example.com', 'server_id' => $server->id,
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'is_wordpress' => true, 'site_user' => 'siteuser',
        ]);

        $ssh = $this->mock(SshClient::class);
        $ssh->shouldReceive('exec')->withArgs(fn ($s) => $s->is($server))->once()
            ->andReturn('__CLOCKWORK_WP_EXIT__:0');
        $pressableRunner = $this->mock(PressableCommandRunner::class);
        $pressableRunner->shouldNotReceive('run');

        app(WpCoreChecksumVerifier::class)->verify($site->fresh());
    }

    public function test_checksum_verifier_uses_pressable_transport_for_pressable_site(): void
    {
        $site = Site::create([
            'domain' => 'pressable-site.example.com', 'server_id' => null,
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE, 'pressable_site_id' => '99887',
            'is_wordpress' => true,
        ]);

        $ssh = $this->mock(SshClient::class);
        $ssh->shouldNotReceive('exec');
        $pressableRunner = $this->mock(PressableCommandRunner::class);
        $pressableRunner->shouldReceive('run')->withArgs(['99887', \Mockery::type('string'), \Mockery::type('int')])->once()
            ->andReturn(['ok' => true, 'exit' => 0, 'output' => '']);

        app(WpCoreChecksumVerifier::class)->verify($site->fresh());
    }

    public function test_resolve_wp_path_prefers_the_recorded_path_over_any_convention(): void
    {
        $site = new Site(['domain' => 'x.example.com', 'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP, 'wp_path' => '/custom/path']);

        $this->assertSame('/custom/path', $site->resolveWpPath());
    }

    public function test_resolve_wp_path_falls_back_to_the_spinupwp_convention(): void
    {
        $site = new Site(['domain' => 'spinup.example.com', 'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP, 'wp_path' => null]);

        $this->assertSame('/sites/spinup.example.com/files', $site->resolveWpPath());
    }

    public function test_resolve_wp_path_falls_back_to_the_gridpane_convention(): void
    {
        $site = new Site(['domain' => 'gp.example.com', 'hosting_provider' => Site::HOSTING_PROVIDER_GRIDPANE, 'wp_path' => null]);

        $this->assertSame('/var/www/gp.example.com/htdocs', $site->resolveWpPath());
    }

    public function test_resolve_wp_path_returns_null_for_a_provider_with_no_known_convention(): void
    {
        // Cloudways has no on-disk layout coded into resolveWpPath() — an
        // unrecorded wp_path must be treated as unresolvable, not guessed at
        // via the SpinupWP/GridPane conventions.
        $site = new Site(['domain' => 'cw.example.com', 'hosting_provider' => Site::HOSTING_PROVIDER_CLOUDWAYS, 'wp_path' => null]);

        $this->assertNull($site->resolveWpPath());
    }
}
