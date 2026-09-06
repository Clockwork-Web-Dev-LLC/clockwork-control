<?php

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Modules\Linode\LinodeCheck;
use Modules\Linode\LinodeClient;
use Modules\Linode\LinodeCloudProvider;

describe('LinodeCloudProvider', function () {
    beforeEach(function () {
        $this->provider = app(LinodeCloudProvider::class);
    });

    it('reports its real id/label/instanceNoun/iconClass/iconColor', function () {
        expect($this->provider->id())->toBe(Server::PROVIDER_LINODE);
        expect($this->provider->label())->toBe('Linode instance');
        expect($this->provider->instanceNoun())->toBe('linode');
        expect($this->provider->iconClass())->toBe('fa-brands fa-linode');
        expect($this->provider->iconColor())->toBe('#02B159');
    });

    it('maps known Linode plan slugs to size tiers', function () {
        expect($this->provider->sizeTier('g6-standard-1'))->toBe('Shared CPU');
        expect($this->provider->sizeTier('g6-dedicated-2'))->toBe('Dedicated CPU');
        expect($this->provider->sizeTier('g6-nanode-1'))->toBe('Nanode');
        expect($this->provider->sizeTier('g6-highmem-1'))->toBe('High Memory');
        expect($this->provider->sizeTier('g6-gpu-1'))->toBe('Dedicated GPU');
        expect($this->provider->sizeTier('custom-slug'))->toBe('custom-slug');
        expect($this->provider->sizeTier(null))->toBeNull();
    });

    it('is not configured when no token is set', function () {
        config(['clockwork.linode.token' => null]);
        $client = new LinodeClient;
        expect($client->isConfigured())->toBeFalse();
    });

    it('is configured when token is set', function () {
        config(['clockwork.linode.token' => 'linode-secret-test-token']);
        $client = new LinodeClient;
        expect($client->isConfigured())->toBeTrue();
    });

    it('returns empty instances-by-ip when unconfigured', function () {
        config(['clockwork.linode.token' => null]);
        $provider = app(LinodeCloudProvider::class);

        expect($provider->instancesByIp())->toBe([]);
    });

    it('maps instances by public IPv4 address and computes specs', function () {
        config(['clockwork.linode.token' => 'test-token']);
        $provider = app(LinodeCloudProvider::class);

        Http::fake([
            'api.linode.com/v4/linode/instances*' => Http::response([
                'data' => [
                    [
                        'id' => 1234567,
                        'label' => 'app-server-01',
                        'type' => 'g6-standard-2',
                        'ipv4' => ['139.162.100.50', '192.168.1.5'],
                        'specs' => [
                            'vcpus' => 2,
                            'memory' => 4096,
                            'disk' => 81920,
                        ],
                    ],
                ],
                'page' => 1,
                'pages' => 1,
                'results' => 1,
            ]),
        ]);

        $byIp = $provider->instancesByIp();

        expect($byIp)->toHaveKey('139.162.100.50');
        expect($byIp['139.162.100.50'])->toBe([
            'id' => '1234567',
            'size_slug' => 'g6-standard-2',
            'vcpus' => 2,
            'memory_mb' => 4096,
            'disk_gb' => 80,
        ]);
    });

    it('fetches CPU metrics from instance stats', function () {
        config(['clockwork.linode.token' => 'test-token']);
        $provider = app(LinodeCloudProvider::class);

        Http::fake([
            'api.linode.com/v4/linode/instances/1234567/stats' => Http::response([
                'data' => [
                    'cpu' => [
                        [1700000000, 20.0],
                        [1700000300, 44.0],
                    ],
                ],
            ]),
        ]);

        $server = Server::factory()->linode()->make([
            'provider_id' => '1234567',
            'vcpus' => 2,
        ]);

        $metrics = $provider->metrics($server, time() - 300, time());

        // 44% total across 2 vCPUs normalized = 22.0%
        expect($metrics['cpu_pct'])->toBe(22.0);
        expect($metrics['memory_pct'])->toBeNull();
        expect($metrics['disk_pct'])->toBeNull();
        expect($metrics['load_1'])->toBeNull();
    });

    it('correctly determines isDeletedAtProvider based on alive IDs', function () {
        $server = Server::factory()->linode()->make(['provider_id' => '1234567']);

        expect($this->provider->isDeletedAtProvider($server, ['1234567', '7654321']))->toBeFalse();
        expect($this->provider->isDeletedAtProvider($server, ['7654321']))->toBeTrue();
        expect($this->provider->isDeletedAtProvider($server, null))->toBeFalse();
    });
});

describe('LinodeCheck', function () {
    it('skips when no token is configured', function () {
        config(['clockwork.linode.token' => null]);
        $check = app(LinodeCheck::class);

        $result = $check->run();

        expect($result->status)->toBe('skipped');
    });

    it('passes when GET /v4/account returns 200', function () {
        config(['clockwork.linode.token' => 'valid-test-token']);
        $check = app(LinodeCheck::class);

        Http::fake([
            'api.linode.com/v4/account' => Http::response([
                'email' => 'admin@clockworkwd.com',
                'company' => 'Clockwork Agency',
                'status' => 'active',
            ]),
        ]);

        $result = $check->run();

        expect($result->status)->toBe('ok');
        expect($result->summary)->toContain('admin@clockworkwd.com');
    });

    it('fails when GET /v4/account returns an error', function () {
        config(['clockwork.linode.token' => 'bad-token']);
        $check = app(LinodeCheck::class);

        Http::fake([
            'api.linode.com/v4/account' => Http::response(['errors' => [['reason' => 'Invalid Token']]], 401),
        ]);

        $result = $check->run();

        expect($result->status)->toBe('fail');
    });
});
