@php
    $isUp = $site->uptime_state === 'up';
    $isDown = $site->uptime_state === 'down';
    $authProtected = $isUp && in_array($site->uptime_last_status_code, [401, 403], true);
    $monitoringEnabled = (bool) $site->uptime_monitoring_enabled;
    $pct = $uptimePercentage30d ?? 100.0;
@endphp

<div class="card p-5 flex flex-col justify-between h-full">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-heart-pulse text-emerald-600"></i>
                Uptime Monitor
            </h3>
            @if (! $monitoringEnabled)
                <span class="status-pill status-unknown text-[10px]">
                    <span class="status-dot"></span> Disabled
                </span>
            @elseif ($isDown)
                <span class="status-pill status-red text-[10px]">
                    <span class="status-dot"></span> Down
                </span>
            @else
                <span class="status-pill status-green text-[10px]">
                    <span class="status-dot"></span> {{ $authProtected ? 'Up (Auth)' : 'Operational' }}
                </span>
            @endif
        </div>

        {{-- Circular Status Indicator & Metric --}}
        <div class="flex items-center gap-4 py-2">
            <div class="relative w-16 h-16 shrink-0 flex items-center justify-center">
                <svg class="w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                    <path class="text-[var(--color-surface-alt)]" stroke-width="3.5" stroke="currentColor" fill="none"
                          d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    <path class="{{ ! $monitoringEnabled ? 'text-gray-300' : ($isDown ? 'text-rose-500' : 'text-emerald-500') }} transition-all duration-700 ease-out"
                          stroke-dasharray="{{ $monitoringEnabled ? ($pct . ', 100') : '0, 100' }}"
                          stroke-width="3.5"
                          stroke-linecap="round"
                          stroke="currentColor"
                          fill="none"
                          d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                    @if (! $monitoringEnabled)
                        <i class="fa-solid fa-pause text-gray-400 text-xs"></i>
                    @elseif ($isDown)
                        <span class="font-bold text-[11px] text-rose-600">DOWN</span>
                    @else
                        <span class="font-bold text-[11px] text-emerald-600">UP</span>
                    @endif
                </div>
            </div>

            <div class="min-w-0">
                <div class="font-display font-semibold text-base text-[var(--color-ink-strong)]">
                    Overall uptime {{ number_format($pct, 1) }}%
                </div>
                <div class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                    @if (! $monitoringEnabled)
                        Monitoring is paused for this site
                    @elseif ($isDown && $site->uptime_down_since)
                        Down for {{ $site->uptime_down_since->diffForHumans(['parts' => 2, 'short' => true]) }}
                        @if ($site->uptime_last_status_code) (HTTP {{ $site->uptime_last_status_code }}) @endif
                    @elseif ($site->uptime_last_up_at)
                        Up for {{ $site->uptime_last_up_at->diffForHumans(['parts' => 2, 'short' => true]) }}
                    @else
                        Awaiting initial probe
                    @endif
                </div>
            </div>
        </div>

        {{-- Latest Events Mini-Log --}}
        <div class="mt-3 pt-3 border-t border-[var(--color-border-light)]">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-2">
                Latest Events
            </div>
            @if ($recentUptimeEvents->isNotEmpty())
                <ul class="space-y-1.5 text-xs">
                    @foreach ($recentUptimeEvents as $event)
                        <li class="flex items-center justify-between text-[11px]">
                            <span class="flex items-center gap-1.5 truncate">
                                @if ($event->event_type === 'up')
                                    <span class="text-emerald-600 font-bold">↑ Up</span>
                                @else
                                    <span class="text-rose-600 font-bold">↓ Down</span>
                                @endif
                                <span class="text-[var(--color-ink-soft)] truncate">
                                    {{ $event->error ?: ($event->status_code ? 'HTTP ' . $event->status_code : 'Check completed') }}
                                </span>
                            </span>
                            <span class="text-[10px] text-[var(--color-ink-muted)] shrink-0 ml-2">
                                {{ $event->event_at->diffForHumans(['short' => true]) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="text-xs text-[var(--color-ink-muted)] italic">
                    No recent downtime recorded (100% stable).
                </div>
            @endif
        </div>
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <form method="POST" action="{{ route('sites.uptime-monitoring.toggle', $site) }}">
            @csrf
            <input type="hidden" name="enabled" value="{{ $monitoringEnabled ? '0' : '1' }}">
            <button type="submit" class="text-[11px] font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] transition-colors">
                <i class="fa-solid {{ $monitoringEnabled ? 'fa-toggle-on text-emerald-600' : 'fa-toggle-off text-gray-400' }} mr-1"></i>
                {{ $monitoringEnabled ? 'Monitoring Active' : 'Enable Monitoring' }}
            </button>
        </form>

        <form method="POST" action="{{ route('sites.uptime.recheck', $site) }}" class="inline">
            @csrf
            <button type="submit" class="btn-pill-nav text-xs py-1 px-2.5" title="Run manual uptime probe now">
                <i class="fa-solid fa-rotate text-[10px] mr-1"></i> Re-check
            </button>
        </form>
    </div>
</div>
