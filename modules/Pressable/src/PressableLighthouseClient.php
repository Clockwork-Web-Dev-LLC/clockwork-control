<?php

namespace Modules\Pressable;

use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Services\Performance\PerformanceScanResult;
use Throwable;

/**
 * Wraps Pressable's own built-in Lighthouse performance report as a
 * PerformanceScanResult, so Pressable-hosted sites get the exact same
 * Performance page as SpinupWP sites without a separate UI.
 *
 * Verified live against perfcheck.example 2026-08-29 — richer than our PSI
 * scrape (adds accessibility/best-practices/SEO scores; Core Web Vitals
 * fields line up 1:1). Used exclusively for Pressable sites in
 * RunPerformanceScans — no GTmetrix/PSI fallback needed since this is
 * Pressable's own always-on report, not a rate-limited third-party API.
 */
class PressableLighthouseClient
{
    public function __construct(private readonly PressableClient $pressable) {}

    public function scanSite(Site $site, string $strategy = SitePerformanceScan::STRATEGY_MOBILE): PerformanceScanResult
    {
        $started = (int) (microtime(true) * 1000);

        if ($site->pressable_site_id === null) {
            return new PerformanceScanResult(
                site: $site,
                status: SitePerformanceScan::STATUS_FAILED,
                strategy: $strategy,
                error: 'Site has no pressable_site_id',
                elapsedMs: 0,
                engine: SitePerformanceScan::ENGINE_PRESSABLE,
            );
        }

        try {
            $report = $this->pressable->sitePerformanceReport($site->pressable_site_id);
            $elapsedMs = (int) (microtime(true) * 1000) - $started;

            $key = $strategy === SitePerformanceScan::STRATEGY_DESKTOP ? 'desktop_report' : 'mobile_report';
            $sub = $report[$key] ?? null;

            if (! is_array($sub) || ! isset($sub['performance_score'])) {
                return new PerformanceScanResult(
                    site: $site,
                    status: SitePerformanceScan::STATUS_FAILED,
                    strategy: $strategy,
                    error: "Pressable performance report missing {$key}.performance_score",
                    elapsedMs: $elapsedMs,
                    engine: SitePerformanceScan::ENGINE_PRESSABLE,
                );
            }

            return $this->parse($site, $strategy, $sub, $elapsedMs);
        } catch (Throwable $e) {
            return new PerformanceScanResult(
                site: $site,
                status: SitePerformanceScan::STATUS_FAILED,
                strategy: $strategy,
                error: substr('Pressable performance exception: '.$e->getMessage(), 0, 480),
                elapsedMs: (int) (microtime(true) * 1000) - $started,
                engine: SitePerformanceScan::ENGINE_PRESSABLE,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $sub
     */
    private function parse(Site $site, string $strategy, array $sub, int $elapsedMs): PerformanceScanResult
    {
        $cls = $sub['cumulative_layout_shift_numeric_value'] ?? null;

        return new PerformanceScanResult(
            site: $site,
            status: SitePerformanceScan::STATUS_OK,
            strategy: $strategy,
            sourceGeneratedAt: isset($sub['created_at']) ? (string) $sub['created_at'] : null,
            performanceScore: (int) round(((float) $sub['performance_score']) * 100),
            accessibilityScore: $this->score($sub['accessibility_score'] ?? null),
            bestPracticesScore: $this->score($sub['best_practices_score'] ?? null),
            seoScore: $this->score($sub['seo_score'] ?? null),
            lcpMs: $this->numeric($sub['largest_contentful_paint_numeric_value'] ?? null),
            fcpMs: $this->numeric($sub['first_contentful_paint_numeric_value'] ?? null),
            tbtMs: $this->numeric($sub['total_blocking_time_numeric_value'] ?? null),
            siMs: $this->numeric($sub['speed_index_numeric_value'] ?? null),
            clsX1000: is_numeric($cls) ? (int) round((float) $cls * 1000) : null,
            pageUrl: isset($sub['final_url']) ? (string) $sub['final_url'] : ('https://'.$site->domain.'/'),
            region: 'pressable',
            elapsedMs: $elapsedMs,
            engine: SitePerformanceScan::ENGINE_PRESSABLE,
        );
    }

    private function numeric(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /** 0-1 float category score → 0-100 int, matching performanceScore's convention. */
    private function score(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value * 100) : null;
    }
}
