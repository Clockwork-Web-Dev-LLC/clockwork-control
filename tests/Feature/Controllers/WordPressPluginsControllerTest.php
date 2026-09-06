<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleStateResolver;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->mockIssueCounterZero();
    app(ModuleStateResolver::class)->flush();
});

it('redirects unauthenticated requests away from /settings/wordpress-plugins', function () {
    $response = $this->get(route('settings.wordpress-plugins.index'));

    $response->assertRedirect(route('login'));
});

it('lists WordPress sites on non-ignored servers with their protection totals', function () {
    $server = Server::factory()->create(['is_ignored' => false]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'is_wordpress' => true,
        'domain' => 'protected-site.test',
        'wordfence_enabled' => true,
        'llar_enabled' => false,
        'companion_installed' => true,
        'companion_version' => '1.33.0',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.wordpress-plugins.index'));

    $response->assertOk()
        ->assertSee('protected-site.test')
        ->assertSee('Installed v1.33.0')
        ->assertSee('Limit Login Attempts')
        ->assertSee('data-col="llar"', false);
});

it('excludes sites on ignored servers', function () {
    $ignoredServer = Server::factory()->ignored()->create();
    $excludedSite = Site::factory()->create([
        'server_id' => $ignoredServer->id,
        'is_wordpress' => true,
        'domain' => 'ignored-server-site.test',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.wordpress-plugins.index'));

    $response->assertOk()->assertDontSee('ignored-server-site.test');
});

it('excludes non-WordPress sites', function () {
    $server = Server::factory()->create(['is_ignored' => false]);
    $nonWpSite = Site::factory()->create([
        'server_id' => $server->id,
        'is_wordpress' => false,
        'domain' => 'not-wordpress-site.test',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.wordpress-plugins.index'));

    $response->assertOk()->assertDontSee('not-wordpress-site.test');
});

it('excludes sites with no linked server', function () {
    $orphanSite = Site::factory()->create([
        'server_id' => null,
        'is_wordpress' => true,
        'domain' => 'no-server-site.test',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.wordpress-plugins.index'));

    $response->assertOk()->assertDontSee('no-server-site.test');
});

it('shows install companion button and missing badge when companion is not installed', function () {
    $server = Server::factory()->create(['is_ignored' => false]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'is_wordpress' => true,
        'domain' => 'missing-companion.test',
        'companion_installed' => false,
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.wordpress-plugins.index'));

    $response->assertOk()
        ->assertSee('missing-companion.test')
        ->assertSee('Missing')
        ->assertSee('Install Companion');
});

it('omits LLAR column and install LLAR button when LLAR module is disabled', function () {
    InstalledModule::create([
        'module_id' => 'llar',
        'name' => 'Limit Login Attempts Reloaded',
        'enabled' => false,
    ]);
    app(ModuleStateResolver::class)->flush();

    $server = Server::factory()->create(['is_ignored' => false]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'is_wordpress' => true,
        'domain' => 'no-llar-module.test',
        'companion_installed' => true,
        'llar_enabled' => false,
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.wordpress-plugins.index'));

    $response->assertOk()
        ->assertSee('no-llar-module.test')
        ->assertDontSee('data-col="llar"', false)
        ->assertDontSee('>Install LLAR<', false);
});

it('blocks installLlar endpoint when LLAR module is disabled', function () {
    InstalledModule::create([
        'module_id' => 'llar',
        'name' => 'Limit Login Attempts Reloaded',
        'enabled' => false,
    ]);
    app(ModuleStateResolver::class)->flush();

    $server = Server::factory()->create(['is_ignored' => false]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'is_wordpress' => true,
        'domain' => 'block-install-llar.test',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->post(route('sites.llar.install', $site));

    $response->assertStatus(403)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('result', 'disabled');
});
