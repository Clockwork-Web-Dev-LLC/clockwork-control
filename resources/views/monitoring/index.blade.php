@extends('layouts.app')

@section('title', 'Monitoring · Clockwork')

@section('content')
    <x-page-header title="Monitoring"
        subtitle="Fleet-wide uptime activity. Probes every monitored site on a schedule and alerts on transitions.">
        <x-slot:actions>
            <form method="POST" action="{{ route('monitoring.refresh') }}" class="inline">
                @csrf
                <button type="submit" class="btn-pill-nav"
                        title="Re-probe every monitored site now. Same command the cron runs. Takes ~10–30 seconds."
                        onclick="this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Probing…';">
                    <i class="fa-solid fa-rotate"></i> <span>Re-probe all sites</span>
                </button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i>
            {{ session('status_error') }}
        </div>
    @endif

    @include('monitoring._tabs')

    {{-- Hero --}}
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div class="card p-5 md:col-span-1 flex flex-col items-center justify-center text-center">
            @php
                $heroState = $currentlyDown > 0 ? 'down' : ($unknown === $sites->count() && $sites->count() > 0 ? 'unknown' : 'up');
                $heroColor = match ($heroState) {
                    'down' => 'border-[var(--color-status-red)] text-[var(--color-status-red)]',
                    'unknown' => 'border-[var(--color-ink-soft)] text-[var(--color-ink-muted)]',
                    default => 'border-[var(--color-status-green)] text-[var(--color-status-green)]',
                };
                $heroLabel = $heroState === 'down'
                    ? "{$currentlyDown} DOWN"
                    : ($heroState === 'unknown' ? 'PENDING' : 'ALL UP');
            @endphp
            <div class="rounded-full border-8 {{ $heroColor }} w-32 h-32 flex items-center justify-center font-display font-bold text-lg">
                {{ $heroLabel }}
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-3">{{ $sites->count() }} sites monitored</p>
        </div>

        <div class="card p-5">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Currently up</div>
            <div class="text-3xl font-bold font-data text-[var(--color-status-green)]">{{ $currentlyUp }}</div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">of {{ $sites->count() }} sites</div>
        </div>

        <div class="card p-5">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Currently down</div>
            <div class="text-3xl font-bold font-data {{ $currentlyDown > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }}">{{ $currentlyDown }}</div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                {{ $unknown }} unknown
                @if ($currentlyIgnored > 0)
                    · <span class="text-[var(--color-status-yellow)]"><i class="fa-solid fa-bell-slash"></i> {{ $currentlyIgnored }} ignored</span>
                @endif
            </div>
        </div>

        <div class="card p-5">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Fleet uptime (7d)</div>
            <div class="text-3xl font-bold font-data text-[var(--color-ink-strong)]">{{ $avg7d !== null ? number_format($avg7d, 2).'%' : '—' }}</div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">average across monitored sites</div>
        </div>

        <div class="card p-5">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Fleet uptime (30d)</div>
            <div class="text-3xl font-bold font-data text-[var(--color-ink-strong)]">{{ $avg30d !== null ? number_format($avg30d, 2).'%' : '—' }}</div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">average across monitored sites</div>
        </div>
    </div>

    {{-- Per-site uptime table --}}
    <div class="card mb-6">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between gap-3">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-list text-[var(--color-ink-soft)] mr-1"></i>
                Sites
            </h2>
            <span class="text-xs text-[var(--color-ink-muted)]">{{ $sites->count() }} monitored</span>
        </div>

        @if ($sites->isEmpty())
            <div class="p-5 text-sm text-[var(--color-ink-muted)]">No sites being monitored. Verify your servers aren't ignored and that sites have <code class="font-data">uptime_monitoring_enabled = true</code>.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">
                        <tr class="border-b border-[var(--color-border-light)]">
                            <th class="text-left px-5 py-2">Site</th>
                            <th class="text-left px-5 py-2">State</th>
                            <th class="text-left px-5 py-2">24h</th>
                            <th class="text-left px-5 py-2">7d</th>
                            <th class="text-left px-5 py-2">30d</th>
                            <th class="text-left px-5 py-2">Last event</th>
                            <th class="text-left px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            @php
                                $state = $site->uptime_state;
                                $statePill = match ($state) {
                                    'up' => 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)]',
                                    'down' => 'bg-[var(--color-status-red)]/15 text-[var(--color-status-red)]',
                                    default => 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]',
                                };
                                $stateLabel = strtoupper($state ?? 'unknown');
                                $u24 = $stats24h[$site->id] ?? null;
                                $u7d = $stats7d[$site->id] ?? null;
                                $u30 = $stats30d[$site->id] ?? null;
                                $upClass = fn ($v) => $v === null ? 'text-[var(--color-ink-muted)]'
                                    : ($v >= 99.9 ? 'text-[var(--color-status-green)]'
                                    : ($v >= 99.0 ? 'text-[var(--color-status-yellow)]'
                                    : 'text-[var(--color-status-red)]'));
                                $upFmt = fn ($v) => $v === null ? '—' : number_format($v, 2).'%';
                                $lastEventAt = $state === 'down' ? $site->uptime_down_since : $site->uptime_last_up_at;
                            @endphp
                            <tr class="border-b border-[var(--color-border-light)] hover:bg-[var(--color-surface-alt)] {{ $site->isUptimeIgnored() ? 'opacity-60' : '' }}">
                                <td class="px-5 py-2">
                                    <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                                    <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $site->server?->name ?? '—' }}</div>
                                </td>
                                <td class="px-5 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold font-data {{ $statePill }}">{{ $stateLabel }}</span>
                                    @if ($site->isUptimeIgnored())
                                        <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-yellow)]/15 text-[var(--color-status-yellow)]" title="Alerts ignored">
                                            <i class="fa-solid fa-bell-slash"></i> ignored
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 font-data {{ $upClass($u24) }}">{{ $upFmt($u24) }}</td>
                                <td class="px-5 py-2 font-data {{ $upClass($u7d) }}">{{ $upFmt($u7d) }}</td>
                                <td class="px-5 py-2 font-data {{ $upClass($u30) }}">{{ $upFmt($u30) }}</td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">
                                    @if ($state === 'down' && $site->uptime_down_since)
                                        Down for {{ $site->uptime_down_since->diffForHumans(['parts' => 2, 'short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}
                                    @elseif ($state === 'up' && $site->uptime_last_up_at)
                                        Up — checked {{ $site->uptime_last_checked_at?->diffForHumans() ?? 'never' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-right">
                                    <a href="{{ route('sites.show', $site) }}" class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">Details →</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Recent events feed --}}
    <div class="card">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-soft)] mr-1"></i>
                Latest events
            </h2>
        </div>
        @if ($recentEvents->isEmpty())
            <div class="p-5 text-sm text-[var(--color-ink-muted)]">No transition events recorded yet. Down/up transitions will appear here as the probe runs.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">
                        <tr class="border-b border-[var(--color-border-light)]">
                            <th class="text-left px-5 py-2 w-24">Type</th>
                            <th class="text-left px-5 py-2">Site</th>
                            <th class="text-left px-5 py-2">Detail</th>
                            <th class="text-left px-5 py-2">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentEvents as $event)
                            @php
                                $isUp = $event->event_type === 'up';
                                $arrow = $isUp ? '↑' : '↓';
                                $arrowClass = $isUp ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]';
                            @endphp
                            <tr class="border-b border-[var(--color-border-light)]">
                                <td class="px-5 py-2 font-data font-semibold {{ $arrowClass }}">{{ $arrow }} {{ ucfirst($event->event_type) }}</td>
                                <td class="px-5 py-2">
                                    <a href="{{ route('sites.show', $event->site_id) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $event->site?->domain ?? "Site #{$event->site_id}" }}</a>
                                </td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">
                                    @if ($isUp)
                                        Everything is OK{{ $event->status_code ? " · HTTP {$event->status_code}" : '' }}
                                    @else
                                        {{ $event->error ?: ($event->status_code ? "HTTP {$event->status_code}" : 'unreachable') }}
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $event->event_at->diffForHumans() }} <span class="text-[10px]">({{ $event->event_at->format('M j, H:i') }})</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
