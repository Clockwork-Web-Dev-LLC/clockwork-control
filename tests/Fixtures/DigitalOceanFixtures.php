<?php

namespace Tests\Fixtures;

/**
 * Realistic sample DigitalOcean API v2 payloads for Http::fake(). See
 * modules/DigitalOcean/src/DigitalOceanClient.php.
 */
class DigitalOceanFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function droplet(array $overrides = []): array
    {
        return array_merge([
            'id' => 555111,
            'name' => 'clockwork-do-01',
            'status' => 'active',
            'size_slug' => 's-2vcpu-4gb',
            'vcpus' => 2,
            'memory' => 4096,
            'disk' => 80,
            'networks' => [
                'v4' => [['ip_address' => '203.0.113.20', 'type' => 'public']],
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function dropletsListResponse(array $droplets): array
    {
        return ['droplets' => $droplets, 'links' => [], 'meta' => ['total' => count($droplets)]];
    }

    /**
     * Prometheus-style monitoring/metrics response shape DO's v2 API uses.
     *
     * @return array<string, mixed>
     */
    public static function metricsResponse(float $value = 42.5): array
    {
        return [
            'status' => 'success',
            'data' => [
                'result' => [
                    ['metric' => [], 'values' => [[time(), (string) $value]]],
                ],
            ],
        ];
    }
}
