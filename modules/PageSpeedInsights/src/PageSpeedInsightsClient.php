<?php

namespace Modules\PageSpeedInsights;

use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Services\Performance\PerformanceScanResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google PageSpeed Insights v5 wrapper. Free with API key from Google Cloud
 * Console; default quota is 25k req/day, rate-limited around 240/min — way
 * more headroom than ~150 sites/day needs.
 *
 * Endpoint:
 *   GET https://www.googleapis.com/pagespeedonline/v5/runPagespeed
 *       ?url=...&strategy=mobile|desktop&category=performance&key=KEY
 *
 * The response is the full Lighthouse JSON. We pluck the Performance category
 * score and the five scoring audits (LCP, FCP, TBT, SI, CLS) plus total byte
 * weight + network request count. We don't store the raw response — Lighthouse
 * payloads are 200KB+ each and the columns we keep capture everything a client
 * report needs. If we ever need the raw audit detail, we can re-run the scan.
 */
class PageSpeedInsightsClient
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?int $timeout = null,
    ) {
        $this->apiKey ??= (string) config('clockwork.psi.api_key', '');
        $this->timeout ??= (int) config('clockwork.psi.timeout', 90);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Run PSI against the site's primary domain (https://) and return a VO.
     * Failures collapse into status='failed' with the cause captured in error.
     */
    public function scanSite(Site $site, string $strategy = SitePerformanceScan::STRATEGY_MOBILE): PerformanceScanResult
    {
        $url = 'https://'.$site->domain.'/';
        $started = (int) (microtime(true) * 1000);

        if (! $this->isConfigured()) {
            return new PerformanceScanResult(
                site: $site,
                status: SitePerformanceScan::STATUS_FAILED,
                strategy: $strategy,
                pageUrl: $url,
                error: 'CLOCKWORK_PSI_API_KEY not configured',
                elapsedMs: 0,
            );
        }

        try {
            // PSI is a slow API — Lighthouse takes 20-60s on a real page.
            // We run sequentially, one site at a time, so the 90s timeout is
            // generous. retry(2) covers the occasional transient timeout.
            $response = Http::timeout($this->timeout)
                ->retry(2, 2_000, throw: false)
                ->acceptJson()
                ->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', [
                    'url' => $url,
                    'strategy' => $strategy,
                    'category' => 'performance',
                    'key' => $this->apiKey,
                ]);

            $elapsedMs = (int) (microtime(true) * 1000) - $started;

            if ($response->failed()) {
                $body = $response->json('error.message') ?? $response->body();

                return new PerformanceScanResult(
                    site: $site,
                    status: SitePerformanceScan::STATUS_FAILED,
                    strategy: $strategy,
                    pageUrl: $url,
                    error: substr("PSI HTTP {$response->status()}: {$body}", 0, 480),
                    elapsedMs: $elapsedMs,
                );
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return new PerformanceScanResult(
                    site: $site,
                    status: SitePerformanceScan::STATUS_FAILED,
                    strategy: $strategy,
                    pageUrl: $url,
                    error: 'PSI returned non-JSON body',
                    elapsedMs: $elapsedMs,
                );
            }

            return $this->parse($site, $strategy, $url, $payload, $elapsedMs);
        } catch (Throwable $e) {
            return new PerformanceScanResult(
                site: $site,
                status: SitePerformanceScan::STATUS_FAILED,
                strategy: $strategy,
                pageUrl: $url,
                error: substr('PSI exception: '.$e->getMessage(), 0, 480),
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parse(Site $site, string $strategy, string $url, array $payload, int $elapsedMs): PerformanceScanResult
    {
        $score = data_get($payload, 'lighthouseResult.categories.performance.score');
        if (! is_numeric($score)) {
            return new PerformanceScanResult(
                site: $site,
                status: SitePerformanceScan::STATUS_FAILED,
                strategy: $strategy,
                pageUrl: $url,
                error: 'PSI response missing Performance category score',
                elapsedMs: $elapsedMs,
            );
        }

        $audits = data_get($payload, 'lighthouseResult.audits', []);

        $lcpMs = $this->numericMs($audits, 'largest-contentful-paint');
        $fcpMs = $this->numericMs($audits, 'first-contentful-paint');
        $tbtMs = $this->numericMs($audits, 'total-blocking-time');
        $siMs = $this->numericMs($audits, 'speed-index');
        $cls = data_get($audits, 'cumulative-layout-shift.numericValue');
        $clsX1000 = is_numeric($cls) ? (int) round((float) $cls * 1000) : null;

        $pageWeight = data_get($audits, 'total-byte-weight.numericValue');
        $networkItems = data_get($audits, 'network-requests.details.items', []);
        $requestCount = is_array($networkItems) ? count($networkItems) : null;

        return new PerformanceScanResult(
            site: $site,
            status: SitePerformanceScan::STATUS_OK,
            strategy: $strategy,
            performanceScore: (int) round((float) $score * 100),
            lcpMs: $lcpMs,
            fcpMs: $fcpMs,
            tbtMs: $tbtMs,
            siMs: $siMs,
            clsX1000: $clsX1000,
            pageWeightBytes: is_numeric($pageWeight) ? (int) $pageWeight : null,
            requestCount: $requestCount,
            pageUrl: $url,
            region: 'google',
            elapsedMs: $elapsedMs,
        );
    }

    /**
     * @param  array<string, mixed>  $audits
     */
    private function numericMs(array $audits, string $key): ?int
    {
        $v = data_get($audits, $key.'.numericValue');

        return is_numeric($v) ? (int) round((float) $v) : null;
    }
}
