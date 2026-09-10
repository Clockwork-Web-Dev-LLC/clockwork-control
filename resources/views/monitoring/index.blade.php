@extends('layouts.app')

@section('title', 'Monitoring · Clockwork')

@section('content')
<div id="monitoring-page-root" x-data="monitoringPage()" class="relative">
    <x-page-header title="Monitoring"
        subtitle="Fleet-wide uptime activity. Probes every monitored site on a schedule and alerts on transitions.">
        <x-slot:actions>
            <form method="POST" action="{{ route('monitoring.refresh') }}" class="inline">
                @csrf
                <button type="submit" class="btn-pill-nav"
                        title="Re-probe every monitored site now. Same command the cron runs. Runs in the background (~2–3 min for ~150 sites)."
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

    @isset($schedulerHeartbeat)
        <div class="card p-4 mb-6 flex items-start gap-3 {{ $schedulerHeartbeat->isOk() ? '' : ($schedulerHeartbeat->isStale() ? 'border-l-4 border-[var(--color-status-red)]' : 'border-l-4 border-[var(--color-status-yellow)]') }}">
            <i class="fa-solid fa-clock mt-0.5 {{ $schedulerHeartbeat->isOk() ? 'text-[var(--color-status-green)]' : ($schedulerHeartbeat->isStale() ? 'text-[var(--color-status-red)]' : 'text-[var(--color-status-yellow)]') }}"></i>
            <div class="text-sm">
                <div class="font-medium text-[var(--color-ink-strong)]">
                    Scheduler heartbeat
                    @if ($schedulerHeartbeat->isOk())
                        <span class="text-[var(--color-status-green)] font-data">· {{ $schedulerHeartbeat->ageLabel() }}</span>
                    @elseif ($schedulerHeartbeat->isStale())
                        <span class="text-[var(--color-status-red)] font-data">· stale {{ $schedulerHeartbeat->ageLabel() }}</span>
                    @else
                        <span class="text-[var(--color-status-yellow)] font-data">· never ticked</span>
                    @endif
                </div>
                <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                    crontab should spawn <code class="font-data">php artisan schedule:run</code> every minute.
                    A missing tick means this page’s uptime numbers will drift.
                    <a href="{{ route('docs.show', 'runbooks/scheduler-stuck') }}" class="text-[var(--color-primary-600)] hover:underline">Runbook</a>
                </p>
            </div>
        </div>
    @endisset

    {{-- Hero --}}
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div class="card p-5 md:col-span-1 flex flex-col items-center justify-center text-center">
            @php
                $heroState = $currentlyDown > 0 ? 'down'
                    : ($currentlyNotOurFault > 0 ? 'not_our_fault'
                    : ($currentlyMaintenance > 0 ? 'maintenance'
                    : ($unknown === $sites->count() && $sites->count() > 0 ? 'unknown' : 'up')));
                $heroColor = match ($heroState) {
                    'down' => 'border-[var(--color-status-red)] text-[var(--color-status-red)]',
                    'not_our_fault' => 'border-amber-500 text-amber-600 dark:text-amber-400',
                    'maintenance' => 'border-[var(--color-primary-500)] text-[var(--color-primary-600)]',
                    'unknown' => 'border-[var(--color-ink-soft)] text-[var(--color-ink-muted)]',
                    default => 'border-[var(--color-status-green)] text-[var(--color-status-green)]',
                };
                $heroLabel = match ($heroState) {
                    'down' => "{$currentlyDown} DOWN",
                    'not_our_fault' => "{$currentlyNotOurFault} EXCUSED",
                    'maintenance' => "{$currentlyMaintenance} MAINT",
                    'unknown' => 'PENDING',
                    default => 'ALL UP',
                };
            @endphp
            <div class="rounded-full border-8 {{ $heroColor }} w-32 h-32 flex items-center justify-center font-display font-bold text-lg text-center px-2 {{ $currentlyNotOurFault > 0 ? 'cursor-pointer hover:opacity-90' : '' }}"
                 @if ($currentlyNotOurFault > 0) @click="filterSearch('not our fault')" onclick="window.monitoringFilterSearch('not our fault')" title="Click to filter by excused / not our fault sites" @endif>
                {{ $heroLabel }}
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-3">
                @if ($currentlyNotOurFault > 0 && $currentlyDown === 0)
                    <button type="button" @click="filterSearch('not our fault')" onclick="window.monitoringFilterSearch('not our fault')" class="text-amber-600 dark:text-amber-400 font-medium hover:underline cursor-pointer">Infrastructure OK ({{ $currentlyNotOurFault }} excused)</button> ·
                @endif
                {{ $sites->count() }} sites monitored
            </p>
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
                @if ($currentlyNotOurFault > 0)
                    · <button type="button" @click="filterSearch('not our fault')" onclick="window.monitoringFilterSearch('not our fault')" class="text-amber-600 dark:text-amber-400 font-medium hover:underline cursor-pointer inline-flex items-center gap-1" title="Click to filter table: external downtime excluded from SLA (e.g. client DNS)"><i class="fa-solid fa-shield-halved"></i> {{ $currentlyNotOurFault }} not our fault</button>
                @endif
                @if ($currentlyMaintenance > 0)
                    · <button type="button" @click="filterSearch('maint')" onclick="window.monitoringFilterSearch('maint')" class="text-[var(--color-primary-600)] font-medium hover:underline cursor-pointer inline-flex items-center gap-1" title="Click to filter maintenance sites"><i class="fa-solid fa-wrench"></i> {{ $currentlyMaintenance }} maint</button>
                @endif
                @if ($currentlyIgnored > 0)
                    · <button type="button" @click="filterSearch('ignored')" onclick="window.monitoringFilterSearch('ignored')" class="text-[var(--color-status-yellow)] hover:underline cursor-pointer inline-flex items-center gap-1" title="Click to filter ignored sites"><i class="fa-solid fa-bell-slash"></i> {{ $currentlyIgnored }} ignored</button>
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

    {{-- Search bar --}}
    <div class="mb-6">
        <div class="relative">
            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)]"></i>
            <input
                type="search"
                id="monitoring-search"
                value="{{ request('q', '') }}"
                placeholder="Search monitored sites (domain, server, state)…"
                autocomplete="off"
                class="w-full pl-11 pr-10 py-3 rounded-full border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-base text-[var(--color-ink-strong)]"
            >
            <button type="button" id="monitoring-search-clear" class="{{ request('q') ? '' : 'hidden' }} absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] w-6 h-6 rounded-full flex items-center justify-center" aria-label="Clear search">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>

    {{-- Per-site uptime table --}}
    <div class="card mb-6">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between gap-3">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-list text-[var(--color-ink-soft)] mr-1"></i>
                Sites
            </h2>
            <span class="text-xs text-[var(--color-ink-muted)]" id="monitoring-sites-count">{{ $sites->count() }} monitored</span>
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
                        <tr id="monitoring-no-match" class="hidden">
                            <td colspan="7" class="p-8 text-center text-[var(--color-ink-soft)]">
                                No monitored sites match "<span id="monitoring-no-match-query"></span>".
                            </td>
                        </tr>
                        @foreach ($sites as $site)
                            @php
                                $state = $site->uptime_state;
                                $isDown = $state === 'down';
                                $latestDown = $isDown ? ($latestDownEvents->get($site->id) ?? null) : null;
                                $isNotOurFault = $isDown && ($site->uptime_sla_exempt || (bool) $latestDown?->is_sla_exempt);
                                $exemptionReason = $site->uptime_exemption_reason ?: $latestDown?->exemption_reason;
                                $reasonLabel = $exemptionReason ? (\App\Models\SiteUptimeEvent::EXEMPTION_REASONS[$exemptionReason] ?? $exemptionReason) : 'Not our fault';

                                $statePill = match (true) {
                                    $isNotOurFault => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30',
                                    $state === 'up' => 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)]',
                                    $state === 'down' => 'bg-[var(--color-status-red)]/15 text-[var(--color-status-red)]',
                                    $state === 'maintenance' => 'bg-[var(--color-primary-500)]/15 text-[var(--color-primary-600)]',
                                    default => 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]',
                                };
                                $stateLabel = match (true) {
                                    $isNotOurFault => 'NOT OUR FAULT',
                                    $state === 'maintenance' => 'MAINT',
                                    default => strtoupper($state ?? 'unknown'),
                                };
                                $u24 = $stats24h[$site->id] ?? null;
                                $u7d = $stats7d[$site->id] ?? null;
                                $u30 = $stats30d[$site->id] ?? null;
                                $upClass = fn ($v) => $v === null ? 'text-[var(--color-ink-muted)]'
                                    : ($v >= 99.9 ? 'text-[var(--color-status-green)]'
                                    : ($v >= 99.0 ? 'text-[var(--color-status-yellow)]'
                                    : 'text-[var(--color-status-red)]'));
                                $upFmt = fn ($v) => $v === null ? '—' : number_format($v, 2).'%';
                                $lastEventAt = match ($state) {
                                    'down' => $site->uptime_down_since,
                                    'maintenance' => $site->uptime_maintenance_since,
                                    default => $site->uptime_last_up_at,
                                };
                            @endphp
                            <tr class="site-row border-b border-[var(--color-border-light)] hover:bg-[var(--color-surface-alt)] {{ $site->isUptimeIgnored() && ! $isNotOurFault ? 'opacity-60' : '' }}"
                                data-search="{{ strtolower($site->domain . ' ' . ($site->server?->name ?? '') . ' ' . $site->uptime_state . ($isNotOurFault ? ' not our fault sla exempt ' . $reasonLabel : '') . ($site->isUptimeIgnored() ? ' ignored' : '')) }}">
                                <td class="px-5 py-2">
                                    <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                                    <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $site->server?->name ?? '—' }}</div>
                                </td>
                                <td class="px-5 py-2">
                                    @if ($isNotOurFault)
                                        <button type="button"
                                                @click="openModal({{ $site->id }}, '{{ addslashes($site->domain) }}', '{{ $exemptionReason }}', '{{ addslashes($site->uptime_ignore_reason ?? '') }}')"
                                                onclick="window.monitoringOpenClassify({{ $site->id }}, '{{ addslashes($site->domain) }}', '{{ $exemptionReason }}', '{{ addslashes($site->uptime_ignore_reason ?? '') }}')"
                                                class="px-2 py-0.5 rounded-full text-xs font-semibold font-data inline-flex items-center gap-1 {{ $statePill }} hover:opacity-85 transition-opacity cursor-pointer text-left"
                                                title="External downtime ({{ $reasonLabel }}) — click to view or edit exemption details">
                                            <i class="fa-solid fa-shield-halved text-[10px]"></i>
                                            {{ $stateLabel }}
                                        </button>
                                    @elseif ($isDown)
                                        <button type="button"
                                                @click="openModal({{ $site->id }}, '{{ addslashes($site->domain) }}')"
                                                onclick="window.monitoringOpenClassify({{ $site->id }}, '{{ addslashes($site->domain) }}')"
                                                class="px-2 py-0.5 rounded-full text-xs font-semibold font-data inline-flex items-center gap-1 {{ $statePill }} hover:opacity-85 transition-opacity cursor-pointer text-left"
                                                title="Site is down — click to classify as Not Our Fault (client DNS, etc.)">
                                            {{ $stateLabel }}
                                        </button>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold font-data inline-flex items-center gap-1 {{ $statePill }}">
                                            @if ($state === 'maintenance')
                                                <i class="fa-solid fa-wrench text-[10px]"></i>
                                            @endif
                                            {{ $stateLabel }}
                                        </span>
                                    @endif
                                    @if ($isNotOurFault && $exemptionReason)
                                        <span class="ml-1 text-[10px] text-[var(--color-ink-muted)] font-data hidden xl:inline">({{ $reasonLabel }})</span>
                                    @endif
                                    @if ($site->isUptimeIgnored() && ! $isNotOurFault)
                                        <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-yellow)]/15 text-[var(--color-status-yellow)]" title="Alerts ignored">
                                            <i class="fa-solid fa-bell-slash"></i> ignored
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 font-data {{ $upClass($u24) }}">
                                    {{ $upFmt($u24) }}
                                    @if ($isNotOurFault)
                                        <span title="SLA protected (100% credited)" class="text-amber-500 text-[10px] ml-0.5 cursor-help"><i class="fa-solid fa-shield"></i></span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 font-data {{ $upClass($u7d) }}">
                                    {{ $upFmt($u7d) }}
                                    @if ($isNotOurFault)
                                        <span title="SLA protected" class="text-amber-500 text-[10px] ml-0.5 cursor-help"><i class="fa-solid fa-shield"></i></span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 font-data {{ $upClass($u30) }}">
                                    {{ $upFmt($u30) }}
                                    @if ($isNotOurFault)
                                        <span title="SLA protected" class="text-amber-500 text-[10px] ml-0.5 cursor-help"><i class="fa-solid fa-shield"></i></span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">
                                    @if ($state === 'down' && $site->uptime_down_since)
                                        Down for {{ $site->uptime_down_since->diffForHumans(['parts' => 2, 'short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}
                                    @elseif ($state === 'maintenance' && $site->uptime_maintenance_since)
                                        In maintenance {{ $site->uptime_maintenance_since->diffForHumans(['parts' => 2, 'short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}
                                    @elseif ($state === 'up' && $site->uptime_last_up_at)
                                        Up — checked {{ $site->uptime_last_checked_at?->diffForHumans() ?? 'never' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-right whitespace-nowrap">
                                    @if ($isDown)
                                        @if ($isNotOurFault)
                                            <button type="button"
                                                    @click="openModal({{ $site->id }}, '{{ addslashes($site->domain) }}', '{{ $exemptionReason }}', '{{ addslashes($site->uptime_ignore_reason ?? '') }}')"
                                                    onclick="window.monitoringOpenClassify({{ $site->id }}, '{{ addslashes($site->domain) }}', '{{ $exemptionReason }}', '{{ addslashes($site->uptime_ignore_reason ?? '') }}')"
                                                    class="btn-pill-nav text-[11px] py-0.5 px-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/30 mr-1.5 cursor-pointer"
                                                    title="Edit Not Our Fault exemption reason or notes">
                                                <i class="fa-solid fa-pen-to-square text-[10px] mr-1"></i> Edit
                                            </button>
                                            <form method="POST" action="{{ route('monitoring.sites.classify-outage', $site) }}" class="inline mr-2">
                                                @csrf
                                                <input type="hidden" name="is_sla_exempt" value="0">
                                                <button type="submit" class="btn-pill-nav text-[11px] py-0.5 px-2 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 cursor-pointer" title="Revert to Legit Outage (will count toward downtime & SLA)">
                                                    <i class="fa-solid fa-rotate-left text-[10px] mr-1"></i> Mark Legit
                                                </button>
                                            </form>
                                        @else
                                            <button type="button"
                                                    @click="openModal({{ $site->id }}, '{{ addslashes($site->domain) }}')"
                                                    onclick="window.monitoringOpenClassify({{ $site->id }}, '{{ addslashes($site->domain) }}')"
                                                    class="btn-pill-nav text-[11px] py-0.5 px-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/30 mr-2 cursor-pointer"
                                                    title="Mark as Not Our Fault (Client DNS, domain expired, etc. — excludes from SLA)">
                                                <i class="fa-solid fa-shield-halved text-[10px] mr-1"></i> Not our fault?
                                            </button>
                                        @endif
                                    @endif
                                    <a href="{{ route('sites.show', $site) }}" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]" title="Site details">
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Recent events table --}}
    <div class="card">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-soft)] mr-1"></i>
                Latest events
            </h2>
        </div>

        @if ($recentEvents->isEmpty())
            <div class="p-5 text-sm text-[var(--color-ink-muted)]">No transition events recorded yet. Events are logged when a site transitions between states.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-[var(--color-ink-muted)] uppercase tracking-wide text-[10px]">
                        <tr class="border-b border-[var(--color-border-light)]">
                            <th class="text-left px-5 py-2">Event</th>
                            <th class="text-left px-5 py-2">Site</th>
                            <th class="text-left px-5 py-2">Detail</th>
                            <th class="text-left px-5 py-2">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr id="monitoring-events-no-match" class="hidden">
                            <td colspan="4" class="p-5 text-center text-xs text-[var(--color-ink-soft)]">
                                No recent events match this search.
                            </td>
                        </tr>
                        @foreach ($recentEvents as $event)
                            @php
                                $isUp = $event->event_type === 'up';
                                $isMaint = $event->event_type === 'maintenance';
                                $isDown = $event->event_type === 'down';
                                $isExempt = $event->is_sla_exempt;
                                $arrow = $isUp ? '↑' : ($isMaint ? '🔧' : '↓');
                                $arrowClass = $isUp
                                    ? 'text-[var(--color-status-green)]'
                                    : ($isMaint ? 'text-[var(--color-primary-600)]' : ($isExempt ? 'text-amber-600 dark:text-amber-400' : 'text-[var(--color-status-red)]'));
                            @endphp
                            <tr class="event-row border-b border-[var(--color-border-light)] hover:bg-[var(--color-surface-alt)]"
                                data-search="{{ strtolower(($event->site?->domain ?? '') . ' ' . $event->event_type . ' ' . ($isExempt ? 'not our fault exempt ' . ($event->exemptionReasonLabel() ?? '') : '') . ' ' . ($event->error ?? '')) }}">
                                <td class="px-5 py-2 font-data font-semibold {{ $arrowClass }}">
                                    {{ $arrow }} {{ ucfirst($event->event_type) }}
                                    @if ($isExempt)
                                        <button type="button"
                                                @click="filterSearch('not our fault')"
                                                onclick="window.monitoringFilterSearch('not our fault')"
                                                class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-500/15 text-amber-600 dark:text-amber-400 inline-flex items-center gap-1 hover:underline cursor-pointer"
                                                title="{{ $event->exemption_notes ?: $event->exemptionReasonLabel() }} (Click to filter table)">
                                            <i class="fa-solid fa-shield-halved text-[9px]"></i> Not Our Fault
                                        </button>
                                    @endif
                                </td>
                                <td class="px-5 py-2">
                                    <a href="{{ route('sites.show', $event->site_id) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $event->site?->domain ?? "Site #{$event->site_id}" }}</a>
                                </td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">
                                    @if ($isUp)
                                        Everything is OK{{ $event->status_code ? " · HTTP {$event->status_code}" : '' }}
                                    @elseif ($isMaint)
                                        {{ $event->error ?: 'Scheduled maintenance mode' }}
                                    @else
                                        {{ $event->error ?: ($event->status_code ? "HTTP {$event->status_code}" : 'unreachable') }}
                                        @if ($isExempt && $event->exemption_reason)
                                            <span class="text-amber-600 dark:text-amber-400 font-medium">({{ $event->exemptionReasonLabel() }})</span>
                                        @endif
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

    {{-- Outage Classification Modal --}}
    <div x-show="modalOpen"
         x-cloak
         @keydown.escape.window="closeModal()"
         id="classify-outage-modal"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs transition-opacity"
         style="display: none;"
         @click.self="closeModal()">
        <div class="card p-6 max-w-md w-full shadow-2xl relative bg-[var(--color-surface)] border border-[var(--color-border)]"
             @click.stop>
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-full bg-amber-500/15 text-amber-600 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)]">Classify Outage: Not Our Fault</h3>
                        <p class="text-xs text-[var(--color-ink-muted)] font-data" id="classify-outage-domain" x-text="domain"></p>
                    </div>
                </div>
                <button type="button" @click="closeModal()" onclick="window.monitoringCloseClassify()" id="classify-outage-close" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] text-sm cursor-pointer" aria-label="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <p class="text-xs text-[var(--color-ink-muted)] mb-4 leading-relaxed">
                Exclude this downtime from your agency's uptime rating and fleet SLA. Active downtime will be credited back, restoring 24h, 7d, and 30d scores.
            </p>

            <form id="classify-outage-form" method="POST" :action="actionUrl" class="space-y-4">
                @csrf
                <input type="hidden" name="is_sla_exempt" value="1">

                <div>
                    <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Outage Cause</label>
                    <select name="exemption_reason" id="classify-outage-reason" x-model="reason" class="w-full text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-2 text-[var(--color-ink-strong)] focus:outline-none focus:border-[var(--color-brand)]">
                        <option value="client_dns">Client DNS change (Nameservers / A record moved)</option>
                        <option value="domain_expired">Domain expired / Registrar hold</option>
                        <option value="third_party">Third-party / Upstream outage (Cloudflare, AWS, etc.)</option>
                        <option value="client_requested">Client requested shutdown / hold</option>
                        <option value="other">Other (not our fault)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Notes / Context (Optional)</label>
                    <input type="text" name="exemption_notes" id="classify-outage-notes" x-model="notes" placeholder="e.g. Client changed NS to GoDaddy"
                           class="w-full text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-2 text-[var(--color-ink-strong)] focus:outline-none focus:border-[var(--color-brand)]">
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-[var(--color-border-light)]">
                    <button type="button" @click="closeModal()" onclick="window.monitoringCloseClassify()" id="classify-outage-cancel" class="btn-pill-nav text-xs cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary text-xs flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-shield-halved text-[10px]"></i> Protect SLA & Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function monitoringPage() {
        return {
            modalOpen: false,
            siteId: null,
            domain: '',
            reason: 'client_dns',
            notes: '',
            actionUrl: '',
            openModal(id, dom, r, n) {
                this.siteId = id;
                this.domain = dom;
                this.reason = r || 'client_dns';
                this.notes = n || '';
                this.actionUrl = '/monitoring/sites/' + id + '/classify-outage';
                this.modalOpen = true;
                this.$nextTick(() => {
                    const select = document.getElementById('classify-outage-reason');
                    if (select) select.focus();
                });
            },
            closeModal() {
                this.modalOpen = false;
            },
            filterSearch(term) {
                window.monitoringFilterSearch(term);
            }
        };
    }

    window.monitoringFilterSearch = function (term) {
        const input = document.getElementById('monitoring-search');
        if (input) {
            input.value = term;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.scrollIntoView({ behavior: 'smooth', block: 'center' });
            input.focus();
        }
    };

    window.monitoringOpenClassify = function (siteId, domain, reason = 'client_dns', notes = '') {
        const root = document.getElementById('monitoring-page-root');
        if (root && root._x_dataStack && root._x_dataStack[0]) {
            root._x_dataStack[0].openModal(siteId, domain, reason, notes);
            return;
        }
        const modal = document.getElementById('classify-outage-modal');
        const form = document.getElementById('classify-outage-form');
        const domainEl = document.getElementById('classify-outage-domain');
        const reasonEl = document.getElementById('classify-outage-reason');
        const notesEl = document.getElementById('classify-outage-notes');
        if (form) form.action = `/monitoring/sites/${siteId}/classify-outage`;
        if (domainEl) domainEl.textContent = domain;
        if (reasonEl && reason) reasonEl.value = reason;
        if (notesEl) notesEl.value = notes || '';
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }
    };

    window.monitoringCloseClassify = function () {
        const root = document.getElementById('monitoring-page-root');
        if (root && root._x_dataStack && root._x_dataStack[0]) {
            root._x_dataStack[0].closeModal();
            return;
        }
        const modal = document.getElementById('classify-outage-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
    };

    (function () {
        const input = document.getElementById('monitoring-search');
        const clearBtn = document.getElementById('monitoring-search-clear');
        const siteRows = Array.from(document.querySelectorAll('.site-row'));
        const eventRows = Array.from(document.querySelectorAll('.event-row'));
        const countSpan = document.getElementById('monitoring-sites-count');
        const noMatchRow = document.getElementById('monitoring-no-match');
        const noMatchQuery = document.getElementById('monitoring-no-match-query');
        const eventsNoMatchRow = document.getElementById('monitoring-events-no-match');
        const totalSites = siteRows.length;

        function applyFilter(rawQ) {
            const q = (rawQ || '').trim().toLowerCase();
            const isQuery = q.length > 0;

            if (clearBtn) {
                clearBtn.classList.toggle('hidden', !isQuery);
            }

            let visibleSites = 0;
            siteRows.forEach(row => {
                const text = (row.dataset.search || '').toLowerCase();
                const matched = !isQuery || text.includes(q);
                row.style.display = matched ? '' : 'none';
                if (matched) visibleSites++;
            });

            if (countSpan) {
                countSpan.textContent = isQuery
                    ? `Showing ${visibleSites} of ${totalSites} monitored`
                    : `${totalSites} monitored`;
            }

            if (noMatchRow) {
                noMatchRow.classList.toggle('hidden', visibleSites > 0 || totalSites === 0);
                if (noMatchQuery) {
                    noMatchQuery.textContent = rawQ || '';
                }
            }

            let visibleEvents = 0;
            eventRows.forEach(row => {
                const text = (row.dataset.search || '').toLowerCase();
                const matched = !isQuery || text.includes(q);
                row.style.display = matched ? '' : 'none';
                if (matched) visibleEvents++;
            });

            if (eventsNoMatchRow) {
                eventsNoMatchRow.classList.toggle('hidden', visibleEvents > 0 || eventRows.length === 0 || !isQuery);
            }

            // Update URL query parameter smoothly without page reload
            const url = new URL(window.location.href);
            if (isQuery) {
                url.searchParams.set('q', rawQ.trim());
            } else {
                url.searchParams.delete('q');
            }
            window.history.replaceState(null, '', url.toString());
        }

        if (input) {
            input.addEventListener('input', e => applyFilter(e.target.value));

            input.addEventListener('keydown', e => {
                if (e.key === 'Escape') {
                    input.value = '';
                    applyFilter('');
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    const firstSite = document.querySelector('.site-row:not([style*="display: none"]) a');
                    if (firstSite) {
                        firstSite.click();
                    }
                }
            });

            // Initialize filter if query was passed in URL
            if (input.value) {
                applyFilter(input.value);
            }
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (input) {
                    input.value = '';
                    applyFilter('');
                    input.focus();
                }
            });
        }

        // "/" focuses search from anywhere on the page
        document.addEventListener('keydown', e => {
            if (e.key === '/' && input && document.activeElement !== input
                    && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
                e.preventDefault();
                input.focus();
                input.select();
            }
        });

        // Direct event listeners for cancel and close buttons (DOM fallback)
        const cancelBtn = document.getElementById('classify-outage-cancel');
        const closeBtn = document.getElementById('classify-outage-close');
        const modal = document.getElementById('classify-outage-modal');
        if (cancelBtn) cancelBtn.addEventListener('click', window.monitoringCloseClassify);
        if (closeBtn) closeBtn.addEventListener('click', window.monitoringCloseClassify);
        if (modal) {
            modal.addEventListener('click', e => {
                if (e.target === modal) window.monitoringCloseClassify();
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape' && modal.style.display !== 'none' && !modal.classList.contains('hidden')) {
                    window.monitoringCloseClassify();
                }
            });
        }
    })();
</script>
@endsection
