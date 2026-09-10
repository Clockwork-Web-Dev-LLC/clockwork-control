<?php

namespace Tests\Fixtures;

/**
 * Realistic sample Google PageSpeed Insights v5 payloads for Http::fake().
 * See app/Services/Performance/PageSpeedInsightsClient.php.
 */
class PageSpeedInsightsFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function successResponse(float $score = 0.82): array
    {
        return [
            'lighthouseResult' => [
                'categories' => [
                    'performance' => ['score' => $score],
                    'accessibility' => ['score' => 0.96],
                    'best-practices' => ['score' => 0.92],
                    'seo' => ['score' => 1.0],
                ],
                'audits' => [
                    'largest-contentful-paint' => ['numericValue' => 1900],
                    'first-contentful-paint' => ['numericValue' => 950],
                    'total-blocking-time' => ['numericValue' => 150],
                    'speed-index' => ['numericValue' => 1600],
                    'cumulative-layout-shift' => ['numericValue' => 0.04],
                    'total-byte-weight' => ['numericValue' => 1_700_000],
                    'network-requests' => ['details' => ['items' => array_fill(0, 40, [])]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function missingScoreResponse(): array
    {
        return ['lighthouseResult' => ['categories' => [], 'audits' => []]];
    }
}
