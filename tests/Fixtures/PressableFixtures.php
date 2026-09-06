<?php

namespace Tests\Fixtures;

/**
 * Realistic sample Pressable API payloads for Http::fake(). PressableClient
 * unwraps most responses through ->json('data', []) — see
 * modules/Pressable/src/PressableClient.php.
 */
class PressableFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function site(array $overrides = []): array
    {
        return array_merge([
            'id' => '111222',
            'name' => 'example-site',
            'primaryDomain' => ['name' => 'example.com'],
            'state' => 'live',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function backup(array $overrides = []): array
    {
        return array_merge([
            'id' => 'bk_abc123',
            'datetime' => now()->subHours(6)->toIso8601String(),
            'type' => 'full',
            'automated' => true,
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
