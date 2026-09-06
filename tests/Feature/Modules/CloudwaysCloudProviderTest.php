<?php

use App\Models\Server;
use App\Services\CloudProvider\CloudProviderRegistry;
use Illuminate\Support\Facades\Http;
use Modules\Cloudways\CloudwaysCloudProvider;

/**
 * Cloudways-specific CloudProvider coverage, mirroring
 * CloudProviderRegistryTest's per-provider describe() blocks. Cloudways is
 * the only provider whose metrics() call fans out across 4 independent,
 * individually-best-effort HTTP calls (cpu/ram/load/disk) rather than one —
 * see CloudwaysCloudProvider::metrics()'s try/catch-per-metric shape — so
 * the partial-failure case gets its own dedicated test here.
 */
describe('CloudwaysCloudProvider', function () {
    beforeEach(function () {
        config([
            'clockwork.cloudways.api_key' => 'cw-key',
            'clockwork.cloudways.email' => 'agency@clockworkwd.com',
        ]);
        $this->provider = app(CloudwaysCloudProvider::class);

        Http::fake([
            'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                'access_token' => 'fake-cloudways-token',
                'expires_in' => 3600,
            ], 200),
        ]);
    });

    it('resolves "cloudways" via CloudProviderRegistry to the real adapter', function () {
        $resolved = app(CloudProviderRegistry::class)->resolve('cloudways');

        expect($resolved)->toBeInstanceOf(CloudwaysCloudProvider::class);
    });

    it('reports its real id/label/instanceNoun/iconClass/iconColor', function () {
        expect($this->provider->id())->toBe(Server::PROVIDER_CLOUDWAYS);
        expect($this->provider->label())->toBe('Cloudways server');
        expect($this->provider->instanceNoun())->toBe('server');
        expect($this->provider->iconClass())->toBe('fa-solid fa-server');
        expect($this->provider->iconColor())->toBe('#E44C4C');
    });

    it('returns the raw slug unchanged from sizeTier() — no Cloudways-specific tier map exists', function () {
        expect($this->provider->sizeTier('do-4gb'))->toBe('do-4gb');
        expect($this->provider->sizeTier(null))->toBeNull();
    });

    it('checks deletion against a real alive-ID list', function () {
        $server = Server::factory()->cloudways()->create(['provider_id' => '42']);

        expect($this->provider->isDeletedAtProvider($server, ['42', '99']))->toBeFalse();
        expect($this->provider->isDeletedAtProvider($server, ['99']))->toBeTrue();
        expect($this->provider->isDeletedAtProvider($server, null))->toBeFalse();
    });

    it('fetches alive provider ids from GET /server', function () {
        Http::fake([
            'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                'access_token' => 'fake-cloudways-token',
                'expires_in' => 3600,
            ], 200),
            'api.cloudways.com/api/v2/server' => Http::response([
                'servers' => [['id' => 1], ['id' => 2]],
            ], 200),
        ]);

        expect($this->provider->aliveProviderIds())->toBe(['1', '2']);
    });

    it('returns null alive ids (not an exception) when the servers call fails', function () {
        Http::fake([
            'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                'access_token' => 'fake-cloudways-token',
                'expires_in' => 3600,
            ], 200),
            'api.cloudways.com/api/v2/server' => Http::response(['message' => 'error'], 500),
        ]);

        expect($this->provider->aliveProviderIds())->toBeNull();
    });

    it('fetches cpu/ram/load/disk metrics from the assumed Cloudways monitor payload shapes', function () {
        $server = Server::factory()->cloudways()->create(['provider_id' => '42']);

        Http::fake([
            'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                'access_token' => 'fake-cloudways-token',
                'expires_in' => 3600,
            ], 200),
            'api.cloudways.com/api/v2/server/monitorSummary*type=cpu*' => Http::response([
                'data' => [['timestamp' => time() - 60, 'value' => 12.5], ['timestamp' => time(), 'value' => 33.0]],
            ], 200),
            'api.cloudways.com/api/v2/server/monitorSummary*type=ram*' => Http::response([
                'data' => [['timestamp' => time(), 'value' => 61.0]],
            ], 200),
            'api.cloudways.com/api/v2/server/monitorSummary*type=load*' => Http::response([
                'data' => [['timestamp' => time(), 'value' => 1.75]],
            ], 200),
            'api.cloudways.com/api/v2/server/diskUsage*' => Http::response([
                'used_mb' => 5000,
                'total_mb' => 20000,
            ], 200),
        ]);

        $metrics = $this->provider->metrics($server, time() - 300, time());

        expect($metrics['cpu_pct'])->toBe(33.0)
            ->and($metrics['memory_pct'])->toBe(61.0)
            ->and($metrics['load_1'])->toBe(1.75)
            ->and($metrics['disk_pct'])->toBe(25.0);
    });

    it('returns null for individual metrics whose call fails, without failing the whole batch', function () {
        $server = Server::factory()->cloudways()->create(['provider_id' => '42']);

        Http::fake([
            'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                'access_token' => 'fake-cloudways-token',
                'expires_in' => 3600,
            ], 200),
            'api.cloudways.com/api/v2/server/monitorSummary*type=cpu*' => Http::response([
                'data' => [['timestamp' => time(), 'value' => 40.0]],
            ], 200),
            'api.cloudways.com/api/v2/server/monitorSummary*type=ram*' => Http::response(['message' => 'error'], 500),
            'api.cloudways.com/api/v2/server/monitorSummary*type=load*' => Http::response(['message' => 'error'], 500),
            'api.cloudways.com/api/v2/server/diskUsage*' => Http::response(['message' => 'error'], 500),
        ]);

        $metrics = $this->provider->metrics($server, time() - 300, time());

        expect($metrics['cpu_pct'])->toBe(40.0)
            ->and($metrics['memory_pct'])->toBeNull()
            ->and($metrics['load_1'])->toBeNull()
            ->and($metrics['disk_pct'])->toBeNull();
    });

    it('keys instancesByIp() by public_ip with the assumed server-summary fields', function () {
        Http::fake([
            'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                'access_token' => 'fake-cloudways-token',
                'expires_in' => 3600,
            ], 200),
            'api.cloudways.com/api/v2/server' => Http::response([
                'servers' => [
                    ['id' => 1, 'public_ip' => '203.0.113.10', 'size' => 'do-4gb', 'vcpus' => 2, 'memory_mb' => 4096, 'disk_gb' => 80],
                    ['id' => 2, 'public_ip' => null, 'size' => 'do-2gb'],
                ],
            ], 200),
        ]);

        $byIp = $this->provider->instancesByIp();

        expect($byIp)->toHaveKey('203.0.113.10')
            ->and($byIp)->toHaveCount(1)
            ->and($byIp['203.0.113.10']['size_slug'])->toBe('do-4gb')
            ->and($byIp['203.0.113.10']['vcpus'])->toBe(2);
    });
});
