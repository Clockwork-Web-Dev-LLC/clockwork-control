<?php

use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Support\CredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Core\ModuleManifest;
use Modules\PageSpeedInsights\PageSpeedInsightsClient;
use Modules\PageSpeedInsights\PageSpeedInsightsServiceProvider;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('PageSpeedInsights module', function () {
    it('manifest provides verified status and api_key credential field', function () {
        $provider = new PageSpeedInsightsServiceProvider(app());
        $manifest = $provider->manifest();

        expect($manifest->id)->toBe('psi');
        expect($manifest->name)->toBe('PageSpeed Insights');
        expect($manifest->status)->toBe(ModuleManifest::STATUS_VERIFIED);
        expect($manifest->credentialFields)->toHaveKey('api_key');
        expect($manifest->credentialFields['api_key']['secret'])->toBeTrue();
    });

    it('resolves PageSpeedInsightsClient from container with CredentialResolver values', function () {
        $resolver = app(CredentialResolver::class);
        $client = app(PageSpeedInsightsClient::class);

        expect($client)->toBeInstanceOf(PageSpeedInsightsClient::class);
        expect($client)->toBeInstanceOf(App\Services\Performance\PageSpeedInsightsClient::class);
    });

    it('fails gracefully when api_key is not configured', function () {
        $client = new PageSpeedInsightsClient(apiKey: '');
        $site = Site::factory()->make(['domain' => 'example.test']);

        $result = $client->scanSite($site);

        expect($result->status)->toBe(SitePerformanceScan::STATUS_FAILED);
        expect($result->error)->toContain('CLOCKWORK_PSI_API_KEY not configured');
    });

    it('parses a successful Lighthouse PSI response correctly', function () {
        $site = Site::factory()->make(['domain' => 'example.test']);

        Http::fake([
            'https://www.googleapis.com/pagespeedonline/v5/runPagespeed*' => Http::response([
                'lighthouseResult' => [
                    'categories' => [
                        'performance' => ['score' => 0.95],
                        'accessibility' => ['score' => 0.98],
                        'best-practices' => ['score' => 0.92],
                        'seo' => ['score' => 1.0],
                    ],
                    'audits' => [
                        'largest-contentful-paint' => ['numericValue' => 1250],
                        'first-contentful-paint' => ['numericValue' => 850],
                        'total-blocking-time' => ['numericValue' => 45],
                        'speed-index' => ['numericValue' => 980],
                        'cumulative-layout-shift' => ['numericValue' => 0.012],
                        'total-byte-weight' => ['numericValue' => 450000],
                        'network-requests' => [
                            'details' => [
                                'items' => [
                                    ['url' => 'https://example.test/'],
                                    ['url' => 'https://example.test/style.css'],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new PageSpeedInsightsClient(apiKey: 'test-key', timeout: 30);
        $result = $client->scanSite($site);

        expect($result->status)->toBe(SitePerformanceScan::STATUS_OK);
        expect($result->performanceScore)->toBe(95);
        expect($result->accessibilityScore)->toBe(98);
        expect($result->bestPracticesScore)->toBe(92);
        expect($result->seoScore)->toBe(100);
        expect($result->lcpMs)->toBe(1250);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'category=performance')
                && str_contains($url, 'category=accessibility')
                && str_contains($url, 'category=best-practices')
                && str_contains($url, 'category=seo')
                && ! str_contains($url, 'category[');
        });
        expect($result->fcpMs)->toBe(850);
        expect($result->tbtMs)->toBe(45);
        expect($result->siMs)->toBe(980);
        expect($result->clsX1000)->toBe(12);
        expect($result->requestCount)->toBe(2);
    });
});
