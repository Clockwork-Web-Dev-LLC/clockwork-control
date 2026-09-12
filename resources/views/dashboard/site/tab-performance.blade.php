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
                    <i class="fa-solid fa-mobile-screen-button text-[var(--color-ink-soft)] mr-1"></i>
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
                    <i class="fa-solid fa-display text-[var(--color-ink-soft)] mr-1"></i>
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

{{-- 30-day trend chart --}}
@if (count($perfTrend['mobile']) + count($perfTrend['desktop']) > 0)
    <div class="card p-5 mb-6">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">
            <i class="fa-solid fa-chart-line text-[var(--color-ink-soft)] mr-1"></i>
            30-day score trend
        </h2>
        <div style="height: 240px;">
            <canvas id="perf-trend-chart"></canvas>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script>
    (() => {
        const ctx = document.getElementById('perf-trend-chart');
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                datasets: [
                    {
                        label: 'Mobile',
                        data: @json($perfTrend['mobile']),
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.08)',
                        tension: 0.2,
                        pointRadius: 3,
                    },
                    {
                        label: 'Desktop',
                        data: @json($perfTrend['desktop']),
                        borderColor: '#0ea5e9',
                        backgroundColor: 'rgba(14, 165, 233, 0.08)',
                        tension: 0.2,
                        pointRadius: 3,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { type: 'time', time: { unit: 'day' } },
                    y: { min: 0, max: 100, ticks: { stepSize: 20 } },
                },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    })();
    </script>
@endif

{{-- History table --}}
<div class="card p-5">
    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">
        <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-soft)] mr-1"></i>
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
