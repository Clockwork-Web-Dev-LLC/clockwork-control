<?php

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Modules\Vultr\VultrCheck;
use Modules\Vultr\VultrClient;
use Modules\Vultr\VultrCloudProvider;

describe('VultrCloudProvider', function () {
    beforeEach(function () {
        $this->provider = app(VultrCloudProvider::class);
    });

    it('reports its real id/label/instanceNoun/iconClass/iconColor', function () {
        expect($this->provider->id())->toBe(Server::PROVIDER_VULTR);
        expect($this->provider->label())->toBe('Vultr instance');
        expect($this->provider->instanceNoun())->toBe('instance');
        expect($this->provider->iconClass())->toBe('fa-solid fa-server');
        expect($this->provider->iconColor())->toBe('#007BFC');
    });

    it('maps known Vultr plan slugs to size tiers', function () {
        expect($this->provider->sizeTier('vc2-1c-1gb'))->toBe('Cloud Compute');
        expect($this->provider->sizeTier('vhf-2c-4gb'))->toBe('High Frequency');
        expect($this->provider->sizeTier('vhp-4c-8gb'))->toBe('High Performance');
        expect($this->provider->sizeTier('voc-g-2c-8gb'))->toBe('Optimized Cloud (Dedicated)');
        expect($this->provider->sizeTier('vdc-8c-32gb'))->toBe('Dedicated Cloud');
        expect($this->provider->sizeTier('vbm-4c-32gb'))->toBe('Bare Metal');
        expect($this->provider->sizeTier('custom-plan'))->toBe('custom-plan');
        expect($this->provider->sizeTier(null))->toBeNull();
    });

    it('is not configured when no api_key is set', function () {
        config(['clockwork.vultr.api_key' => null]);
        $client = new VultrClient;
        expect($client->isConfigured())->toBeFalse();
    });

    it('is configured when api_key is set', function () {
        config(['clockwork.vultr.api_key' => 'vultr-secret-test-key']);
        $client = new VultrClient;
        expect($client->isConfigured())->toBeTrue();
    });

    it('returns empty instances-by-ip when unconfigured', function () {
        config(['clockwork.vultr.api_key' => null]);
        $provider = app(VultrCloudProvider::class);

        expect($provider->instancesByIp())->toBe([]);
    });

    it('maps instances by public IPv4 address', function () {
        config(['clockwork.vultr.api_key' => 'test-key']);
        $provider = app(VultrCloudProvider::class);

        Http::fake([
            'api.vultr.com/v2/instances*' => Http::response([
                'instances' => [
                    [
                        'id' => 'vultr-inst-01',
                        'label' => 'production-web',
                        'main_ip' => '45.76.10.20',
                        'vcpus' => 2,
                        'ram' => 4096,
                        'disk' => 80,
                        'plan' => 'vhf-2c-4gb',
                    ],
                ],
                'meta' => [
                    'total' => 1,
                    'links' => ['next' => ''],
                ],
            ]),
        ]);

        $byIp = $provider->instancesByIp();

        expect($byIp)->toHaveKey('45.76.10.20');
        expect($byIp['45.76.10.20'])->toBe([
            'id' => 'vultr-inst-01',
            'size_slug' => 'vhf-2c-4gb',
            'vcpus' => 2,
            'memory_mb' => 4096,
            'disk_gb' => 80,
        ]);
    });

    it('correctly determines isDeletedAtProvider based on alive IDs', function () {
        $server = Server::factory()->vultr()->make(['provider_id' => 'vultr-inst-01']);

        expect($this->provider->isDeletedAtProvider($server, ['vultr-inst-01', 'vultr-inst-02']))->toBeFalse();
        expect($this->provider->isDeletedAtProvider($server, ['vultr-inst-02']))->toBeTrue();
        expect($this->provider->isDeletedAtProvider($server, null))->toBeFalse();
    });
});

describe('VultrCheck', function () {
    it('skips when no api key is configured', function () {
        config(['clockwork.vultr.api_key' => null]);
        $check = app(VultrCheck::class);

        $result = $check->run();

        expect($result->status)->toBe('skipped');
    });

    it('passes when GET /account returns 200', function () {
        config(['clockwork.vultr.api_key' => 'valid-test-key']);
        $check = app(VultrCheck::class);

        Http::fake([
            'api.vultr.com/v2/account' => Http::response([
                'account' => [
                    'name' => 'Clockwork Agency',
                    'email' => 'ops@clockworkwd.com',
                ],
            ]),
        ]);

        $result = $check->run();

        expect($result->status)->toBe('ok');
        expect($result->summary)->toContain('ops@clockworkwd.com');
    });

    it('fails when GET /account returns an error', function () {
        config(['clockwork.vultr.api_key' => 'bad-key']);
        $check = app(VultrCheck::class);

        Http::fake([
            'api.vultr.com/v2/account' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $result = $check->run();

        expect($result->status)->toBe('fail');
    });
});
