<?php

use App\Models\Server;
use App\Services\CloudProvider\CloudProviderRegistry;
use Illuminate\Support\Facades\Http;
use Modules\Azure\AzureCloudProvider;
use Modules\Core\NullCloudProvider;
use Modules\DigitalOcean\DigitalOceanCloudProvider;
use Modules\Hetzner\HetznerCloudProvider;
use Modules\Linode\LinodeCloudProvider;
use Modules\Vultr\VultrCloudProvider;
use Tests\Fixtures\AzureFixtures;
use Tests\Fixtures\HetznerFixtures;

/**
 * CloudProviderRegistry::resolve() dispatch + the id()/label()/sizeTier()/
 * isDeletedAtProvider() contracts of the 3 real registered adapters plus
 * NullCloudProvider. See ProviderDispatchTest for the higher-level
 * PollServers/ReconcileProvider characterization tests this complements.
 */
describe('CloudProviderRegistry::resolve()', function () {
    beforeEach(function () {
        $this->registry = app(CloudProviderRegistry::class);
    });

    it('resolves "digitalocean" to the real DigitalOceanCloudProvider adapter', function () {
        expect($this->registry->resolve('digitalocean'))->toBeInstanceOf(DigitalOceanCloudProvider::class);
    });

    it('resolves "hetzner" to the real HetznerCloudProvider adapter', function () {
        expect($this->registry->resolve('hetzner'))->toBeInstanceOf(HetznerCloudProvider::class);
    });

    it('resolves "azure" to the real AzureCloudProvider adapter', function () {
        expect($this->registry->resolve('azure'))->toBeInstanceOf(AzureCloudProvider::class);
    });

    it('resolves "vultr" to the real VultrCloudProvider adapter', function () {
        expect($this->registry->resolve('vultr'))->toBeInstanceOf(VultrCloudProvider::class);
    });

    it('resolves "linode" to the real LinodeCloudProvider adapter', function () {
        expect($this->registry->resolve('linode'))->toBeInstanceOf(LinodeCloudProvider::class);
    });

    /**
     * Phase 4 behavior (see CloudProviderRegistry's class docblock): an
     * unrecognized provider string no longer silently falls through to
     * DigitalOcean. It resolves to the NullCloudProvider null object
     * instead. Confirmed by reading the current resolve() implementation
     * directly — do not assume the old default still holds.
     */
    it('resolves an unrecognized provider string to NullCloudProvider, not DigitalOcean', function () {
        $provider = $this->registry->resolve('some-unrecognized-string');

        expect($provider)->toBeInstanceOf(NullCloudProvider::class)
            ->and($provider)->not->toBeInstanceOf(DigitalOceanCloudProvider::class);
    });

    it('resolves a null provider string to NullCloudProvider', function () {
        expect($this->registry->resolve(null))->toBeInstanceOf(NullCloudProvider::class);
    });
});

describe('DigitalOceanCloudProvider', function () {
    beforeEach(function () {
        $this->provider = app(DigitalOceanCloudProvider::class);
    });

    it('reports its real id/label/instanceNoun/iconClass', function () {
        expect($this->provider->id())->toBe('digitalocean');
        expect($this->provider->label())->toBe('DigitalOcean droplet');
        expect($this->provider->instanceNoun())->toBe('droplet');
        expect($this->provider->iconClass())->toBe('fa-brands fa-digital-ocean');
    });

    it('maps real DO size slugs through its tier map', function () {
        expect($this->provider->sizeTier('s-2vcpu-4gb'))->toBe('Basic');
        expect($this->provider->sizeTier('m-2vcpu-16gb'))->toBe('Memory-Optimized');
        expect($this->provider->sizeTier('c-4'))->toBe('CPU-Optimized');
        expect($this->provider->sizeTier(null))->toBeNull();
        // Unrecognized prefix falls back to the raw slug.
        expect($this->provider->sizeTier('weird-slug'))->toBe('weird-slug');
    });

    it('checks deletion against a real alive-ID list', function () {
        $server = Server::factory()->digitalOcean()->create(['provider_id' => '555111']);

        expect($this->provider->isDeletedAtProvider($server, ['555111', '999']))->toBeFalse();
        expect($this->provider->isDeletedAtProvider($server, ['999']))->toBeTrue();
        // No alive-ID list at all (fetch failed/unconfigured) => unknown, not deleted.
        expect($this->provider->isDeletedAtProvider($server, null))->toBeFalse();
    });

    it('fetches metrics from the real DO API shape via Http::fake', function () {
        // Config must be set before the provider (and the client it wraps)
        // is resolved from the container — the client reads config in its
        // own constructor, so the beforeEach-built $this->provider would
        // otherwise have already baked in an empty token.
        config(['clockwork.digitalocean.token' => 'test-do-token']);
        $provider = app(DigitalOceanCloudProvider::class);

        // percentCpuUsed() needs DO's real cumulative-jiffy-counter shape —
        // per-mode series with >= 2 points each, idle delta vs total delta —
        // not a single scalar sample, so this is built by hand rather than
        // reused from DigitalOceanFixtures::metricsResponse() (which fits
        // the single-value load_1/memory/disk endpoints instead).
        $t1 = time() - 60;
        $t2 = time();
        $cpuPayload = [
            'data' => [
                'result' => [
                    ['metric' => ['mode' => 'idle'], 'values' => [[$t1, '1000'], [$t2, '1900']]],
                    ['metric' => ['mode' => 'user'], 'values' => [[$t1, '0'], [$t2, '100']]],
                ],
            ],
        ];

        Http::fake([
            'api.digitalocean.com/v2/monitoring/metrics/droplet/cpu*' => Http::response($cpuPayload),
            'api.digitalocean.com/*' => Http::response(['data' => ['result' => []]]),
        ]);

        $server = Server::factory()->digitalOcean()->create(['provider_id' => '555111']);

        $metrics = $provider->metrics($server, time() - 300, time());

        // idle delta 900, total delta 1000 => (1 - 900/1000) * 100 = 10.
        expect(round($metrics['cpu_pct'], 6))->toBe(10.0);
    });
});

describe('HetznerCloudProvider', function () {
    beforeEach(function () {
        $this->provider = app(HetznerCloudProvider::class);
    });

    it('reports its real id/label/instanceNoun/iconClass', function () {
        expect($this->provider->id())->toBe('hetzner');
        expect($this->provider->label())->toBe('Hetzner server');
        expect($this->provider->instanceNoun())->toBe('server');
        expect($this->provider->iconClass())->toBe('fa-solid fa-server');
    });

    it('maps real Hetzner size slugs through its tier map', function () {
        expect($this->provider->sizeTier('cx21'))->toBe('Shared AMD');
        expect($this->provider->sizeTier('ccx13'))->toBe('Dedicated vCPU');
        expect($this->provider->sizeTier('cpx31'))->toBe('Shared AMD (EPYC)');
        expect($this->provider->sizeTier('cax11'))->toBe('Shared ARM');
        expect($this->provider->sizeTier(null))->toBeNull();
    });

    it('checks deletion against a real alive-ID list', function () {
        $server = Server::factory()->hetzner()->create(['provider_id' => '987654']);

        expect($this->provider->isDeletedAtProvider($server, ['987654']))->toBeFalse();
        expect($this->provider->isDeletedAtProvider($server, ['111']))->toBeTrue();
        expect($this->provider->isDeletedAtProvider($server, null))->toBeFalse();
    });

    it('fetches metrics from the real Hetzner API shape via Http::fake', function () {
        config(['clockwork.hetzner.token' => 'test-hetzner-token']);
        $provider = app(HetznerCloudProvider::class);

        Http::fake([
            'api.hetzner.cloud/v1/servers/*/metrics*' => Http::response(
                HetznerFixtures::metricsResponse(50.0)
            ),
        ]);

        $server = Server::factory()->hetzner()->create(['provider_id' => '987654', 'vcpus' => 2]);

        $metrics = $provider->metrics($server, time() - 300, time());

        // Hetzner reports CPU summed across vCPUs; the adapter divides by
        // vcpus to normalize to a 0-100 percentage (see class implementation).
        expect($metrics['cpu_pct'])->toBe(25.0);
        expect($metrics['memory_pct'])->toBeNull();
        expect($metrics['disk_pct'])->toBeNull();
    });
});

describe('AzureCloudProvider', function () {
    beforeEach(function () {
        $this->provider = app(AzureCloudProvider::class);
    });

    it('reports its real id/label/instanceNoun/iconClass', function () {
        expect($this->provider->id())->toBe('azure');
        expect($this->provider->label())->toBe('Azure VM');
        expect($this->provider->instanceNoun())->toBe('VM');
        expect($this->provider->iconClass())->toBe('fa-solid fa-server');
    });

    /**
     * Confirmed via the class docblock: no Azure-specific tier map exists.
     * The adapter returns the raw slug unchanged rather than running it
     * through DigitalOcean's unrelated naming scheme.
     */
    it('returns the raw slug unchanged instead of mapping it to a tier', function () {
        expect($this->provider->sizeTier('Standard_B2s'))->toBe('Standard_B2s');
        expect($this->provider->sizeTier('s-2vcpu-4gb'))->toBe('s-2vcpu-4gb');
        expect($this->provider->sizeTier(null))->toBeNull();
    });

    /**
     * Regression guard for the historical bug described in the task and in
     * ProviderDispatchTest's docblock: Azure servers used to fall through
     * to DO's alive-ID check and were always misclassified as deleted.
     * AzureCloudProvider::isDeletedAtProvider() now unconditionally returns
     * false — Azure resource IDs are stable long-lived ARM paths, not
     * ephemeral instance IDs, so there is nothing to cross-reference. This
     * must hold true even when handed an alive-ID list that does NOT
     * contain the server's provider_id.
     */
    it('never reports deleted, even given an alive-ID list missing the server\'s provider_id', function () {
        $server = Server::factory()->azure()->create([
            'provider_id' => '/subscriptions/sub-1/resourceGroups/rg-1/providers/Microsoft.Compute/virtualMachines/clockwork-az-01',
        ]);

        expect($this->provider->isDeletedAtProvider($server, ['some-other-id-entirely']))->toBeFalse();
        expect($this->provider->isDeletedAtProvider($server, []))->toBeFalse();
        expect($this->provider->isDeletedAtProvider($server, null))->toBeFalse();
    });

    it('has no alive-ID list to fetch — aliveProviderIds() always returns null', function () {
        expect($this->provider->aliveProviderIds())->toBeNull();
    });

    it('fetches metrics from the real Azure Monitor API shape via Http::fake', function () {
        config([
            'clockwork.azure.tenant_id' => 'test-tenant',
            'clockwork.azure.client_id' => 'test-client',
            'clockwork.azure.client_secret' => 'test-secret',
            'clockwork.azure.subscription_id' => 'test-sub',
        ]);
        $provider = app(AzureCloudProvider::class);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(AzureFixtures::oauthToken()),
            'management.azure.com/*' => Http::response(
                AzureFixtures::vmMetricsResponse(12.0, 2_000_000_000.0)
            ),
        ]);

        $server = Server::factory()->azure()->create([
            'provider_id' => '/subscriptions/test-sub/resourceGroups/rg-1/providers/Microsoft.Compute/virtualMachines/clockwork-az-01',
            'memory_mb' => 4096,
        ]);

        $metrics = $provider->metrics($server, time() - 300, time());

        expect($metrics['cpu_pct'])->toBe(12.0);
        expect($metrics['disk_pct'])->toBeNull();
        expect($metrics['load_1'])->toBeNull();
    });
});

describe('NullCloudProvider', function () {
    beforeEach(function () {
        $this->provider = app(NullCloudProvider::class);
    });

    it('is never configured', function () {
        expect($this->provider->isConfigured())->toBeFalse();
    });

    it('reports its real id/label/instanceNoun/iconClass', function () {
        expect($this->provider->id())->toBe('unknown');
        expect($this->provider->label())->toBe('Unrecognized provider');
        expect($this->provider->instanceNoun())->toBe('instance');
        expect($this->provider->iconClass())->toBe('fa-solid fa-circle-question');
    });

    it('returns the raw slug unchanged from sizeTier() (no mapping exists)', function () {
        expect($this->provider->sizeTier('anything-goes'))->toBe('anything-goes');
        expect($this->provider->sizeTier(null))->toBeNull();
    });

    it('never reports deleted, regardless of the alive-ID list given', function () {
        $server = Server::factory()->create(['provider' => 'some-unrecognized-string']);

        expect($this->provider->isDeletedAtProvider($server, ['1', '2']))->toBeFalse();
        expect($this->provider->isDeletedAtProvider($server, null))->toBeFalse();
    });

    it('has no alive-ID list and no instances-by-ip', function () {
        expect($this->provider->aliveProviderIds())->toBeNull();
        expect($this->provider->instancesByIp())->toBe([]);
    });
});
