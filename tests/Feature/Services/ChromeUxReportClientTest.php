<?php

use App\Models\SiteFieldMetric;
use Illuminate\Support\Facades\Http;
use Modules\PageSpeedInsights\ChromeUxReportClient;

it('parses successful crux response with good metrics and cwv pass', function () {
    Http::fake([
        'https://chromeuxreport.googleapis.com/v1/records:queryRecord*' => Http::response([
            'record' => [
                'key' => [
                    'origin' => 'https://example.com',
                    'formFactor' => 'PHONE',
                ],
                'metrics' => [
                    'largest_contentful_paint' => [
                        'percentiles' => ['p75' => 1800],
                        'histogram' => [
                            ['start' => 0, 'end' => 2500, 'density' => 0.85],
                            ['start' => 2500, 'end' => 4000, 'density' => 0.10],
                        ],
                    ],
                    'interaction_to_next_paint' => [
                        'percentiles' => ['p75' => 120],
                        'histogram' => [
                            ['start' => 0, 'end' => 200, 'density' => 0.95],
                        ],
                    ],
                    'cumulative_layout_shift' => [
                        'percentiles' => ['p75' => '0.04'],
                        'histogram' => [
                            ['start' => 0, 'end' => '0.1', 'density' => 0.92],
                        ],
                    ],
                    'first_contentful_paint' => [
                        'percentiles' => ['p75' => 1100],
                        'histogram' => [
                            ['start' => 0, 'end' => 1800, 'density' => 0.88],
                        ],
                    ],
                    'experimental_time_to_first_byte' => [
                        'percentiles' => ['p75' => 450],
                        'histogram' => [
                            ['start' => 0, 'end' => 800, 'density' => 0.79],
                        ],
                    ],
                ],
                'collectionPeriod' => [
                    'firstDate' => ['year' => 2026, 'month' => 9, 'day' => 1],
                    'lastDate' => ['year' => 2026, 'month' => 9, 'day' => 28],
                ],
            ],
        ], 200),
    ]);

    $client = new ChromeUxReportClient(apiKey: 'test-key');
    $result = $client->queryRecord('example.com', 'PHONE');

    expect($result['status'])->toBe(SiteFieldMetric::STATUS_OK)
        ->and($result['form_factor'])->toBe('phone')
        ->and($result['lcp_p75_ms'])->toBe(1800)
        ->and($result['inp_p75_ms'])->toBe(120)
        ->and($result['cls_p75_x1000'])->toBe(40)
        ->and($result['fcp_p75_ms'])->toBe(1100)
        ->and($result['ttfb_p75_ms'])->toBe(450)
        ->and($result['cwv_pass'])->toBeTrue()
        ->and($result['period_start'])->toBe('2026-09-01')
        ->and($result['period_end'])->toBe('2026-09-28')
        ->and($result['good_pct']['lcp'])->toBe(85.0);
});

it('handles 404 as no_data without failing or tripping circuit breaker', function () {
    Http::fake([
        'https://chromeuxreport.googleapis.com/v1/records:queryRecord*' => Http::response([
            'error' => [
                'code' => 404,
                'message' => 'Record not found.',
                'status' => 'NOT_FOUND',
            ],
        ], 404),
    ]);

    $client = new ChromeUxReportClient(apiKey: 'test-key');
    $result = $client->queryRecord('lowtraffic.com', 'PHONE');

    expect($result['status'])->toBe(SiteFieldMetric::STATUS_NO_DATA)
        ->and($result['form_factor'])->toBe('phone')
        ->and($result['error'])->toContain('Not enough Chrome traffic');
});

it('handles 429 quota exhaustion as failed status', function () {
    Http::fake([
        'https://chromeuxreport.googleapis.com/v1/records:queryRecord*' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'Quota exceeded',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ], 429),
    ]);

    $client = new ChromeUxReportClient(apiKey: 'test-key');
    $result = $client->queryRecord('example.com', 'PHONE');

    expect($result['status'])->toBe(SiteFieldMetric::STATUS_FAILED)
        ->and($result['error'])->toContain('429');
});
