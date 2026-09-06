<?php

namespace Tests\Fixtures;

/**
 * Realistic sample Hetzner Cloud API (api.hetzner.cloud/v1) payloads for
 * Http::fake(). See modules/Hetzner/src/HetznerClient.php. Hetzner exposes
 * CPU only — no memory or disk metric.
 */
class HetznerFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function server(array $overrides = []): array
    {
        return array_merge([
            'id' => 987654,
            'name' => 'clockwork-hz-01',
            'status' => 'running',
            'server_type' => ['name' => 'cx21', 'cores' => 2, 'memory' => 4, 'disk' => 40],
            'public_net' => ['ipv4' => ['ip' => '198.51.100.10']],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serversListResponse(array $servers): array
    {
        return ['servers' => $servers, 'meta' => ['pagination' => ['next_page' => null]]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function metricsResponse(float $cpuPercent = 15.0): array
    {
        return [
            'metrics' => [
                'time_series' => [
                    'cpu' => ['values' => [[time(), (string) $cpuPercent]]],
                ],
            ],
        ];
    }
}
