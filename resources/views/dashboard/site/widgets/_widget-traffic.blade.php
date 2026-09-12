@php
    $sshCapable = $site->host()->supports(\Modules\Core\Contracts\HostingProvider::CAP_SSH);
    $hasTraffic = $traffic7d && $traffic7d->isNotEmpty();
    $maxVal = $hasTraffic ? max(1, $traffic7d->max('requests') ?: 1) : 1;
@endphp

<div class="card p-5 flex flex-col justify-between h-full @if (! $sshCapable) opacity-70 bg-[var(--color-surface-alt)]/20 @endif">
    <div>
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-chart-line text-blue-600"></i>
                Analytics &amp; Traffic
            </h3>
            @if ($sshCapable)
                <span class="text-[10px] text-[var(--color-ink-muted)]">Last 7 days</span>
            @else
                <span class="status-pill status-unknown text-[10px]">
                    <i class="fa-solid fa-circle-minus text-[9px]"></i> Unavailable
                </span>
            @endif
        </div>

        @if (! $sshCapable)
            <div class="py-6 text-center">
                <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800/60 text-gray-400 flex items-center justify-center mx-auto mb-3 text-lg">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <div class="font-semibold text-sm text-[var(--color-ink-strong)]">Traffic telemetry unavailable</div>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1.5 max-w-xs mx-auto">
                    Traffic and request volume rollups require server-level access logs (SpinupWP / SSH). Not available on Companion-only sites.
                </p>
            </div>
        @else
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <div class="text-[10px] text-[var(--color-ink-muted)] uppercase font-semibold">Total Visits (7d)</div>
                    <div class="font-display font-bold text-xl text-[var(--color-ink-strong)] mt-0.5">
                        {{ number_format($traffic7dVisits ?? 0) }}
                    </div>
                </div>
                <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <div class="text-[10px] text-[var(--color-ink-muted)] uppercase font-semibold">Requests (7d)</div>
                    <div class="font-display font-bold text-xl text-[var(--color-ink-strong)] mt-0.5">
                        {{ number_format($traffic7dRequests ?? 0) }}
                    </div>
                </div>
            </div>

            {{-- Mini Sparkline / Bar visualization --}}
            @if ($hasTraffic)
                <div class="pt-2">
                    <div class="text-[10px] text-[var(--color-ink-muted)] mb-1.5 flex items-center justify-between">
                        <span>Daily Request Volume</span>
                        <span class="font-mono text-[9px]">{{ $traffic7d->first()->date->format('M j') }} – {{ $traffic7d->last()->date->format('M j') }}</span>
                    </div>
                    <div class="h-16 flex items-end gap-1.5 pt-1 px-1 bg-[var(--color-surface-alt)]/30 rounded-lg border border-[var(--color-border-light)]/50">
                        @foreach ($traffic7d as $day)
                            @php
                                $heightPct = max(8, min(100, (int) round(($day->requests / $maxVal) * 100)));
                            @endphp
                            <div class="flex-1 flex flex-col items-center group relative h-full justify-end">
                                <div class="w-full rounded-t bg-blue-500/70 group-hover:bg-blue-600 transition-all cursor-pointer"
                                     style="height: {{ $heightPct }}%"
                                     title="{{ $day->date->format('M j') }}: {{ number_format($day->requests) }} req, {{ number_format($day->visits) }} visits">
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="py-4 text-center text-xs text-[var(--color-ink-muted)] italic">
                    No recent traffic data rolled up for this period.
                </div>
            @endif
        @endif
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        @if ($sshCapable)
            <span class="text-[11px] text-[var(--color-ink-muted)]">
                {{ number_format($logCount24h) }} threat events (24h)
            </span>
            <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'traffic']) }}"
               class="btn-pill-nav text-xs font-medium text-blue-600 hover:underline">
                View Traffic <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
            </a>
        @else
            <span class="text-[11px] text-[var(--color-ink-muted)] flex items-center gap-1">
                <i class="fa-solid fa-info-circle text-[10px]"></i> Requires server SSH access
            </span>
            <span class="text-xs text-[var(--color-ink-muted)] italic">
                N/A
            </span>
        @endif
    </div>
</div>
