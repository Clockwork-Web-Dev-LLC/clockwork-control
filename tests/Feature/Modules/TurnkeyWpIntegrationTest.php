<?php

namespace Tests\Feature\Modules;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Diagnostics\CheckResult;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use App\Services\Security\WpCoreChecksumVerifier;
use App\Services\Ssh\SshClient;
use App\Support\Settings;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\Core\Contracts\HostingProvider;
use Modules\TurnkeyWp\TurnkeyWpCheck;
use Modules\TurnkeyWp\TurnkeyWpClient;
use Modules\TurnkeyWp\TurnkeyWpHostingProvider;
use Modules\TurnkeyWp\TurnkeyWpReadOnlyException;

const TK_BASE = 'https://turnkey.example.com/api/v1';

/**
 * One page of TurnkeyWP's Laravel paginator envelope.
 *
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function tkPage(array $items, int $page = 1, int $lastPage = 1): array
{
    return [
        'data' => $items,
        'links' => ['next' => $page < $lastPage ? TK_BASE.'/x?page='.($page + 1) : null],
        'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => 100, 'total' => count($items)],
    ];
}

/** @return array<string, mixed> */
function tkServer(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'name' => 'tk-web-1',
        'provider_name' => 'Digitalocean',
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'turnkey',
        'status' => 'provisioned',
        'ubuntu_version' => '24.04',
        'upgrade_required' => true,
        'reboot_required' => false,
    ], $overrides);
}

/** @return array<string, mixed> */
function tkSite(array $overrides = []): array
{
    return array_merge([
        'id' => 41,
        'server_id' => 7,
        'domain' => 'example.com',
        'site_user' => 'example',
        'status' => 'deployed',
        'is_wordpress' => true,
        'is_staging' => false,
        'database' => ['name' => 'example', 'user' => 'example', 'table_prefix' => 'wp_ab12_'],
        'https' => ['enabled' => true, 'expires_at' => '2026-12-01T00:00:00+00:00'],
        'wp_core_update' => false,
        'wp_theme_updates' => false,
        'wp_plugin_updates' => false,
    ], $overrides);
}

function fakeTurnkey(array $servers, array $sites): void
{
    Http::fake([
        'turnkey.example.com/api/v1/servers*' => Http::response(tkPage($servers)),
        'turnkey.example.com/api/v1/sites*' => Http::response(tkPage($sites)),
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'clockwork.turnkeywp.token' => 'test-tk-token',
        'clockwork.turnkeywp.base_url' => TK_BASE,
        'clockwork.turnkeywp.view_only' => false,
    ]);
});

describe('TurnkeyWpClient', function () {
    it('walks every page using meta.last_page and asks for 100 per page', function () {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            expect($q['limit'])->toBe('100');
            expect($request->hasHeader('Authorization', 'Bearer test-tk-token'))->toBeTrue();

            return Http::response(tkPage([['id' => (int) $q['page']]], (int) $q['page'], 3));
        });

        $servers = app(TurnkeyWpClient::class)->servers();

        expect(array_column($servers, 'id'))->toBe([1, 2, 3]);
    });

    it('keeps the pages it got and reports partial when a later page fails', function () {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return (int) $q['page'] === 1
                ? Http::response(tkPage([['id' => 1]], 1, 2))
                : Http::response(['message' => 'Server Error'], 500);
        });

        $client = new TurnkeyWpClient(token: 'test-tk-token', baseUrl: TK_BASE, retryAttempts: 0);
        $sites = $client->sites();

        expect($sites)->toHaveCount(1)
            ->and($client->wasPartial())->toBeTrue();
    });

    it('never resends a POST after a server error, but retries a GET', function () {
        Http::fake(['turnkey.example.com/api/v1/*' => Http::response(['message' => 'Server Error'], 500)]);
        $client = new TurnkeyWpClient(token: 'test-tk-token', baseUrl: TK_BASE, retryAttempts: 2);

        expect(fn () => $client->rebootServer(7))->toThrow(RequestException::class);
        Http::assertSentCount(1);

        expect(fn () => $client->server(7))->toThrow(RequestException::class);
        Http::assertSentCount(3);
    });

    it('refuses writes in view-only mode without calling the API', function () {
        $client = new TurnkeyWpClient(token: 'test-tk-token', baseUrl: TK_BASE, viewOnly: true);

        expect(fn () => $client->purgeCache(41))->toThrow(TurnkeyWpReadOnlyException::class);
        Http::assertNothingSent();
    });
});

describe('clockwork:import-turnkeywp', function () {
    it('fails fast without a token', function () {
        config(['clockwork.turnkeywp.token' => '']);

        $this->artisan('clockwork:import-turnkeywp')
            ->assertFailed()
            ->expectsOutputToContain('TURNKEYWP_TOKEN is not configured');
    });

    it('imports a server and a site with TurnkeyWP layout and ssh user', function () {
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        $server = Server::where('turnkeywp_server_id', '7')->sole();
        expect($server->hostname)->toBe('203.0.113.10')
            ->and($server->ssh_user)->toBe('turnkey')
            ->and($server->provider)->toBe('digitalocean')
            ->and((bool) $server->upgrade_required)->toBeTrue()
            ->and($server->passwordless_sudo)->toBeTrue()
            ->and($server->hasSudoAccess())->toBeTrue();

        $site = Site::withoutGlobalScopes()->where('turnkeywp_site_id', '41')->sole();
        expect($site->hosting_provider)->toBe(Site::HOSTING_PROVIDER_TURNKEYWP)
            ->and($site->server_id)->toBe($server->id)
            ->and($site->site_user)->toBe('example')
            ->and($site->table_prefix)->toBe('wp_ab12_')
            ->and($site->wp_path)->toBe('/sites/example.com/files')
            ->and($site->cert_source)->toBe(Site::CERT_SOURCE_LIVE_PROBE)
            // TurnkeyWP's update flags are placeholders today; never imported.
            ->and($site->wp_core_update)->toBeNull();
    });

    it('is idempotent', function () {
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();
        $this->artisan('clockwork:import-turnkeywp')
            ->assertSuccessful()
            ->expectsOutputToContain('"unchanged":1');

        expect(Server::count())->toBe(1)
            ->and(Site::withoutGlobalScopes()->count())->toBe(1);
    });

    it('writes nothing in dry-run mode', function () {
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('[DRY RUN] Sites:');

        expect(Server::count())->toBe(0)
            ->and(Site::withoutGlobalScopes()->count())->toBe(0);
    });

    it('skips staging and not-yet-deployed sites by default', function () {
        fakeTurnkey([tkServer()], [
            tkSite(['id' => 42, 'domain' => 'staging-1.203-0-113-10.sslip.io', 'is_staging' => true]),
            tkSite(['id' => 43, 'domain' => 'example.net', 'status' => 'deploying']),
        ]);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        expect(Site::withoutGlobalScopes()->count())->toBe(0);
    });

    it('imports staging sites with --include-staging, without uptime alerts', function () {
        fakeTurnkey([tkServer()], [tkSite(['id' => 42, 'domain' => 'staging-1.203-0-113-10.sslip.io', 'is_staging' => true])]);

        $this->artisan('clockwork:import-turnkeywp --include-staging')->assertSuccessful();

        $site = Site::withoutGlobalScopes()->where('turnkeywp_site_id', '42')->sole();
        expect((bool) $site->uptime_monitoring_enabled)->toBeFalse();
    });

    it('leaves a site SpinupWP still tracks alone', function () {
        $existing = Site::factory()->create(['domain' => 'example.com', 'spinupwp_id' => '9001']);
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp')
            ->assertSuccessful()
            ->expectsOutputToContain('still tracked by');

        $existing->refresh();
        expect($existing->hosting_provider)->toBe(Site::HOSTING_PROVIDER_SPINUPWP)
            ->and($existing->turnkeywp_site_id)->toBeNull()
            ->and($existing->spinupwp_id)->toBe('9001');
    });

    it('adopts the same row once SpinupWP no longer tracks it', function () {
        $existing = Site::factory()->create(['domain' => 'example.com', 'spinupwp_id' => null]);
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp')
            ->assertSuccessful()
            ->expectsOutputToContain('"adopted":1');

        $existing->refresh();
        expect($existing->hosting_provider)->toBe(Site::HOSTING_PROVIDER_TURNKEYWP)
            ->and($existing->turnkeywp_site_id)->toBe('41');
    });

    it('takes over a SpinupWP-tracked site right away with --adopt', function () {
        $existing = Site::factory()->create(['domain' => 'example.com', 'spinupwp_id' => '9001']);
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp --adopt=example.com')->assertSuccessful();

        $existing->refresh();
        expect($existing->hosting_provider)->toBe(Site::HOSTING_PROVIDER_TURNKEYWP)
            ->and($existing->spinupwp_id)->toBeNull()
            ->and($existing->turnkeywp_site_id)->toBe('41');
    });

    it('only links a server SpinupWP still tracks, keeping its ssh user', function () {
        $server = Server::factory()->create([
            'hostname' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'clockwork-deploy',
            'spinupwp_id' => 555,
            'name' => 'old-name',
        ]);
        fakeTurnkey([tkServer()], []);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        $server->refresh();
        expect($server->turnkeywp_server_id)->toBe('7')
            ->and($server->ssh_user)->toBe('clockwork-deploy')
            ->and($server->name)->toBe('old-name');
    });

    it('clears the id of a site TurnkeyWP stopped listing on a server it still lists', function () {
        $server = Server::factory()->create(['hostname' => '203.0.113.10', 'ssh_port' => 22, 'turnkeywp_server_id' => '7']);
        $gone = Site::factory()->turnkeywp()->create(['domain' => 'example.org', 'server_id' => $server->id, 'turnkeywp_site_id' => '999']);
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        expect($gone->refresh()->turnkeywp_site_id)->toBeNull();
    });

    it('keeps sites and servers a restricted token cannot see', function () {
        $hidden = Server::factory()->create(['name' => 'hidden-box', 'hostname' => '203.0.113.99', 'turnkeywp_server_id' => '99']);
        $hiddenSite = Site::factory()->turnkeywp()->create(['domain' => 'example.org', 'server_id' => $hidden->id, 'turnkeywp_site_id' => '999']);
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp')
            ->assertSuccessful()
            ->expectsOutputToContain('Not listed by TurnkeyWP (deleted there, or not visible to this token): hidden-box');

        expect($hiddenSite->refresh()->turnkeywp_site_id)->toBe('999')
            ->and($hidden->refresh()->turnkeywp_server_id)->toBe('99');
    });

    it('does not sweep when a listing comes back empty', function () {
        $server = Server::factory()->create(['hostname' => '203.0.113.10', 'ssh_port' => 22, 'turnkeywp_server_id' => '7']);
        $kept = Site::factory()->turnkeywp()->create(['domain' => 'example.org', 'server_id' => $server->id, 'turnkeywp_site_id' => '999']);
        fakeTurnkey([tkServer()], []);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        expect($kept->refresh()->turnkeywp_site_id)->toBe('999');
    });

    it('keeps a moved site on the copy it follows while both copies are listed', function () {
        fakeTurnkey([tkServer(), tkServer(['id' => 8, 'name' => 'tk-web-2', 'ip_address' => '203.0.113.20'])], [
            tkSite(['id' => 41, 'server_id' => 7]),
            tkSite(['id' => 52, 'server_id' => 8]),
        ]);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();
        $this->artisan('clockwork:import-turnkeywp')
            ->assertSuccessful()
            ->expectsOutputToContain('"skipped_duplicate_domain":1');

        $site = Site::withoutGlobalScopes()->where('domain', 'example.com')->sole();
        expect($site->turnkeywp_site_id)->toBe('41')
            ->and($site->server->turnkeywp_server_id)->toBe('7');
    });

    it('moves to the new copy once the old one is no longer deployed', function () {
        fakeTurnkey([tkServer()], [tkSite(['id' => 41])]);
        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        fakeTurnkey([tkServer(), tkServer(['id' => 8, 'name' => 'tk-web-2', 'ip_address' => '203.0.113.20'])], [
            tkSite(['id' => 41, 'server_id' => 7, 'status' => 'deleting']),
            tkSite(['id' => 52, 'server_id' => 8]),
        ]);
        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        $site = Site::withoutGlobalScopes()->where('domain', 'example.com')->sole();
        expect($site->turnkeywp_site_id)->toBe('52')
            ->and($site->server->turnkeywp_server_id)->toBe('8');
    });

    it('does not create a duplicate when SpinupWP tracks the www variant on the same server', function () {
        $server = Server::factory()->create(['hostname' => '203.0.113.10', 'ssh_port' => 22, 'spinupwp_id' => 555]);
        Site::factory()->create(['domain' => 'www.example.com', 'server_id' => $server->id, 'spinupwp_id' => '9001']);
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp')
            ->assertSuccessful()
            ->expectsOutputToContain('still tracked by');

        expect(Site::withoutGlobalScopes()->count())->toBe(1);
    });

    it('refuses --adopt for a site another host than SpinupWP tracks', function () {
        $existing = Site::factory()->create(['domain' => 'example.com', 'spinupwp_id' => null, 'pressable_site_id' => '77']);
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp --adopt=example.com')
            ->assertSuccessful()
            ->expectsOutputToContain('not taking it over');

        expect($existing->refresh()->turnkeywp_site_id)->toBeNull()
            ->and($existing->pressable_site_id)->toBe('77');
    });

    it('clears the previous host\'s update flags when adopting', function () {
        $existing = Site::factory()->create([
            'domain' => 'example.com',
            'spinupwp_id' => null,
            'wp_core_update' => true,
            'wp_plugin_updates' => true,
        ]);
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        $existing->refresh();
        expect($existing->turnkeywp_site_id)->toBe('41')
            ->and($existing->wp_core_update)->toBeNull()
            ->and($existing->wp_plugin_updates)->toBeNull();
    });

    it('keeps an operator-corrected wp_path on later runs', function () {
        fakeTurnkey([tkServer()], [tkSite()]);
        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        $site = Site::withoutGlobalScopes()->where('turnkeywp_site_id', '41')->sole();
        $site->update(['wp_path' => '/sites/example.com/files/wp']);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        expect($site->refresh()->wp_path)->toBe('/sites/example.com/files/wp');
    });
});

describe('passwordless sudo', function () {
    it('turns on passwordless_sudo for a turnkey server imported before the flag existed', function () {
        $server = Server::factory()->create([
            'hostname' => '203.0.113.10', 'ssh_port' => 22, 'turnkeywp_server_id' => '7',
            'ssh_user' => 'turnkey', 'passwordless_sudo' => false,
        ]);
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        expect($server->refresh()->passwordless_sudo)->toBeTrue();
    });

    it('leaves the flag off for a server SpinupWP still tracks', function () {
        $server = Server::factory()->create([
            'hostname' => '203.0.113.10', 'ssh_port' => 22, 'spinupwp_id' => 555, 'ssh_user' => 'clockwork-deploy',
        ]);
        fakeTurnkey([tkServer()], []);

        $this->artisan('clockwork:import-turnkeywp')->assertSuccessful();

        expect($server->refresh()->passwordless_sudo)->toBeFalse();
    });

    it('grants sudo access for a stored password, root, or the passwordless flag', function () {
        expect(Server::factory()->make(['ssh_user' => 'clockwork-deploy', 'ssh_password' => null])->hasSudoAccess())->toBeFalse()
            ->and(Server::factory()->make(['ssh_user' => 'clockwork-deploy', 'ssh_password' => 'secret'])->hasSudoAccess())->toBeTrue()
            ->and(Server::factory()->make(['ssh_user' => 'root', 'ssh_password' => null])->hasSudoAccess())->toBeTrue()
            ->and(Server::factory()->make(['ssh_user' => 'turnkey', 'ssh_password' => null, 'passwordless_sudo' => true])->hasSudoAccess())->toBeTrue();
    });

    it('lets the core checksum scan run on a passwordless-sudo server instead of skipping it', function () {
        $server = Server::factory()->create(['ssh_user' => 'turnkey', 'ssh_password' => null, 'passwordless_sudo' => true]);
        $site = Site::factory()->turnkeywp()->create(['server_id' => $server->id, 'site_user' => 'example']);

        $ssh = \Mockery::mock(SshClient::class);
        $ssh->shouldReceive('exec')->once()->andReturn("Success: WordPress installation verifies against checksums.\n__CLOCKWORK_WP_EXIT__:0");
        app()->instance(SshClient::class, $ssh);

        $result = app(WpCoreChecksumVerifier::class)->verify($site);

        expect($result->error)->not->toBe('missing_credentials');
    });
});

describe('clockwork:import-spinupwp with TurnkeyWP sites', function () {
    it('does not take back a site that moved to TurnkeyWP', function () {
        config(['clockwork.spinupwp.token' => 'test-sp-token', 'clockwork.spinupwp.base_url' => 'https://api.spinupwp.example/v1']);
        $server = Server::factory()->create(['spinupwp_id' => 555, 'hostname' => '203.0.113.10']);
        $site = Site::factory()->turnkeywp()->create(['domain' => 'example.com', 'server_id' => $server->id, 'turnkeywp_site_id' => '41']);

        Http::fake([
            'api.spinupwp.example/v1/servers*' => Http::response(['data' => [['id' => 555, 'name' => 'sp-1', 'ip_address' => '203.0.113.10', 'provider_name' => 'Custom']], 'pagination' => ['next' => null]]),
            'api.spinupwp.example/v1/sites*' => Http::response(['data' => [['id' => 9001, 'server_id' => 555, 'domain' => 'example.com', 'is_wordpress' => true, 'site_user' => 'example']], 'pagination' => ['next' => null]]),
            '*' => Http::response([], 404),
        ]);

        $this->artisan('clockwork:import-spinupwp')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipping example.com: site is tracked under TurnkeyWP');

        $site->refresh();
        expect($site->hosting_provider)->toBe(Site::HOSTING_PROVIDER_TURNKEYWP)
            ->and($site->spinupwp_id)->toBeNull()
            ->and($site->turnkeywp_site_id)->toBe('41');
    });
});

describe('TurnkeyWpHostingProvider', function () {
    it('resolves through the registry with SSH, server linkage and companion', function () {
        $host = Site::factory()->turnkeywp()->create()->host();

        expect($host)->toBeInstanceOf(TurnkeyWpHostingProvider::class)
            ->and($host->label())->toBe('TurnkeyWP')
            ->and($host->supports(HostingProvider::CAP_SSH))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeFalse()
            ->and($host->commandRunner())->not->toBeNull();
    });

    it('drops SSH and companion in view-only mode', function () {
        config(['clockwork.turnkeywp.view_only' => true]);
        $host = Site::factory()->turnkeywp()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->commandRunner())->toBeNull()
            ->and($host->companionInstaller())->toBeNull();
    });

    it('links to the site page in the TurnkeyWP app', function () {
        $server = Server::factory()->create(['turnkeywp_server_id' => '7']);
        $site = Site::factory()->turnkeywp()->create(['server_id' => $server->id, 'turnkeywp_site_id' => '41']);

        expect($site->host()->panelUrl($site))->toBe('https://turnkey.example.com/servers/7/sites/41');
    });

    it('uses the /sites/<domain>/files layout for wp_path fallback', function () {
        $site = Site::factory()->turnkeywp()->create(['domain' => 'example.net', 'wp_path' => null]);

        expect($site->resolveWpPath())->toBe('/sites/example.net/files');
    });
});

describe('TurnkeyWpCheck', function () {
    it('skips when no token is set', function () {
        config(['clockwork.turnkeywp.token' => '']);

        expect(app(TurnkeyWpCheck::class)->run()->status)->toBe(CheckResult::STATUS_SKIPPED);
    });

    it('passes when GET /user succeeds', function () {
        Http::fake(['turnkey.example.com/api/v1/user' => Http::response(['id' => 1, 'email' => 'ops@example.com'])]);

        $result = app(TurnkeyWpCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('ops@example.com');
    });

    it('fails on a rejected token', function () {
        Http::fake(['turnkey.example.com/api/v1/user' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        expect(app(TurnkeyWpCheck::class)->run()->status)->toBe(CheckResult::STATUS_FAIL);
    });
});

describe('clockwork:turnkeywp-authorize-ssh', function () {
    beforeEach(function () {
        $this->pubKey = tempnam(sys_get_temp_dir(), 'tkpub');
        file_put_contents($this->pubKey, "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyForTestsOnly clockwork@example.com\n");
    });

    afterEach(function () {
        @unlink($this->pubKey);
    });

    it('refuses in view-only mode', function () {
        config(['clockwork.turnkeywp.view_only' => true]);

        $this->artisan('clockwork:turnkeywp-authorize-ssh', ['--public-key-file' => $this->pubKey, '--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('View-Only');
    });

    it('adds the key to turnkey-user servers and skips one that already has it', function () {
        Server::factory()->create(['name' => 'a', 'hostname' => '203.0.113.11', 'turnkeywp_server_id' => '7', 'ssh_user' => 'turnkey']);
        Server::factory()->create(['name' => 'b', 'hostname' => '203.0.113.12', 'turnkeywp_server_id' => '8', 'ssh_user' => 'turnkey']);
        Server::factory()->create(['name' => 'c', 'hostname' => '203.0.113.13', 'turnkeywp_server_id' => '9', 'ssh_user' => 'clockwork-deploy']);

        Http::fake([
            'turnkey.example.com/api/v1/servers/7/ssh-keys*' => Http::sequence()
                ->push(tkPage([]))
                ->push(['data' => ['id' => 1], 'event_id' => 501], 201),
            'turnkey.example.com/api/v1/servers/8/ssh-keys*' => Http::response(tkPage([
                ['id' => 2, 'server_id' => 8, 'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyForTestsOnly other-comment'],
            ])),
        ]);

        $this->artisan('clockwork:turnkeywp-authorize-ssh', ['--public-key-file' => $this->pubKey, '--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('queued (TurnkeyWP event 501)')
            ->expectsOutputToContain('already registered');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/servers/7/ssh-keys')
            && $r['name'] === 'Clockwork Control');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/servers/8/'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/servers/9/'));
    });
});

describe('TurnkeyWP settings modal and refresh', function () {
    it('serves the settings modal data with credentials, limits and a testable check', function () {
        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('settings.integrations.limits', 'turnkeywp'));

        $response->assertOk()
            ->assertJsonPath('service.name', 'TurnkeyWP')
            ->assertJsonPath('service.official_limits.standard', '60 requests / minute (1 req/sec)')
            ->assertJsonPath('testable', true)
            ->assertJsonPath('hosting_panels.turnkeywp.label', 'TurnkeyWP');

        $envVars = collect($response->json('credentials'))->pluck('env_var')->all();
        expect($envVars)->toContain('TURNKEYWP_TOKEN')
            ->and($envVars)->toContain('TURNKEYWP_BASE_URL');
    });

    it('uses the operator timeout and retry overrides saved from the modal', function () {
        app(Settings::class)->put('services.turnkeywp.timeout', 40);
        app(Settings::class)->put('services.turnkeywp.retry_attempts', 0);

        $client = new TurnkeyWpClient(token: 'test-tk-token', baseUrl: TK_BASE);

        expect($client->getTimeout())->toBe(40);
    });

    it('starts the TurnkeyWP import and fleet poll in the background', function () {
        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (string $key, array $cmds) => $key === 'fleet.import_turnkeywp'
                    && $cmds === ['clockwork:import-turnkeywp', 'clockwork:poll-servers'])
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $this->actingAs(User::factory()->create())
            ->post(route('servers.refreshFromTurnkeyWp'))
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'TurnkeyWP refresh started in the background'));
    });

    it('shows the Refresh from TurnkeyWP button on a TurnkeyWP server page', function () {
        $server = Server::factory()->create(['turnkeywp_server_id' => '7', 'spinupwp_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('Refresh from TurnkeyWP');
    });
});

describe('cloud provider linking after import', function () {
    it('links a new TurnkeyWP server to its droplet so metrics can poll it right away', function () {
        config([
            'clockwork.digitalocean.token' => 'test-do-token',
            'clockwork.digitalocean.base_url' => 'https://api.digitalocean.com/v2',
        ]);
        Http::fake([
            'turnkey.example.com/api/v1/servers*' => Http::response(tkPage([tkServer()])),
            'turnkey.example.com/api/v1/sites*' => Http::response(tkPage([tkSite()])),
            'api.digitalocean.com/v2/droplets*' => Http::response([
                'droplets' => [[
                    'id' => 608188532,
                    'size_slug' => 's-1vcpu-2gb',
                    'vcpus' => 1,
                    'memory' => 2048,
                    'disk' => 50,
                    'networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.10']]],
                ]],
                'links' => [],
                'meta' => ['total' => 1],
            ]),
            '*' => Http::response([], 404),
        ]);

        $this->artisan('clockwork:import-turnkeywp')
            ->assertSuccessful()
            ->expectsOutputToContain('Linked 1 of 1 new server(s) to their cloud provider');

        expect(Server::where('turnkeywp_server_id', '7')->sole()->provider_id)->toBe('608188532');
    });

    it('does not try to link anything on a dry run', function () {
        fakeTurnkey([tkServer()], [tkSite()]);

        $this->artisan('clockwork:import-turnkeywp --dry-run')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('Linked');
    });
});
