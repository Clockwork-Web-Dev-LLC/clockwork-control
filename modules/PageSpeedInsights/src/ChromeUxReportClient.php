<?php

namespace Modules\PageSpeedInsights;

use App\Models\SiteFieldMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client for Google's Chrome UX Report (CrUX) API.
 *
 * CrUX provides real-world user experience data (Core Web Vitals) collected from
 * real Chrome users over a 28-day rolling window.
 *
 * Endpoint:
 *   POST https://chromeuxreport.googleapis.com/v1/records:queryRecord?key=KEY
 *   Body: {"origin": "https://{domain}", "formFactor": "PHONE"|"DESKTOP"}
 *
 * 404 means the origin has insufficient Chrome real-user traffic to meet privacy/statistical
 * thresholds. This is treated as STATUS_NO_DATA, not a network/service failure.
 */
class ChromeUxReportClient
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?int $timeout = null,
    ) {
        $this->apiKey ??= (string) (config('clockwork.crux.api_key') ?: config('clockwork.psi.api_key', ''));
        $this->timeout ??= (int) config('clockwork.crux.timeout', 30);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Query CrUX record for a domain.
     *
     * @param  string  $domain  e.g. "example.com" or "www.example.com"
     * @param  string  $formFactor  "PHONE" or "DESKTOP" (or 'phone'/'desktop')
     * @param  string  $scope  "origin" or "url"
     * @return array<string, mixed>
     */
    public function queryRecord(string $domain, string $formFactor = 'PHONE', string $scope = 'origin'): array
    {
        $domain = preg_replace('#^https?://#', '', rtrim($domain, '/'));
        $formFactorUpper = strtoupper($formFactor);
        $normFormFactor = strtolower($formFactor);

        if (! $this->isConfigured()) {
            return [
                'status' => SiteFieldMetric::STATUS_FAILED,
                'form_factor' => $normFormFactor,
                'scope' => $scope,
                'error' => 'CrUX / PSI API key is not configured',
            ];
        }

        try {
            $url = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord?key='.$this->apiKey;

            $payload = [
                'formFactor' => $formFactorUpper,
            ];

            if ($scope === 'url') {
                $payload['url'] = 'https://'.$domain.'/';
            } else {
                $payload['origin'] = 'https://'.$domain;
            }

            $response = Http::timeout($this->timeout)
                ->retry(2, 1000, throw: false)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->status() === 404) {
                return [
                    'status' => SiteFieldMetric::STATUS_NO_DATA,
                    'form_factor' => $normFormFactor,
                    'scope' => $scope,
                    'error' => 'Not enough Chrome traffic for Google to report real-user data',
                ];
            }

            if ($response->failed()) {
                $errMsg = $response->json('error.message') ?? $response->body();

                return [
                    'status' => SiteFieldMetric::STATUS_FAILED,
                    'form_factor' => $normFormFactor,
                    'scope' => $scope,
                    'error' => substr("CrUX HTTP {$response->status()}: {$errMsg}", 0, 480),
                ];
            }

            $data = $response->json('record');
            if (! is_array($data)) {
                return [
                    'status' => SiteFieldMetric::STATUS_FAILED,
                    'form_factor' => $normFormFactor,
                    'scope' => $scope,
                    'error' => 'CrUX response missing record object',
                ];
            }

            return $this->parseRecord($data, $normFormFactor, $scope);
        } catch (Throwable $e) {
            return [
                'status' => SiteFieldMetric::STATUS_FAILED,
                'form_factor' => $normFormFactor,
                'scope' => $scope,
                'error' => substr('CrUX exception: '.$e->getMessage(), 0, 480),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function parseRecord(array $record, string $formFactor, string $scope): array
    {
        $metrics = $record['metrics'] ?? [];

        $lcpMs = $this->extractIntPercentile($metrics, 'largest_contentful_paint');
        $inpMs = $this->extractIntPercentile($metrics, 'interaction_to_next_paint');
        $fcpMs = $this->extractIntPercentile($metrics, 'first_contentful_paint');
        $ttfbMs = $this->extractIntPercentile($metrics, 'experimental_time_to_first_byte');

        // CLS is expressed as a decimal float e.g. 0.05 or "0.05"
        $clsRaw = data_get($metrics, 'cumulative_layout_shift.percentiles.p75');
        $clsX1000 = is_numeric($clsRaw) ? (int) round((float) $clsRaw * 1000) : null;

        $goodPct = [
            'lcp' => $this->extractGoodPercentage($metrics, 'largest_contentful_paint'),
            'inp' => $this->extractGoodPercentage($metrics, 'interaction_to_next_paint'),
            'cls' => $this->extractGoodPercentage($metrics, 'cumulative_layout_shift'),
            'fcp' => $this->extractGoodPercentage($metrics, 'first_contentful_paint'),
            'ttfb' => $this->extractGoodPercentage($metrics, 'experimental_time_to_first_byte'),
        ];

        // CWV pass requires:
        // LCP <= 2500ms
        // CLS <= 0.10 (cls_p75_x1000 <= 100)
        // INP <= 200ms (if measured; some low-traffic pages lack INP)
        $cwvPass = null;
        if ($lcpMs !== null && $clsX1000 !== null) {
            $lcpGood = $lcpMs <= 2500;
            $clsGood = $clsX1000 <= 100;
            $inpGood = $inpMs === null || $inpMs <= 200;
            $cwvPass = $lcpGood && $clsGood && $inpGood;
        }

        $period = $record['collectionPeriod'] ?? [];
        $firstDate = $period['firstDate'] ?? null;
        $lastDate = $period['lastDate'] ?? null;

        $periodStart = null;
        if (is_array($firstDate) && isset($firstDate['year'], $firstDate['month'], $firstDate['day'])) {
            $periodStart = Carbon::createFromDate($firstDate['year'], $firstDate['month'], $firstDate['day'])->toDateString();
        }

        $periodEnd = null;
        if (is_array($lastDate) && isset($lastDate['year'], $lastDate['month'], $lastDate['day'])) {
            $periodEnd = Carbon::createFromDate($lastDate['year'], $lastDate['month'], $lastDate['day'])->toDateString();
        }

        return [
            'status' => SiteFieldMetric::STATUS_OK,
            'form_factor' => $formFactor,
            'scope' => $scope,
            'lcp_p75_ms' => $lcpMs,
            'inp_p75_ms' => $inpMs,
            'fcp_p75_ms' => $fcpMs,
            'ttfb_p75_ms' => $ttfbMs,
            'cls_p75_x1000' => $clsX1000,
            'good_pct' => $goodPct,
            'cwv_pass' => $cwvPass,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'error' => null,
        ];
    }

    private function extractIntPercentile(array $metrics, string $key): ?int
    {
        $val = data_get($metrics, "{$key}.percentiles.p75");

        return is_numeric($val) ? (int) round((float) $val) : null;
    }

    private function extractGoodPercentage(array $metrics, string $key): ?float
    {
        $histogram = data_get($metrics, "{$key}.histogram");
        if (! is_array($histogram) || ! isset($histogram[0]['density'])) {
            return null;
        }

        return round(((float) $histogram[0]['density']) * 100, 1);
    }
}
