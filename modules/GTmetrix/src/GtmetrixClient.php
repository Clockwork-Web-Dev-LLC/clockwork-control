<?php

namespace Modules\GTmetrix;

use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Services\Performance\PerformanceScanResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GTmetrix REST API v2 client. Primary performance-scanning engine since
 * 2026-06-27 (PSI is the fallback, see PageSpeedInsightsClient).
 *
 * Picked over PSI because GTmetrix pins:
 *   - Test location (we default to San Antonio TX, ID 4; per-site override on sites.performance_scan_region)
 *   - Browser version (their managed Chrome instance, controlled rollout)
 *   - Connection profile (cable-equivalent for desktop, 4G for mobile)
 *
 * Result: scores have a much tighter day-over-day distribution than PSI's
 * ±10-15 noise floor — a real signal for "did my page actually get slower."
 *
 * API flow (two HTTP calls minimum):
 *   1. POST /tests        → submits, returns 202 with a test resource ID
 *   2. GET  /tests/{id}   → poll until state == 'completed' or 'error'
 *
 * Auth: HTTP Basic, API key as username, password blank. The Laravel HTTP
 * client's withBasicAuth() handles base64 encoding.
 */
class GtmetrixClient
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        // Region IS an integer (GTmetrix location ID), but we keep it as
        // ?int with explicit cast so config() returning a string from env
        // still coerces cleanly.
        protected ?int $defaultRegion = null,
        protected ?int $pollInterval = null,
        protected ?int $pollMaxAttempts = null,
    ) {
        $this->apiKey ??= (string) config('clockwork.gtmetrix.api_key', '');
        $this->baseUrl ??= (string) config('clockwork.gtmetrix.base_url');
        $this->timeout ??= (int) config('clockwork.gtmetrix.timeout', 120);
        $this->defaultRegion ??= (int) config('clockwork.gtmetrix.region', 4);
        $this->pollInterval ??= (int) config('clockwork.gtmetrix.poll_interval_seconds', 5);
        $this->pollMaxAttempts ??= (int) config('clockwork.gtmetrix.poll_max_attempts', 24);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function scanSite(Site $site, string $strategy = SitePerformanceScan::STRATEGY_MOBILE): PerformanceScanResult
    {
        $url = $this->scanUrl($site);
        $regionId = $this->resolveRegion($site);
        // Persist region as a string in the DB column (it's varchar) but
        // operationally we treat it as the integer location ID GTmetrix
        // expects on the test submission.
        $region = (string) $regionId;
        $started = (int) (microtime(true) * 1000);

        if (! $this->isConfigured()) {
            return new PerformanceScanResult(
                site: $site,
                status: SitePerformanceScan::STATUS_FAILED,
                strategy: $strategy,
                pageUrl: $url,
                region: $region,
                error: 'GTmetrix API key not configured',
                elapsedMs: 0,
                engine: 'gtmetrix',
            );
        }

        try {
            $submission = $this->submitTest($url, $strategy, $regionId);

            // Submission failure: surface the GTmetrix error message verbatim
            // so the operator can tell e.g. "invalid location" from "quota hit"
            // from "URL unreachable."
            if (! isset($submission['data']['id'])) {
                return new PerformanceScanResult(
                    site: $site,
                    status: SitePerformanceScan::STATUS_FAILED,
                    strategy: $strategy,
                    pageUrl: $url,
                    region: $region,
                    error: substr('GTmetrix submission failed: '.$this->submissionError($submission), 0, 480),
                    elapsedMs: (int) (microtime(true) * 1000) - $started,
                    engine: 'gtmetrix',
                );
            }

            $payload = $this->pollUntilComplete((string) $submission['data']['id']);
            $elapsedMs = (int) (microtime(true) * 1000) - $started;

            if (! $payload) {
                return new PerformanceScanResult(
                    site: $site,
                    status: SitePerformanceScan::STATUS_FAILED,
                    strategy: $strategy,
                    pageUrl: $url,
                    region: $region,
                    error: 'GTmetrix poll timed out before test completed',
                    elapsedMs: $elapsedMs,
                    engine: 'gtmetrix',
                );
            }

            return $this->parse($site, $strategy, $url, $region, $payload, $elapsedMs);
        } catch (Throwable $e) {
            return new PerformanceScanResult(
                site: $site,
                status: SitePerformanceScan::STATUS_FAILED,
                strategy: $strategy,
                pageUrl: $url,
                region: $region,
                error: substr('GTmetrix exception: '.$e->getMessage(), 0, 480),
                elapsedMs: (int) (microtime(true) * 1000) - $started,
                engine: 'gtmetrix',
            );
        }
    }

    /**
     * POST /tests — submit a new GTmetrix test. Returns the JSON:API
     * response body (raw decoded).
     *
     * @return array<string, mixed>
     */
    private function submitTest(string $url, string $strategy, int $regionId): array
    {
        // Minimal attribute set: URL + integer location ID + lighthouse report.
        // Every site gets a desktop-Chrome Lighthouse scan from the chosen
        // GTmetrix datacenter, regardless of $strategy. Mobile vs desktop
        // doesn't differentiate at the API level on our paid tier — GTmetrix's
        // /devices + /connections endpoints return empty arrays, and the
        // public /browsers list only exposes desktop Chrome (id=3) + Firefox
        // (id=1). The scheduler also runs only ONE nightly scan now (was two,
        // mobile + desktop, pre-cutover). $strategy is still passed through
        // for the DB row's `strategy` column so historical analysis lines up
        // with pre-cutover PSI rows, but the underlying scan is identical.
        // If GTmetrix unlocks mobile device emulation on a higher tier later,
        // pin `browser` + `simulate_device` here per their /devices listing
        // and restore the second scheduler entry. See CLAUDE.md #29.
        $attributes = [
            'url' => $url,
            // Integer location ID, NOT a slug (GTmetrix v2 dropped slug
            // support; their /locations endpoint returns null for the
            // `code` field on every entry now).
            'location' => $regionId,
            'report' => 'lighthouse',
        ];

        // $strategy intentionally unused — see block comment above.
        unset($strategy);

        // GTmetrix requires JSON:API media type on the Content-Type header
        // (per the JSON:API 1.0 spec they implement) — the generic
        // application/json that Laravel's ->post() sets by default produces
        // a 400 "Request must use Content-Type: application/vnd.api+json".
        // Accept is set the same way for symmetry; GTmetrix accepts the
        // wildcard too but the vnd.api+json subtype is the documented one.
        $response = Http::withBasicAuth($this->apiKey, '')
            ->timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/vnd.api+json',
                'Accept' => 'application/vnd.api+json',
            ])
            ->post("{$this->baseUrl}/tests", [
                'data' => [
                    'type' => 'test',
                    'attributes' => $attributes,
                ],
            ]);

        return is_array($response->json()) ? $response->json() : [];
    }

    /**
     * Poll GET /tests/{id} until the test reaches a terminal state or we
     * run out of attempts. Returns the full body on success, null on timeout.
     *
     * GTmetrix's REST API has a subtle behavior here: while the test runs,
     * the resource has `data.type = "test"` and a `state` attribute that
     * transitions queued → started → ...; ONCE COMPLETE, the same URL
     * returns `data.type = "report"` with no `state` attribute at all but
     * the full metric set (performance_score, lcp, fcp, etc.) populated.
     * So "completion" is signaled by EITHER an explicit terminal state
     * OR a type flip to "report".
     *
     * @return array<string, mixed>|null
     */
    private function pollUntilComplete(string $testId): ?array
    {
        for ($i = 0; $i < $this->pollMaxAttempts; $i++) {
            if ($i > 0) {
                sleep($this->pollInterval);
            }

            $response = Http::withBasicAuth($this->apiKey, '')
                ->timeout(30)
                ->withHeaders(['Accept' => 'application/vnd.api+json'])
                ->get("{$this->baseUrl}/tests/{$testId}");

            $body = is_array($response->json()) ? $response->json() : [];
            $type = data_get($body, 'data.type');
            $state = data_get($body, 'data.attributes.state');

            // Completion signals (any of these = done):
            //   - data.type === 'report' (resource has flipped to the
            //     report shape; metrics are in attributes)
            //   - data.attributes.state === 'completed' (rare on v2 — the
            //     type-flip happens before this would ever fire; kept for
            //     forward-compat if GTmetrix tightens the contract later)
            //   - data.attributes.state === 'error' (test ran but failed —
            //     e.g. site unreachable from the chosen location)
            if ($type === 'report' || $state === 'completed' || $state === 'error') {
                return $body;
            }

            // Transient states: 'queued', 'started', 'analyzing' — keep polling.
        }

        return null;
    }

    /**
     * Translate the GTmetrix completed-test response into the engine-agnostic
     * PerformanceScanResult shape that PerformanceScanRecorder writes to DB.
     *
     * Field mapping (GTmetrix attribute → our column):
     *   performance_score        → performance_score (already 0-100, just round to int)
     *   first_contentful_paint   → fcp_ms
     *   largest_contentful_paint → lcp_ms
     *   total_blocking_time      → tbt_ms
     *   speed_index              → si_ms
     *   cumulative_layout_shift  → cls_x1000 (multiply float by 1000 to int)
     *   page_bytes               → page_weight_bytes
     *   page_requests            → request_count (some payloads use 'requests')
     *
     * @param  array<string, mixed>  $payload
     */
    private function parse(Site $site, string $strategy, string $url, string $region, array $payload, int $elapsedMs): PerformanceScanResult
    {
        $type = (string) data_get($payload, 'data.type', '');
        $attrs = (array) data_get($payload, 'data.attributes', []);
        $state = (string) ($attrs['state'] ?? '');

        // Failure modes:
        //   - state === 'error' (test ran but Lighthouse/site failed)
        //   - type is neither 'report' (success) nor 'test' (somehow still
        //     running — shouldn't reach parse() in this state, but defensive)
        $isReport = $type === 'report';
        if (! $isReport && $state !== 'completed') {
            $err = (string) ($attrs['error'] ?? 'GTmetrix test ended in error state without detail');

            return new PerformanceScanResult(
                site: $site,
                status: SitePerformanceScan::STATUS_FAILED,
                strategy: $strategy,
                pageUrl: $url,
                region: $region,
                error: substr("GTmetrix state={$state}: {$err}", 0, 480),
                elapsedMs: $elapsedMs,
                engine: 'gtmetrix',
            );
        }

        $cls = $attrs['cumulative_layout_shift'] ?? null;

        return new PerformanceScanResult(
            site: $site,
            status: SitePerformanceScan::STATUS_OK,
            strategy: $strategy,
            performanceScore: isset($attrs['performance_score'])
                ? (int) round((float) $attrs['performance_score'])
                : null,
            lcpMs: isset($attrs['largest_contentful_paint']) ? (int) $attrs['largest_contentful_paint'] : null,
            fcpMs: isset($attrs['first_contentful_paint']) ? (int) $attrs['first_contentful_paint'] : null,
            tbtMs: isset($attrs['total_blocking_time']) ? (int) $attrs['total_blocking_time'] : null,
            siMs: isset($attrs['speed_index']) ? (int) $attrs['speed_index'] : null,
            clsX1000: $cls !== null ? (int) round(((float) $cls) * 1000) : null,
            pageWeightBytes: isset($attrs['page_bytes']) ? (int) $attrs['page_bytes'] : null,
            requestCount: isset($attrs['page_requests'])
                ? (int) $attrs['page_requests']
                : (isset($attrs['requests']) ? (int) $attrs['requests'] : null),
            pageUrl: $url,
            region: $region,
            elapsedMs: $elapsedMs,
            engine: 'gtmetrix',
        );
    }

    /**
     * URL to actually probe. Defaults to https://{domain}/. Future hook for
     * a per-site "performance_scan_url" override (e.g. probing a stable
     * landing page instead of a dynamic homepage); reads from that column
     * if it exists on the model and is set.
     */
    private function scanUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);

        return 'https://'.$domain.'/';
    }

    /**
     * Per-site region override falls back to the global default. Column
     * is varchar so we accept any non-empty cast-to-int value.
     */
    private function resolveRegion(Site $site): int
    {
        $override = (string) ($site->performance_scan_region ?? '');

        return $override !== '' ? (int) $override : $this->defaultRegion;
    }

    /**
     * Extract a human-readable error from a failed submission response.
     * GTmetrix returns JSON:API errors in `errors[].detail`.
     *
     * @param  array<string, mixed>  $body
     */
    private function submissionError(array $body): string
    {
        $errors = $body['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $first = $errors[0] ?? [];

            return (string) ($first['detail'] ?? $first['title'] ?? 'unknown error');
        }

        return 'no error detail in response';
    }
}
