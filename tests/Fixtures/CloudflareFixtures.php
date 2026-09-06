<?php

namespace Tests\Fixtures;

/**
 * Realistic sample Cloudflare API v4 payloads for Http::fake(). Every
 * endpoint CloudflareClient reads unwraps through ->json('result', []) —
 * see app/Services/Cloudflare/CloudflareClient.php.
 */
class CloudflareFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function zone(array $overrides = []): array
    {
        return array_merge([
            'id' => 'zone-abc123',
            'name' => 'example.com',
            'status' => 'active',
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $result
     * @return array<string, mixed>
     */
    public static function envelope(array $result, bool $success = true): array
    {
        return ['success' => $success, 'errors' => [], 'messages' => [], 'result' => $result];
    }
}
