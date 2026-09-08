<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\DigitalOceanFixtures;
use Tests\Fixtures\SpinupWpFixtures;

/*
|--------------------------------------------------------------------------
| Coverage for `clockwork:import-spinupwp` (App\Console\Commands\ImportSpinupWp)
|--------------------------------------------------------------------------
|
| SpinupWpClient::servers()/sites() return the raw `data` array from the API
| unmodified (see SpinupWpClient::paginate()) — no transform layer. The base
| SpinupWpFixtures::server()/site() payloads were built for the
| clockwork:spinupwp-test connectivity check (see SpinupWpTestTest), which
| only counts rows — they do NOT carry every key ImportSpinupWp actually
| reads (it wants `provider_name` not `provider`, `domain` not
| `site_domain`, `https.*` not `ssl.*`). Every row below layers the real
| keys on top of the base fixture via overrides instead of hand-rolling a
| new payload shape.
|
| DigitalOcean/Hetzner tokens are unset by default in this suite's config
| (see .env.testing) — cross-reference is exercised explicitly where it
| matters and left off (client "not configured", provider_id stays null)
| everywhere else.
*/
function spinupwpConfig(): void
{
    config(['clockwork.spinupwp.token' => 'swp-token']);
}

describe('clockwork:import-spinupwp', function () {
    it('fails fast with no writes when the SpinupWP token is not configured', function () {
        config(['clockwork.spinupwp.token' => '']);

        $this->artisan('clockwork:import-spinupwp')
            ->assertFailed()
            ->expectsOutputToContain('CLOCKWORK_SPINUPWP_TOKEN is not set');

        expect(Server::count())->toBe(0);
        expect(Site::count())->toBe(0);
    });

    it('creates a server and a WordPress site from a fresh listing', function () {
        spinupwpConfig();

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::server([
                        'id' => 12345,
                        'name' => 'web42',
                        'ip_address' => '203.0.113.10',
                        'provider_name' => 'DigitalOcean',
                    ]),
                ]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::site([
                        'id' => 67890,
                        'server_id' => 12345,
                        'domain' => 'example.com',
                        'is_wordpress' => true,
                        'site_user' => 'example',
                        'database' => ['table_prefix' => 'wp_'],
                        'https' => [
                            'enabled' => true,
                            'certificate_expires' => now()->addDays(60)->toIso8601String(),
                            'certificate_renews' => now()->addDays(30)->toIso8601String(),
                        ],
                        'wp_core_update' => false,
                        'wp_theme_updates' => true,
                        'wp_plugin_updates' => false,
                    ]),
                ]),
                200
            ),
        ]);

        $this->artisan('clockwork:import-spinupwp')->assertSuccessful();

        expect(Server::count())->toBe(1);
        $server = Server::firstOrFail();
        expect($server->spinupwp_id)->toBe('12345')
            ->and($server->name)->toBe('web42')
            ->and($server->hostname)->toBe('203.0.113.10')
            ->and($server->provider)->toBe('digitalocean')
            // DO token unconfigured in this test => no cross-reference.
            ->and($server->provider_id)->toBeNull();

        expect(Site::count())->toBe(1);
        $site = Site::firstOrFail();
        expect($site->domain)->toBe('example.com')
            ->and($site->hosting_provider)->toBe(Site::HOSTING_PROVIDER_SPINUPWP)
            ->and($site->spinupwp_id)->toBe('67890')
            ->and($site->server_id)->toBe($server->id)
            ->and($site->is_wordpress)->toBeTrue()
            ->and($site->site_user)->toBe('example')
            ->and($site->table_prefix)->toBe('wp_')
            ->and($site->cert_source)->toBe(Site::CERT_SOURCE_SPINUPWP_LE)
            ->and($site->wp_theme_updates)->toBeTrue()
            ->and($site->wp_core_update)->toBeFalse();
    });

    it('is idempotent: running the import twice does not duplicate rows and marks the second run unchanged', function () {
        spinupwpConfig();

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::server(['id' => 111, 'ip_address' => '203.0.113.11', 'provider_name' => 'DigitalOcean']),
                ]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::site(['id' => 222, 'server_id' => 111, 'domain' => 'repeat-example.com', 'is_wordpress' => true]),
                ]),
                200
            ),
        ]);

        $this->artisan('clockwork:import-spinupwp')->assertSuccessful();
        expect(Server::count())->toBe(1);
        expect(Site::count())->toBe(1);

        $this->artisan('clockwork:import-spinupwp')
            ->assertSuccessful()
            ->expectsOutputToContain('"unchanged":1'); // appears in both the Servers and Sites json_encode lines

        expect(Server::count())->toBe(1);
        expect(Site::count())->toBe(1);
    });

    it('cross-references a DigitalOcean droplet by IP and stores size/provider_id', function () {
        spinupwpConfig();
        config(['clockwork.digitalocean.token' => 'test-do-token']);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::server([
                        'id' => 555,
                        'ip_address' => '203.0.113.20',
                        'provider_name' => 'DigitalOcean',
                    ]),
                ]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(SpinupWpFixtures::listResponse([]), 200),
            'api.digitalocean.com/v2/droplets*' => Http::response(
                DigitalOceanFixtures::dropletsListResponse([
                    DigitalOceanFixtures::droplet([
                        'id' => 555111,
                        'size_slug' => 's-2vcpu-4gb',
                        'vcpus' => 2,
                        'memory' => 4096,
                        'disk' => 80,
                        'networks' => ['v4' => [['ip_address' => '203.0.113.20', 'type' => 'public']]],
                    ]),
                ]),
                200
            ),
        ]);

        $this->artisan('clockwork:import-spinupwp')->assertSuccessful();

        $server = Server::firstOrFail();
        expect($server->provider_id)->toBe('555111')
            ->and($server->size_slug)->toBe('s-2vcpu-4gb')
            ->and($server->vcpus)->toBe(2)
            ->and($server->memory_mb)->toBe(4096)
            ->and($server->disk_gb)->toBe(80);
    });

    it('skips a site whose server_id is not among our known SpinupWP servers', function () {
        spinupwpConfig();

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(SpinupWpFixtures::listResponse([]), 200),
            'api.spinupwp.app/v1/sites*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::site(['id' => 999, 'server_id' => 424242, 'domain' => 'orphaned.com']),
                ]),
                200
            ),
        ]);

        $this->artisan('clockwork:import-spinupwp')
            ->assertSuccessful()
            ->expectsOutputToContain('references SpinupWP server 424242 not in our DB')
            ->expectsOutputToContain('"skipped_no_server":1');

        expect(Site::count())->toBe(0);
    });

    it('nulls spinupwp_id (sweep) for a local server and site no longer present in the API response', function () {
        spinupwpConfig();

        $goneServer = Server::factory()->create(['spinupwp_id' => '70001']);
        $goneSite = Site::factory()->spinupwp()->create([
            'server_id' => $goneServer->id,
            'spinupwp_id' => '70002',
            'domain' => 'deleted-from-spinupwp.com',
        ]);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::server(['id' => 999, 'ip_address' => '203.0.113.99', 'provider_name' => 'DigitalOcean']),
                ]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(SpinupWpFixtures::listResponse([]), 200),
        ]);

        $this->artisan('clockwork:import-spinupwp')
            ->assertSuccessful()
            ->expectsOutputToContain('"spinupwp_id_nulled":1');

        expect($goneServer->fresh()->spinupwp_id)->toBeNull();
        expect($goneSite->fresh()->spinupwp_id)->toBeNull();
    });

    it('adopts a manually-added server row (no spinupwp_id) matching hostname+ssh_port instead of colliding on insert', function () {
        spinupwpConfig();

        $manual = Server::factory()->create([
            'name' => 'manually-added',
            'hostname' => '203.0.113.30',
            'ssh_port' => 22,
            'spinupwp_id' => null,
        ]);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::server([
                        'id' => 8080,
                        'name' => 'web-adopted',
                        'ip_address' => '203.0.113.30',
                        'ssh_port' => 22,
                        'provider_name' => 'DigitalOcean',
                    ]),
                ]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(SpinupWpFixtures::listResponse([]), 200),
        ]);

        $this->artisan('clockwork:import-spinupwp')->assertSuccessful();

        expect(Server::count())->toBe(1);
        $manual->refresh();
        expect($manual->spinupwp_id)->toBe('8080')
            ->and($manual->name)->toBe('web-adopted');
    });

    it('does not overwrite reboot_required when the server was recently rebooted (guard against SpinupWP\'s stale flag)', function () {
        spinupwpConfig();

        $server = Server::factory()->create([
            'spinupwp_id' => '321',
            'hostname' => '203.0.113.40',
            'reboot_required' => false,
            'scheduled_reboot_at' => now()->subHours(2), // past, within 24h => "recently rebooted" guard applies
        ]);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::server([
                        'id' => 321,
                        'ip_address' => '203.0.113.40',
                        'provider_name' => 'DigitalOcean',
                        'reboot_required' => true, // SpinupWP still says yes — stale
                    ]),
                ]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(SpinupWpFixtures::listResponse([]), 200),
        ]);

        $this->artisan('clockwork:import-spinupwp')->assertSuccessful();

        expect($server->fresh()->reboot_required)->toBeFalse();
    });

    it('disables uptime monitoring for a new staging-pattern domain but not for a normal one', function () {
        spinupwpConfig();

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::server(['id' => 1, 'ip_address' => '203.0.113.50', 'provider_name' => 'DigitalOcean']),
                ]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::site(['id' => 2, 'server_id' => 1, 'domain' => 'staging.client-a.com']),
                    SpinupWpFixtures::site(['id' => 3, 'server_id' => 1, 'domain' => 'client-b.com']),
                ]),
                200
            ),
        ]);

        $this->artisan('clockwork:import-spinupwp')->assertSuccessful();

        expect(Site::where('domain', 'staging.client-a.com')->firstOrFail()->uptime_monitoring_enabled)->toBeFalse();
        expect(Site::where('domain', 'client-b.com')->firstOrFail()->uptime_monitoring_enabled)->toBeTrue();
    });

    it('runs in dry-run mode without committing changes to the database', function () {
        spinupwpConfig();

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::server([
                        'id' => 12345,
                        'name' => 'web42',
                        'ip_address' => '203.0.113.10',
                        'provider_name' => 'DigitalOcean',
                    ]),
                ]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::site([
                        'id' => 999,
                        'server_id' => 12345,
                        'domain' => 'client-x.com',
                    ]),
                ]),
                200
            ),
        ]);

        $this->artisan('clockwork:import-spinupwp --dry-run')
            ->expectsOutputToContain('[DRY RUN] Servers:')
            ->expectsOutputToContain('[DRY RUN] Sites:')
            ->assertSuccessful();

        expect(Server::count())->toBe(0);
        expect(Site::count())->toBe(0);
    });
});
