<?php

use App\Support\ServiceRateLimitRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('ServiceRateLimitRegistry', function () {
    it('contains all 25 supported service integrations with complete metadata', function () {
        $registry = app(ServiceRateLimitRegistry::class);
        $services = $registry->all();

        $expectedServices = [
            'digitalocean',
            'hetzner',
            'linode',
            'vultr',
            'azure',
            'spinupwp',
            'pressable',
            'wpengine',
            'kinsta',
            'cloudways',
            'gridpane',
            'cloudflare',
            'psi',
            'gtmetrix',
            'sucuri',
            'twilio',
            'slack',
            'mattermost',
            'bill-com',
            'auth_google',
            'auth_github',
            'auth_microsoft',
            'contact-forms',
            'client_slack',
            'backup-relay',
        ];

        expect(count($services))->toBe(25);

        foreach ($expectedServices as $serviceId) {
            expect($services)->toHaveKey($serviceId);
            $service = $services[$serviceId];

            expect($service['id'])->toBe($serviceId)
                ->and($service['name'])->toBeString()->not->toBeEmpty()
                ->and($service['category'])->toBeString()->not->toBeEmpty()
                ->and($service['docs_url'])->toStartWith('https://')
                ->and($service['rate_limit_docs_url'])->toStartWith('https://')
                ->and($service['official_limits'])->toBeArray()
                ->and($service['official_limits'])->toHaveKeys(['standard', 'window', 'headers', 'exceeded_code', 'burst_notes'])
                ->and($service['fleet_impact'])->toBeArray()
                ->and($service['fleet_impact'])->toHaveKeys(['calls_per_server', 'fleet_projection', 'recommendation'])
                ->and($service['defaults'])->toBeArray()
                ->and($service['defaults'])->toHaveKeys(['rate_limit', 'rate_limit_unit', 'timeout', 'concurrency', 'delay_ms', 'retry_attempts']);
        }
    });

    it('returns null for an unknown service ID in get()', function () {
        $registry = app(ServiceRateLimitRegistry::class);

        expect($registry->get('nonexistent-service'))->toBeNull();
    });

    it('returns service definition for a known service ID in get()', function () {
        $registry = app(ServiceRateLimitRegistry::class);

        $digitalocean = $registry->get('digitalocean');
        expect($digitalocean)->not->toBeNull()
            ->and($digitalocean['name'])->toBe('DigitalOcean')
            ->and($digitalocean['defaults']['timeout'])->toBe(15);
    });

    it('returns defaults with is_custom false when no settings override exists', function () {
        $registry = app(ServiceRateLimitRegistry::class);

        $tunables = $registry->getTunables('spinupwp');
        expect($tunables['timeout'])->toBe(15)
            ->and($tunables['concurrency'])->toBe(2)
            ->and($tunables['delay_ms'])->toBe(250)
            ->and($tunables['is_custom'])->toBeFalse();
    });

    it('saves custom tunables to settings and reflects them in getTunables() with is_custom true', function () {
        $registry = app(ServiceRateLimitRegistry::class);

        $registry->saveTunables('spinupwp', [
            'timeout' => 45,
            'rate_limit' => 50,
            'concurrency' => 1,
            'delay_ms' => 300,
            'retry_attempts' => 4,
        ]);

        $tunables = $registry->getTunables('spinupwp');
        expect($tunables['timeout'])->toBe(45)
            ->and($tunables['rate_limit'])->toBe(50)
            ->and($tunables['concurrency'])->toBe(1)
            ->and($tunables['delay_ms'])->toBe(300)
            ->and($tunables['retry_attempts'])->toBe(4)
            ->and($tunables['is_custom'])->toBeTrue();
    });

    it('resets custom tunables back to default values', function () {
        $registry = app(ServiceRateLimitRegistry::class);

        $registry->saveTunables('kinsta', [
            'timeout' => 60,
            'delay_ms' => 500,
        ]);

        expect($registry->getTunables('kinsta')['is_custom'])->toBeTrue();

        $registry->resetToDefaults('kinsta');

        $tunables = $registry->getTunables('kinsta');
        expect($tunables['timeout'])->toBe(15)
            ->and($tunables['delay_ms'])->toBe(300)
            ->and($tunables['is_custom'])->toBeFalse();
    });

    it('clamps or sanitizes non-standard numeric inputs when saving tunables', function () {
        $registry = app(ServiceRateLimitRegistry::class);

        $registry->saveTunables('digitalocean', [
            'timeout' => -5,
            'concurrency' => 0,
            'delay_ms' => -100,
            'rate_limit' => -50,
        ]);

        $tunables = $registry->getTunables('digitalocean');
        expect($tunables['timeout'])->toBeGreaterThanOrEqual(1)
            ->and($tunables['concurrency'])->toBeGreaterThanOrEqual(1)
            ->and($tunables['delay_ms'])->toBeGreaterThanOrEqual(0);
    });
});
