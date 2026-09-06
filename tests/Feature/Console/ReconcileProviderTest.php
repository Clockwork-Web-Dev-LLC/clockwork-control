<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\DigitalOceanFixtures;
use Tests\Fixtures\HetznerFixtures;

/*
|--------------------------------------------------------------------------
| Coverage for `clockwork:reconcile-provider` (App\Console\Commands\ReconcileProvider)
|--------------------------------------------------------------------------
|
| Zero real $this->artisan(...) coverage existed before this file — the
| command only appeared as a name string in ScheduleSnapshotTest and a
| comment pointer in CloudProviderRegistryTest. This file is the actual
| behavioral coverage: matching an unlinked server's hostname/IP against
| DigitalOcean/Hetzner by IP, writing provider/provider_id/size fields, and
| the --server / --dry-run options.
*/
describe('clockwork:reconcile-provider', function () {
    it('fails fast when no cloud provider tokens are configured', function () {
        Server::factory()->create(['provider_id' => null]);

        $this->artisan('clockwork:reconcile-provider')
            ->assertFailed()
            ->expectsOutputToContain('No cloud provider tokens configured');
    });

    it('reports nothing to do when there are no unlinked servers', function () {
        config(['clockwork.digitalocean.token' => 'test-do-token']);
        Server::factory()->create(['provider_id' => '12345']);

        $this->artisan('clockwork:reconcile-provider')
            ->assertSuccessful()
            ->expectsOutputToContain('No unlinked servers to reconcile.');
    });

    it('matches an unlinked server to a DigitalOcean droplet by IP and writes provider fields', function () {
        config(['clockwork.digitalocean.token' => 'test-do-token']);

        Http::fake([
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

        $server = Server::factory()->create([
            'hostname' => '203.0.113.20',
            'provider' => 'digitalocean',
            'provider_id' => null,
        ]);

        $this->artisan('clockwork:reconcile-provider')
            ->assertSuccessful()
            ->expectsOutputToContain('matched=1 unmatched=0');

        $server->refresh();
        expect($server->provider)->toBe('digitalocean')
            ->and($server->provider_id)->toBe('555111')
            ->and($server->size_slug)->toBe('s-2vcpu-4gb')
            ->and($server->vcpus)->toBe(2)
            ->and($server->memory_mb)->toBe(4096)
            ->and($server->disk_gb)->toBe(80);
    });

    it('matches by Hetzner precedence over DigitalOcean when both are configured and only Hetzner has the IP', function () {
        config([
            'clockwork.digitalocean.token' => 'test-do-token',
            'clockwork.hetzner.token' => 'test-hetzner-token',
        ]);

        Http::fake([
            'api.digitalocean.com/v2/droplets*' => Http::response(
                DigitalOceanFixtures::dropletsListResponse([]),
                200
            ),
            'api.hetzner.cloud/v1/servers*' => Http::response(
                HetznerFixtures::serversListResponse([
                    HetznerFixtures::server([
                        'id' => 987654,
                        'public_net' => ['ipv4' => ['ip' => '198.51.100.10']],
                    ]),
                ]),
                200
            ),
        ]);

        $server = Server::factory()->create([
            'hostname' => '198.51.100.10',
            'provider' => 'digitalocean', // wrong initial guess — reconcile should correct it
            'provider_id' => null,
        ]);

        $this->artisan('clockwork:reconcile-provider')->assertSuccessful();

        $server->refresh();
        expect($server->provider)->toBe('hetzner')
            ->and($server->provider_id)->toBe('987654');
    });

    it('matches an unlinked server to a Vultr instance by IP and writes provider fields', function () {
        config(['clockwork.vultr.api_key' => 'test-vultr-key']);

        Http::fake([
            'api.vultr.com/v2/instances*' => Http::response([
                'instances' => [
                    [
                        'id' => 'vultr-rec-01',
                        'main_ip' => '45.76.50.60',
                        'plan' => 'vhf-2c-4gb',
                        'vcpus' => 2,
                        'ram' => 4096,
                        'disk' => 80,
                    ],
                ],
                'meta' => ['links' => ['next' => '']],
            ], 200),
        ]);

        $server = Server::factory()->create([
            'hostname' => '45.76.50.60',
            'provider_id' => null,
        ]);

        $this->artisan('clockwork:reconcile-provider')->assertSuccessful();

        $server->refresh();
        expect($server->provider)->toBe('vultr')
            ->and($server->provider_id)->toBe('vultr-rec-01')
            ->and($server->size_slug)->toBe('vhf-2c-4gb')
            ->and($server->vcpus)->toBe(2)
            ->and($server->memory_mb)->toBe(4096)
            ->and($server->disk_gb)->toBe(80);
    });

    it('matches an unlinked server to a Linode instance by IP and writes provider fields', function () {
        config(['clockwork.linode.token' => 'test-linode-token']);

        Http::fake([
            'api.linode.com/v4/linode/instances*' => Http::response([
                'data' => [
                    [
                        'id' => 778899,
                        'type' => 'g6-standard-2',
                        'ipv4' => ['139.162.200.75'],
                        'specs' => [
                            'vcpus' => 2,
                            'memory' => 4096,
                            'disk' => 81920,
                        ],
                    ],
                ],
                'pages' => 1,
            ], 200),
        ]);

        $server = Server::factory()->create([
            'hostname' => '139.162.200.75',
            'provider_id' => null,
        ]);

        $this->artisan('clockwork:reconcile-provider')->assertSuccessful();

        $server->refresh();
        expect($server->provider)->toBe('linode')
            ->and($server->provider_id)->toBe('778899')
            ->and($server->size_slug)->toBe('g6-standard-2')
            ->and($server->vcpus)->toBe(2)
            ->and($server->memory_mb)->toBe(4096)
            ->and($server->disk_gb)->toBe(80);
    });

    it('leaves an unmatched server\'s provider fields untouched and reports it as unmatched', function () {
        config(['clockwork.digitalocean.token' => 'test-do-token']);

        Http::fake([
            'api.digitalocean.com/v2/droplets*' => Http::response(
                DigitalOceanFixtures::dropletsListResponse([]),
                200
            ),
        ]);

        $server = Server::factory()->create([
            'hostname' => '203.0.113.99',
            'provider_id' => null,
        ]);

        $this->artisan('clockwork:reconcile-provider')
            ->assertSuccessful()
            ->expectsOutputToContain('no match in DO, Hetzner, or Azure')
            ->expectsOutputToContain('matched=0 unmatched=1');

        expect($server->fresh()->provider_id)->toBeNull();
    });

    it('--dry-run reports the match without writing it', function () {
        config(['clockwork.digitalocean.token' => 'test-do-token']);

        Http::fake([
            'api.digitalocean.com/v2/droplets*' => Http::response(
                DigitalOceanFixtures::dropletsListResponse([
                    DigitalOceanFixtures::droplet([
                        'id' => 555222,
                        'networks' => ['v4' => [['ip_address' => '203.0.113.21', 'type' => 'public']]],
                    ]),
                ]),
                200
            ),
        ]);

        $server = Server::factory()->create([
            'hostname' => '203.0.113.21',
            'provider_id' => null,
        ]);

        $this->artisan('clockwork:reconcile-provider', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('matched=1 unmatched=0 (dry-run — no writes)');

        expect($server->fresh()->provider_id)->toBeNull();
    });

    it('--server limits reconciliation to the named server only', function () {
        config(['clockwork.digitalocean.token' => 'test-do-token']);

        Http::fake([
            'api.digitalocean.com/v2/droplets*' => Http::response(
                DigitalOceanFixtures::dropletsListResponse([
                    DigitalOceanFixtures::droplet([
                        'id' => 555333,
                        'networks' => ['v4' => [['ip_address' => '203.0.113.22', 'type' => 'public']]],
                    ]),
                    DigitalOceanFixtures::droplet([
                        'id' => 555444,
                        'networks' => ['v4' => [['ip_address' => '203.0.113.23', 'type' => 'public']]],
                    ]),
                ]),
                200
            ),
        ]);

        $target = Server::factory()->create([
            'name' => 'reconcile-me',
            'hostname' => '203.0.113.22',
            'provider_id' => null,
        ]);
        $other = Server::factory()->create([
            'name' => 'leave-me-alone',
            'hostname' => '203.0.113.23',
            'provider_id' => null,
        ]);

        $this->artisan('clockwork:reconcile-provider', ['--server' => 'reconcile-me'])
            ->assertSuccessful()
            ->expectsOutputToContain('matched=1 unmatched=0');

        expect($target->fresh()->provider_id)->toBe('555333');
        expect($other->fresh()->provider_id)->toBeNull();
    });

    it('never dispatches a request for a server already linked (provider_id not null)', function () {
        config(['clockwork.digitalocean.token' => 'test-do-token']);

        Http::fake([
            'api.digitalocean.com/v2/droplets*' => Http::response(
                DigitalOceanFixtures::dropletsListResponse([]),
                200
            ),
        ]);

        Server::factory()->create(['provider_id' => 'already-linked']);

        $this->artisan('clockwork:reconcile-provider')
            ->assertSuccessful()
            ->expectsOutputToContain('No unlinked servers to reconcile.');

        Http::assertNothingSent();
    });
});
