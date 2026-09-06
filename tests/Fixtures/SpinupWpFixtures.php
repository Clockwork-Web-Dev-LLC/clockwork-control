<?php

namespace Tests\Fixtures;

/**
 * Realistic sample SpinupWP API payloads for Http::fake(). SpinupWpClient
 * unwraps every response through ->json('data', []) — see
 * modules/SpinupWp/src/SpinupWpClient.php.
 */
class SpinupWpFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function server(array $overrides = []): array
    {
        return array_merge([
            'id' => 12345,
            'name' => 'web42',
            'ip_address' => '203.0.113.10',
            'provider' => 'digitalocean',
            'ubuntu_version' => '22.04',
            'php_version' => '8.3',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function site(array $overrides = []): array
    {
        return array_merge([
            'id' => 67890,
            'server_id' => 12345,
            'site_domain' => 'example.com',
            'status' => 'active',
            'ssl' => [
                'status' => 'active',
                'expiry_date' => now()->addDays(60)->toIso8601String(),
                'renewal_date' => now()->addDays(30)->toIso8601String(),
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function listResponse(array $items): array
    {
        return ['data' => $items];
    }
}
