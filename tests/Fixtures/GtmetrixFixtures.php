<?php

namespace Tests\Fixtures;

/**
 * Realistic sample GTmetrix REST API v2 payloads (JSON:API-shaped) for
 * Http::fake(). See app/Services/Performance/GtmetrixClient.php.
 */
class GtmetrixFixtures
{
    /**
     * Response while a submitted test is still running.
     *
     * @return array<string, mixed>
     */
    public static function testSubmitted(string $testId = 'test-abc123'): array
    {
        return ['data' => ['id' => $testId, 'type' => 'test', 'attributes' => ['state' => 'queued']]];
    }

    /**
     * Completed report — the shape GtmetrixClient::parse() reads.
     *
     * @return array<string, mixed>
     */
    public static function completedReport(array $overrides = []): array
    {
        return [
            'data' => [
                'id' => 'report-abc123',
                'type' => 'report',
                'attributes' => array_merge([
                    'state' => 'completed',
                    'performance_score' => 0.87,
                    'largest_contentful_paint' => 1800,
                    'first_contentful_paint' => 900,
                    'total_blocking_time' => 120,
                    'speed_index' => 1500,
                    'cumulative_layout_shift' => 0.05,
                    'page_bytes' => 1_800_000,
                    'page_requests' => 45,
                ], $overrides),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function errorReport(string $error = 'Could not resolve host'): array
    {
        return ['data' => ['id' => 'report-err', 'type' => 'test', 'attributes' => ['state' => 'error', 'error' => $error]]];
    }
}
