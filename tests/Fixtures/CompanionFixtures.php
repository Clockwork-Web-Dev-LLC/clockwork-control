<?php

namespace Tests\Fixtures;

/**
 * Realistic sample Companion mu-plugin REST responses for Http::fake().
 * See app/Services/Companion/ClockworkCompanionClient.php — every endpoint
 * returns a plain top-level JSON object (no envelope).
 */
class CompanionFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function health(array $overrides = []): array
    {
        return array_merge([
            'ok' => true,
            'version' => '1.30.4',
            'capabilities' => ['malware-scan', 'resource-metrics', 'two-factor'],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function detect(array $overrides = []): array
    {
        return array_merge([
            'installed' => true,
            'version' => '1.30.4',
            'capabilities' => ['malware-scan', 'resource-metrics', 'two-factor'],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function malwareScan(array $overrides = []): array
    {
        return array_merge([
            'ok' => true,
            'scanned_files_count' => 4210,
            'findings' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function malwareScanWithFindings(): array
    {
        return self::malwareScan([
            'findings' => [
                ['path' => 'wp-content/uploads/2026/01/shell.php', 'signature' => 'eval-base64'],
            ],
        ]);
    }
}
