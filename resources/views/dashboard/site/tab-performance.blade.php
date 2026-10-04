@php
    $onCarePlan = $site->isCarePlanActive();

    $scoreColorClass = function (?int $score): string {
        if ($score === null) {
            return 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]';
        }
        if ($score >= 90) {
            return 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)]';
        }
        if ($score >= 50) {
            return 'bg-[var(--color-status-yellow)]/15 text-[var(--color-status-yellow)]';
        }
        return 'bg-[var(--color-status-red)]/15 text-[var(--color-status-red)]';
    };

    $letterGrade = function (?int $score): string {
        if ($score === null) {
            return '—';
        }
        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 50 => 'C',
            $score >= 30 => 'D',
            default => 'E',
        };
    };

    $formatMs = function ($ms): string {
        if (! is_numeric($ms)) {
            return '—';
        }
        $ms = (int) $ms;
        return $ms >= 1000 ? number_format($ms / 1000, 2).' s' : number_format($ms).' ms';
    };

    $formatCls = function ($x1000): string {
        if (! is_numeric($x1000)) {
            return '—';
        }
        return number_format(((int) $x1000) / 1000, 3);
    };

    $formatBytes = function ($bytes): string {
        if (! is_numeric($bytes)) {
            return '—';
        }
        $bytes = (int) $bytes;
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0).' KB';
        }
        return $bytes.' B';
    };
@endphp

@if (! $onCarePlan)
    <div class="card p-4 mb-5 flex items-start gap-3 border-l-4 border-[var(--color-ink-soft)]">
        <i class="fa-regular fa-circle text-[var(--color-ink-soft)] mt-0.5"></i>
        <div class="text-sm flex-1">
            <p class="text-[var(--color-ink-strong)] font-medium">Performance scans run on the care plan only.</p>
            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">Daily Lighthouse scans (mobile + desktop) are part of the care plan. Enable on the <a class="text-[var(--color-primary-600)] hover:underline" href="{{ route('sites.show', [$site, 'settings']) }}">Settings tab</a> to begin scheduled runs.</p>
        </div>
    </div>
@endif

<div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
    {{-- Mobile hero --}}
    @php
        $m = $latestPerfMobile;
        $mScore = $m?->performance_score;
        $mGrade = $letterGrade($mScore);
        $mClass = $scoreColorClass($mScore);
    @endphp
    <div class="card p-5">
        <div class="flex items-start justify-between mb-4 gap-3">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-mobile-screen-button text-[var(--color-ink-muted)] mr-1"></i>
                    Mobile
                </h2>
                <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                    @if ($m)
                        Scanned {{ $m->scanned_at->diffForHumans() }} · daily 03:30 UTC
                    @else
                        Not yet scanned · daily 03:30 UTC
                    @endif
                </p>
            </div>
            @if ($m && $mScore !== null)
                <div class="flex items-baseline gap-2">
                    <span class="text-4xl font-bold font-data px-3 py-1 rounded-md {{ $mClass }}">{{ $mGrade }}</span>
                    <span class="text-sm text-[var(--color-ink-muted)] font-data">{{ $mScore }}/100</span>
                </div>
            @else
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]">Pending</span>
            @endif
        </div>
        @if ($m)
            <dl class="grid grid-cols-3 gap-3 text-xs">
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">LCP</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatMs($m->lcp_ms) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">CLS</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatCls($m->cls_x1000) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">TBT</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatMs($m->tbt_ms) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">FCP</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatMs($m->fcp_ms) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">Speed Index</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatMs($m->si_ms) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">Page weight</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatBytes($m->page_weight_bytes) }}</dd></div>
            </dl>
            @if ($m->accessibility_score !== null || $m->best_practices_score !== null || $m->seo_score !== null)
                {{-- Reported by PageSpeed Insights and Pressable engines — GTmetrix's API does not expose them. --}}
                <dl class="grid grid-cols-3 gap-3 text-xs mt-3 pt-3 border-t border-[var(--color-border-light)]">
                    <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">Accessibility</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $m->accessibility_score ?? '—' }}</dd></div>
                    <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">Best Practices</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $m->best_practices_score ?? '—' }}</dd></div>
                    <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">SEO</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $m->seo_score ?? '—' }}</dd></div>
                </dl>
            @endif
        @endif
    </div>

    {{-- Desktop hero --}}
    @php
        $d = $latestPerfDesktop;
        $dScore = $d?->performance_score;
        $dGrade = $letterGrade($dScore);
        $dClass = $scoreColorClass($dScore);
    @endphp
    <div class="card p-5">
        <div class="flex items-start justify-between mb-4 gap-3">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-display text-[var(--color-ink-muted)] mr-1"></i>
                    Desktop
                </h2>
                <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                    @if ($d)
                        Scanned {{ $d->scanned_at->diffForHumans() }} · daily 04:30 UTC
                    @else
                        Not yet scanned · daily 04:30 UTC
                    @endif
                </p>
            </div>
            @if ($d && $dScore !== null)
                <div class="flex items-baseline gap-2">
                    <span class="text-4xl font-bold font-data px-3 py-1 rounded-md {{ $dClass }}">{{ $dGrade }}</span>
                    <span class="text-sm text-[var(--color-ink-muted)] font-data">{{ $dScore }}/100</span>
                </div>
            @else
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]">Pending</span>
            @endif
        </div>
        @if ($d)
            <dl class="grid grid-cols-3 gap-3 text-xs">
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">LCP</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatMs($d->lcp_ms) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">CLS</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatCls($d->cls_x1000) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">TBT</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatMs($d->tbt_ms) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">FCP</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatMs($d->fcp_ms) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">Speed Index</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatMs($d->si_ms) }}</dd></div>
                <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">Page weight</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $formatBytes($d->page_weight_bytes) }}</dd></div>
            </dl>
            @if ($d->accessibility_score !== null || $d->best_practices_score !== null || $d->seo_score !== null)
                <dl class="grid grid-cols-3 gap-3 text-xs mt-3 pt-3 border-t border-[var(--color-border-light)]">
                    <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">Accessibility</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $d->accessibility_score ?? '—' }}</dd></div>
                    <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">Best Practices</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $d->best_practices_score ?? '—' }}</dd></div>
                    <div><dt class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">SEO</dt><dd class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $d->seo_score ?? '—' }}</dd></div>
                </dl>
            @endif
        @endif
    </div>
</div>

{{-- Real Users (CrUX Field Data) --}}
@php
    $fieldPhone = $latestFieldMetricPhone ?? null;
    $fieldDesktop = $latestFieldMetricDesktop ?? null;
@endphp
<div class="card p-5 mb-6">
    <div class="flex items-start justify-between mb-4 gap-3">
        <div>
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-users text-[var(--color-ink-muted)]"></i>
                Real Users (Core Web Vitals)
                @if ($fieldPhone && $fieldPhone->status === 'ok')
                    @if ($fieldPhone->cwv_pass)
                        <span class="px-2 py-0.5 rounded text-xs font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                            <i class="fa-solid fa-check mr-1"></i> Passed Core Web Vitals
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded text-xs font-semibold bg-amber-500/15 text-amber-600 dark:text-amber-400">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i> Needs Improvement
                        </span>
                    @endif
                @endif
            </h2>
            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                Chrome User Experience Report (CrUX) · 28-day rolling collection period
                @if ($fieldPhone?->period_start && $fieldPhone?->period_end)
                    ({{ $fieldPhone->period_start->format('M j') }} – {{ $fieldPhone->period_end->format('M j, Y') }})
                @endif
            </p>
        </div>
        <div class="text-right">
            <span class="text-xs text-[var(--color-ink-muted)] block">75th Percentile (p75)</span>
        </div>
    </div>

    @if ($fieldPhone && $fieldPhone->status === 'ok')
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-center">
            {{-- LCP --}}
            @php $lcpRating = $fieldPhone->ratingForMetric('lcp'); @endphp
            <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                <span class="text-[10px] uppercase font-bold tracking-wider text-[var(--color-ink-muted)] block">LCP</span>
                <span class="text-xl font-bold font-data text-[var(--color-ink-strong)] block mt-0.5">{{ $fieldPhone->lcpFormatted() }}</span>
                <span class="text-[10px] font-semibold mt-1 inline-block px-1.5 py-0.5 rounded {{ $lcpRating === 'good' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : ($lcpRating === 'needs_improvement' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400') }}">
                    {{ ucfirst(str_replace('_', ' ', $lcpRating)) }}
                </span>
            </div>
            {{-- INP --}}
            @php $inpRating = $fieldPhone->ratingForMetric('inp'); @endphp
            <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                <span class="text-[10px] uppercase font-bold tracking-wider text-[var(--color-ink-muted)] block">INP</span>
                <span class="text-xl font-bold font-data text-[var(--color-ink-strong)] block mt-0.5">{{ $fieldPhone->inpFormatted() }}</span>
                <span class="text-[10px] font-semibold mt-1 inline-block px-1.5 py-0.5 rounded {{ $inpRating === 'good' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : ($inpRating === 'needs_improvement' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400') }}">
                    {{ ucfirst(str_replace('_', ' ', $inpRating)) }}
                </span>
            </div>
            {{-- CLS --}}
            @php $clsRating = $fieldPhone->ratingForMetric('cls'); @endphp
            <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                <span class="text-[10px] uppercase font-bold tracking-wider text-[var(--color-ink-muted)] block">CLS</span>
                <span class="text-xl font-bold font-data text-[var(--color-ink-strong)] block mt-0.5">{{ $fieldPhone->clsFormatted() }}</span>
                <span class="text-[10px] font-semibold mt-1 inline-block px-1.5 py-0.5 rounded {{ $clsRating === 'good' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : ($clsRating === 'needs_improvement' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400') }}">
                    {{ ucfirst(str_replace('_', ' ', $clsRating)) }}
                </span>
            </div>
            {{-- FCP --}}
            @php $fcpRating = $fieldPhone->ratingForMetric('fcp'); @endphp
            <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                <span class="text-[10px] uppercase font-bold tracking-wider text-[var(--color-ink-muted)] block">FCP</span>
                <span class="text-xl font-bold font-data text-[var(--color-ink-strong)] block mt-0.5">{{ $fieldPhone->fcpFormatted() }}</span>
                <span class="text-[10px] font-semibold mt-1 inline-block px-1.5 py-0.5 rounded {{ $fcpRating === 'good' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : ($fcpRating === 'needs_improvement' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400') }}">
                    {{ ucfirst(str_replace('_', ' ', $fcpRating)) }}
                </span>
            </div>
            {{-- TTFB --}}
            @php $ttfbRating = $fieldPhone->ratingForMetric('ttfb'); @endphp
            <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                <span class="text-[10px] uppercase font-bold tracking-wider text-[var(--color-ink-muted)] block">TTFB</span>
                <span class="text-xl font-bold font-data text-[var(--color-ink-strong)] block mt-0.5">{{ $fieldPhone->ttfbFormatted() }}</span>
                <span class="text-[10px] font-semibold mt-1 inline-block px-1.5 py-0.5 rounded {{ $ttfbRating === 'good' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : ($ttfbRating === 'needs_improvement' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400') }}">
                    {{ ucfirst(str_replace('_', ' ', $ttfbRating)) }}
                </span>
            </div>
        </div>
    @elseif ($fieldPhone && $fieldPhone->status === 'no_data')
        <div class="p-4 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-center text-sm text-[var(--color-ink-muted)]">
            <i class="fa-solid fa-chart-simple text-lg mb-1 opacity-50 block"></i>
            Not enough Chrome traffic for Google to report real-user field data for this origin.
        </div>
    @else
        <div class="p-4 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-center text-sm text-[var(--color-ink-muted)]">
            <i class="fa-regular fa-clock text-lg mb-1 opacity-50 block"></i>
            Real-user field metrics collection scheduled weekly for care-plan sites.
        </div>
    @endif
</div>

{{-- 30-day trend chart --}}
@if (count($perfTrend['mobile']) + count($perfTrend['desktop']) > 0)
    <div class="card p-5 mb-6" x-data="performanceTrendChart({{ json_encode($perfTrend) }})">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">
            <i class="fa-solid fa-chart-line text-[var(--color-ink-muted)] mr-1"></i>
            30-day score trend
        </h2>
        <div id="perf-trend-chart" style="height: 240px;"></div>
    </div>
@endif

{{-- History table --}}
<div class="card p-5">
    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">
        <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] mr-1"></i>
        Recent scans
    </h2>
    @if ($perfHistory->isEmpty())
        <p class="text-sm text-[var(--color-ink-muted)]">No scans recorded yet. The next scheduled runs are mobile 03:30 UTC and desktop 04:30 UTC.</p>
    @else
        <div class="overflow-x-auto -mx-5">
            <table class="w-full text-sm">
                <thead class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">
                    <tr class="border-b border-[var(--color-border-light)]">
                        <th class="text-left px-5 py-2">When</th>
                        <th class="text-left px-5 py-2">Strategy</th>
                        <th class="text-left px-5 py-2">Score</th>
                        <th class="text-left px-5 py-2">LCP</th>
                        <th class="text-left px-5 py-2">CLS</th>
                        <th class="text-left px-5 py-2">TBT</th>
                        <th class="text-left px-5 py-2">Page weight</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($perfHistory as $row)
                        @php
                            $rowOk = $row->status === \App\Models\SitePerformanceScan::STATUS_OK;
                            $rowScoreClass = $scoreColorClass($row->performance_score);
                        @endphp
                        <tr class="border-b border-[var(--color-border-light)] hover:bg-[var(--color-surface-alt)]">
                            <td class="px-5 py-2 font-data text-xs">
                                {{ $row->scanned_at->diffForHumans() }}
                                <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $row->scanned_at->format('M j, Y H:i') }} UTC</div>
                            </td>
                            <td class="px-5 py-2 capitalize">{{ $row->strategy }}</td>
                            <td class="px-5 py-2">
                                @if ($rowOk)
                                    <span class="font-data font-semibold px-2 py-0.5 rounded {{ $rowScoreClass }}">{{ $row->performance_score }}</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-[var(--color-status-yellow)]/15 text-[var(--color-status-yellow)]">Failed</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 font-data">{{ $formatMs($row->lcp_ms) }}</td>
                            <td class="px-5 py-2 font-data">{{ $formatCls($row->cls_x1000) }}</td>
                            <td class="px-5 py-2 font-data">{{ $formatMs($row->tbt_ms) }}</td>
                            <td class="px-5 py-2 font-data">{{ $formatBytes($row->page_weight_bytes) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
