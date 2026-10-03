<?php

use App\Models\Site;
use App\Models\SiteFieldMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('collects crux metrics for care plan sites and stores them in database', function () {
    config()->set('clockwork.crux.api_key', 'test-api-key');

    $careSite = Site::factory()->create([
        'domain' => 'my-care-site.com',
        'care_plan_enabled' => true,
        'is_inactive' => false,
    ]);

    $nonCareSite = Site::factory()->create([
        'domain' => 'non-care-site.com',
        'care_plan_enabled' => false,
        'is_inactive' => false,
    ]);

    Http::fake([
        'https://chromeuxreport.googleapis.com/v1/records:queryRecord*' => Http::response([
            'record' => [
                'key' => [
                    'origin' => 'https://my-care-site.com',
                ],
                'metrics' => [
                    'largest_contentful_paint' => [
                        'percentiles' => ['p75' => 2100],
                        'histogram' => [['density' => 0.88]],
                    ],
                    'cumulative_layout_shift' => [
                        'percentiles' => ['p75' => '0.02'],
                        'histogram' => [['density' => 0.99]],
                    ],
                ],
                'collectionPeriod' => [
                    'firstDate' => ['year' => 2026, 'month' => 9, 'day' => 1],
                    'lastDate' => ['year' => 2026, 'month' => 9, 'day' => 28],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('clockwork:collect-field-metrics')
        ->assertExitCode(0);

    // Verify record was created for care plan site
    $metric = SiteFieldMetric::where('site_id', $careSite->id)
        ->where('form_factor', 'phone')
        ->first();

    expect($metric)->not->toBeNull()
        ->and($metric->status)->toBe('ok')
        ->and($metric->lcp_p75_ms)->toBe(2100)
        ->and($metric->cls_p75_x1000)->toBe(20)
        ->and($metric->cwv_pass)->toBeTrue();

    // Verify non-care-plan site was skipped
    expect(SiteFieldMetric::where('site_id', $nonCareSite->id)->exists())->toBeFalse();
});

it('supports collecting for a specific site with --site flag', function () {
    config()->set('clockwork.crux.api_key', 'test-api-key');

    $site = Site::factory()->create([
        'domain' => 'target-site.com',
        'care_plan_enabled' => false,
        'is_inactive' => false,
    ]);

    Http::fake([
        'https://chromeuxreport.googleapis.com/v1/records:queryRecord*' => Http::response([], 404),
    ]);

    $this->artisan('clockwork:collect-field-metrics', ['--site' => $site->domain])
        ->assertExitCode(0);

    $metric = SiteFieldMetric::where('site_id', $site->id)
        ->where('form_factor', 'phone')
        ->first();

    expect($metric)->not->toBeNull()
        ->and($metric->status)->toBe('no_data');
});
