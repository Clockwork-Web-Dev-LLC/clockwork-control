<?php

namespace App\Services\Performance;

use App\Models\Site;
use App\Models\SitePerformanceScan;

/**
 * Engine-agnostic value object returned by any performance scanner.
 * Today populated by PageSpeedInsightsClient; if we ever swap to GTmetrix
 * or WebPageTest, the recorder + command stay unchanged.
 */
final class PerformanceScanResult
{
    public function __construct(
        public readonly Site $site,
        public readonly string $status,
        public readonly string $strategy = SitePerformanceScan::STRATEGY_MOBILE,
        // Set only when the engine's own report carries a generation
        // timestamp distinct from "whenever we happened to poll it"
        // (Pressable's monthly-batch Lighthouse report). Null for PSI/
        // GTmetrix, where every call is a genuinely fresh scan — see
        // PerformanceScanRecorder for how this prevents storing the same
        // stale report as fake daily history.
        public readonly ?string $sourceGeneratedAt = null,
        public readonly ?int $performanceScore = null,
        public readonly ?int $accessibilityScore = null,
        public readonly ?int $bestPracticesScore = null,
        public readonly ?int $seoScore = null,
        public readonly ?int $lcpMs = null,
        public readonly ?int $fcpMs = null,
        public readonly ?int $tbtMs = null,
        public readonly ?int $siMs = null,
        public readonly ?int $clsX1000 = null,
        public readonly ?int $pageWeightBytes = null,
        public readonly ?int $requestCount = null,
        public readonly ?string $pageUrl = null,
        public readonly ?string $region = null,
        public readonly ?string $error = null,
        public readonly ?int $elapsedMs = null,
        // Which engine produced this row. PageSpeedInsightsClient sets
        // 'psi', GtmetrixClient sets 'gtmetrix'. The RunPerformanceScans
        // command relabels PSI results to 'psi-fallback' when GTmetrix
        // failed first.
        public readonly string $engine = 'psi',
    ) {}

    public function isOk(): bool
    {
        return $this->status === SitePerformanceScan::STATUS_OK;
    }

    /**
     * Return a copy of this result with a different engine label. Used by
     * RunPerformanceScans to relabel a PSI result as 'psi-fallback' when
     * GTmetrix was tried first and failed, so action_log + DB rows can
     * tell "primary engine" vs "fallback ran" apart.
     */
    public function withEngine(string $engine): self
    {
        return new self(
            site: $this->site,
            status: $this->status,
            strategy: $this->strategy,
            sourceGeneratedAt: $this->sourceGeneratedAt,
            performanceScore: $this->performanceScore,
            accessibilityScore: $this->accessibilityScore,
            bestPracticesScore: $this->bestPracticesScore,
            seoScore: $this->seoScore,
            lcpMs: $this->lcpMs,
            fcpMs: $this->fcpMs,
            tbtMs: $this->tbtMs,
            siMs: $this->siMs,
            clsX1000: $this->clsX1000,
            pageWeightBytes: $this->pageWeightBytes,
            requestCount: $this->requestCount,
            pageUrl: $this->pageUrl,
            region: $this->region,
            error: $this->error,
            elapsedMs: $this->elapsedMs,
            engine: $engine,
        );
    }
}
