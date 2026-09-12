@php
    $hasScans = $latestPerfMobile || $latestPerfDesktop;
    $mobileScore = $latestPerfMobile?->performance_score;
    $desktopScore = $latestPerfDesktop?->performance_score;

    $scoreColor = function (?int $score) {
        if ($score === null) return 'text-gray-400';
        if ($score >= 90) return 'text-emerald-600';
        if ($score >= 50) return 'text-amber-600';
        return 'text-rose-600';
    };

    $isOptimized = ($mobileScore === null || $mobileScore >= 85) && ($desktopScore === null || $desktopScore >= 85) && $hasScans;
@endphp

<div class="card p-5 flex flex-col justify-between h-full">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-gauge-high text-amber-500"></i>
                Performance &amp; Speed
            </h3>
            @if (! $site->isCarePlanActive())
                <span class="status-pill status-unknown text-[10px]">No Care Plan</span>
            @elseif (! $hasScans)
                <span class="status-pill status-unknown text-[10px]">Awaiting Scan</span>
            @elseif ($isOptimized)
                <span class="status-pill status-green text-[10px]">
                    <span class="status-dot"></span> Optimized
                </span>
            @else
                <span class="status-pill status-yellow text-[10px]">
                    <span class="status-dot"></span> Needs Review
                </span>
            @endif
        </div>

        @if (! $hasScans)
            <div class="text-center py-6">
                <div class="w-12 h-12 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center mx-auto mb-3 text-lg">
                    <i class="fa-solid fa-stopwatch"></i>
                </div>
                <div class="font-semibold text-sm text-[var(--color-ink-strong)]">No performance scans recorded</div>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1 max-w-xs mx-auto">
                    Weekly Lighthouse audit rotates nightly across managed sites.
                </p>
            </div>
        @else
            @if ($isOptimized)
                <div class="text-center py-3 mb-2">
                    <div class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-2 text-base">
                        <i class="fa-solid fa-gauge-simple-high"></i>
                    </div>
                    <div class="font-semibold text-sm text-[var(--color-ink-strong)]">Everything is optimized</div>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                        High Lighthouse scores &amp; fast Core Web Vitals.
                    </p>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3 py-1">
                <div class="p-3 rounded-xl bg-[var(--color-surface-alt)]/60 text-center">
                    <div class="text-[10px] uppercase font-semibold text-[var(--color-ink-muted)] mb-1 flex items-center justify-center gap-1">
                        <i class="fa-solid fa-mobile-screen text-[10px]"></i> Mobile (SEO)
                    </div>
                    @if ($mobileScore !== null)
                        <div class="font-display font-bold text-2xl {{ $scoreColor($mobileScore) }}">
                            {{ $mobileScore }}<span class="text-xs font-normal text-[var(--color-ink-muted)]">/100</span>
                        </div>
                    @else
                        <div class="text-xs text-[var(--color-ink-muted)] mt-1">—</div>
                    @endif
                </div>

                <div class="p-3 rounded-xl bg-[var(--color-surface-alt)]/60 text-center">
                    <div class="text-[10px] uppercase font-semibold text-[var(--color-ink-muted)] mb-1 flex items-center justify-center gap-1">
                        <i class="fa-solid fa-laptop text-[10px]"></i> Desktop
                    </div>
                    @if ($desktopScore !== null)
                        <div class="font-display font-bold text-2xl {{ $scoreColor($desktopScore) }}">
                            {{ $desktopScore }}<span class="text-xs font-normal text-[var(--color-ink-muted)]">/100</span>
                        </div>
                    @else
                        <div class="text-xs text-[var(--color-ink-muted)] mt-1">—</div>
                    @endif
                </div>
            </div>

            @if ($latestPerfMobile && $latestPerfMobile->lcp_ms)
                <div class="mt-2 text-[11px] text-[var(--color-ink-muted)] flex items-center justify-between px-1">
                    <span>Largest Contentful Paint (LCP):</span>
                    <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                        {{ number_format($latestPerfMobile->lcp_ms / 1000, 2) }}s
                    </span>
                </div>
            @endif
        @endif
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <span class="text-[11px] text-[var(--color-ink-muted)]">
            @if ($latestPerfMobile?->scanned_at)
                Scanned {{ $latestPerfMobile->scanned_at->diffForHumans() }}
            @elseif ($latestPerfDesktop?->scanned_at)
                Scanned {{ $latestPerfDesktop->scanned_at->diffForHumans() }}
            @endif
        </span>
        <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'performance']) }}"
           class="btn-pill-nav text-xs font-medium text-[var(--color-brand)] hover:underline">
            Performance Details <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
        </a>
    </div>
</div>
