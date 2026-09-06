<?php

namespace App\Services\Performance;

use App\Models\ActionLog;
use App\Models\SitePerformanceScan;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Support\Carbon;

/**
 * Single chokepoint for persisting a PerformanceScanResult: writes one
 * site_performance_scans row + one action_logs row via ActionLogger. The
 * action_logs entry mirrors to Companion automatically (per the existing
 * ActionLogger pipeline), so a Companion-equipped site sees the result on
 * its Performance admin page after the next scan.
 *
 * Skips the write entirely when $r->sourceGeneratedAt matches the last
 * stored row for this site/strategy/engine — Pressable's Lighthouse report
 * only regenerates roughly once a month (confirmed live via
 * get_site_performance_score_history: one entry per calendar month, and
 * re-polling /reports/performance/latest on consecutive days returned the
 * identical report's own created_at both times). Without this check, a
 * daily poll would store ~30 duplicate rows of the same stale report
 * between Pressable's real refreshes — fake history, not real measurements.
 * PSI/GTmetrix never set sourceGeneratedAt, so this never skips them.
 */
class PerformanceScanRecorder
{
    public function __construct(private readonly ActionLogger $logger) {}

    public function record(PerformanceScanResult $r): ?SitePerformanceScan
    {
        if ($r->sourceGeneratedAt !== null && $this->isDuplicateOfLastScan($r)) {
            return null;
        }

        $now = Carbon::now();

        $row = SitePerformanceScan::create([
            'site_id' => $r->site->id,
            'scanned_at' => $now,
            'status' => $r->status,
            'strategy' => $r->strategy,
            'engine' => $r->engine,
            'source_generated_at' => $r->sourceGeneratedAt,
            'performance_score' => $r->performanceScore,
            'accessibility_score' => $r->accessibilityScore,
            'best_practices_score' => $r->bestPracticesScore,
            'seo_score' => $r->seoScore,
            'lcp_ms' => $r->lcpMs,
            'fcp_ms' => $r->fcpMs,
            'tbt_ms' => $r->tbtMs,
            'si_ms' => $r->siMs,
            'cls_x1000' => $r->clsX1000,
            'page_weight_bytes' => $r->pageWeightBytes,
            'request_count' => $r->requestCount,
            'page_url' => $r->pageUrl,
            'region' => $r->region,
            'error' => $r->error,
            'elapsed_ms' => $r->elapsedMs,
        ]);

        $summary = $r->isOk()
            ? sprintf(
                'Performance %d/100 (%s) — LCP %sms, CLS %s.',
                $r->performanceScore ?? 0,
                $r->strategy,
                $r->lcpMs ?? '—',
                $r->clsX1000 !== null ? number_format($r->clsX1000 / 1000, 3) : '—',
            )
            : 'Performance scan failed.';

        $this->logger->record(
            actionType: ActionLog::TYPE_PERFORMANCE_SCAN,
            summary: $summary,
            site: $r->site,
            target: $r->strategy,
            details: [
                'scan_id' => $row->id,
                'status' => $r->status,
                'strategy' => $r->strategy,
                'engine' => $r->engine,
                'performance_score' => $r->performanceScore,
                'accessibility_score' => $r->accessibilityScore,
                'best_practices_score' => $r->bestPracticesScore,
                'seo_score' => $r->seoScore,
                'lcp_ms' => $r->lcpMs,
                'fcp_ms' => $r->fcpMs,
                'tbt_ms' => $r->tbtMs,
                'si_ms' => $r->siMs,
                'cls_x1000' => $r->clsX1000,
                'page_weight_bytes' => $r->pageWeightBytes,
                'request_count' => $r->requestCount,
                'page_url' => $r->pageUrl,
                'region' => $r->region,
            ],
            ok: $r->isOk(),
            error: $r->error,
            elapsedMs: $r->elapsedMs,
            actor: 'system',
            ranAt: $now,
        );

        return $row;
    }

    private function isDuplicateOfLastScan(PerformanceScanResult $r): bool
    {
        $last = SitePerformanceScan::query()
            ->where('site_id', $r->site->id)
            ->where('strategy', $r->strategy)
            ->where('engine', $r->engine)
            ->orderByDesc('id')
            ->value('source_generated_at');

        if ($last === null) {
            return false;
        }

        return Carbon::parse($last)->equalTo(Carbon::parse($r->sourceGeneratedAt));
    }
}
