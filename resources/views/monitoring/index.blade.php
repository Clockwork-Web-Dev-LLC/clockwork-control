@extends('layouts.app')

@section('title', 'Monitoring · Clockwork')

@section('content')
<div id="monitoring-page-root" x-data="monitoringPage()" class="relative">
    <x-page-header title="Monitoring"
        subtitle="Fleet-wide uptime activity. Probes every monitored site on a schedule and alerts on transitions.">
        <x-slot:actions>
            {{-- WordPress-Style Screen Options Tab Button --}}
            <button type="button"
                    @click="screenOptionsOpen = !screenOptionsOpen"
                    :class="screenOptionsOpen ? 'bg-[var(--color-brand)] text-white border-[var(--color-brand)] shadow-xs' : ''"
                    class="btn-pill-nav inline-flex items-center gap-1.5 cursor-pointer text-xs md:text-sm font-medium transition-all"
                    title="Customize monitoring pagination and display settings">
                <i class="fa-solid fa-sliders text-xs" :class="screenOptionsOpen ? 'text-white' : 'text-[var(--color-brand)]'"></i>
                <span>Screen Options</span>
                <i class="fa-solid fa-chevron-down text-[10px] opacity-70 transition-transform duration-200"
                   :class="screenOptionsOpen ? 'rotate-180' : ''"></i>
            </button>

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

    {{-- WORDPRESS-STYLE SCREEN OPTIONS DRAWER --}}
    <div x-show="screenOptionsOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         x-cloak
         class="mb-6 rounded-[var(--radius-card)] border-2 border-[var(--color-brand)]/40 bg-[var(--color-surface)] shadow-xl overflow-hidden">
        {{-- Screen Options Top Control Bar --}}
        <div class="px-5 py-3.5 bg-[var(--color-surface-alt)]/80 border-b border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-2.5">
                <span class="w-2.5 h-2.5 rounded-full bg-[var(--color-brand)]"></span>
                <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-strong)]">Screen Options: Monitoring Display</span>
                <span class="text-xs text-[var(--color-ink-muted)] hidden sm:inline">— Customize table pagination and display settings. Saved in your browser.</span>
            </div>
            <div class="flex items-center gap-2 text-xs flex-wrap">
                <span class="text-[var(--color-ink-soft)] font-medium">Presets:</span>
                <button type="button"
                        @click="setPerPage(25)"
                        :class="perPage === 25 ? 'bg-[var(--color-brand)] text-white border-[var(--color-brand)]' : 'bg-[var(--color-surface)] border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:border-[var(--color-brand)]'"
                        class="px-2 py-1 rounded border transition-all cursor-pointer font-medium">
                    25
                </button>
                <button type="button"
                        @click="setPerPage(50)"
                        :class="perPage === 50 ? 'bg-[var(--color-brand)] text-white border-[var(--color-brand)]' : 'bg-[var(--color-surface)] border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:border-[var(--color-brand)]'"
                        class="px-2 py-1 rounded border transition-all cursor-pointer font-medium">
                    50 (Default)
                </button>
                <button type="button"
                        @click="setPerPage(100)"
                        :class="perPage === 100 ? 'bg-[var(--color-brand)] text-white border-[var(--color-brand)]' : 'bg-[var(--color-surface)] border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:border-[var(--color-brand)]'"
                        class="px-2 py-1 rounded border transition-all cursor-pointer font-medium">
                    100
                </button>
                <button type="button"
                        @click="setPerPage('all')"
                        :class="perPage === 'all' ? 'bg-[var(--color-brand)] text-white border-[var(--color-brand)]' : 'bg-[var(--color-surface)] border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:border-[var(--color-brand)]'"
                        class="px-2 py-1 rounded border transition-all cursor-pointer font-medium">
                    All
                </button>
                <button type="button"
                        @click="resetScreenOptions()"
                        class="px-2 py-1 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)] hover:border-amber-500 text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] transition-all cursor-pointer font-medium ml-2">
                    Reset
                </button>
            </div>
        </div>

        {{-- Screen Options Body --}}
        <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
            <div>
                <h4 class="font-semibold text-xs text-[var(--color-ink-strong)] uppercase tracking-wider mb-2 flex items-center gap-1.5">
                    <i class="fa-solid fa-table-list text-[var(--color-brand)]"></i>
                    Pagination
                </h4>
                <p class="text-[var(--color-ink-muted)] mb-3 leading-relaxed">
                    Set how many monitored sites to display on each page. Defaults to 50 sites per page for optimal scanning and performance.
                </p>
                <form @submit.prevent="applyPerPage()" class="flex items-center gap-2">
                    <label for="screen-options-per-page" class="text-[var(--color-ink-strong)] font-medium">
                        Number of sites per page:
                    </label>
                    <input type="number"
                           id="screen-options-per-page"
                           min="1"
                           max="500"
                           x-model.number="perPageInput"
                           class="w-20 px-2.5 py-1 text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] font-data focus:outline-none focus:border-[var(--color-brand)]">
                    <button type="submit"
                            class="btn-primary text-xs py-1 px-3 cursor-pointer">
                        Apply
                    </button>
                </form>
            </div>
            <div>
                <h4 class="font-semibold text-xs text-[var(--color-ink-strong)] uppercase tracking-wider mb-2 flex items-center gap-1.5">
                    <i class="fa-solid fa-circle-info text-[var(--color-brand)]"></i>
                    Display Notes
                </h4>
                <ul class="space-y-1.5 text-[var(--color-ink-muted)]">
                    <li class="flex items-start gap-1.5">
                        <i class="fa-solid fa-check text-[var(--color-status-green)] text-[10px] mt-0.5"></i>
                        <span>Fleet totals and 7d/30d uptime averages always reflect all monitored sites regardless of page size.</span>
                    </li>
                    <li class="flex items-start gap-1.5">
                        <i class="fa-solid fa-check text-[var(--color-status-green)] text-[10px] mt-0.5"></i>
                        <span>Instant search matches across all sites; pagination dynamically re-indexes matching results.</span>
                    </li>
                    <li class="flex items-start gap-1.5">
                        <i class="fa-solid fa-check text-[var(--color-status-green)] text-[10px] mt-0.5"></i>
                        <span>Per-site instant re-checks update live without reloading or changing your active page.</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

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
                    @if ($schedulerHeartbeat->isOk())
                        cron is running <code class="font-data">php artisan schedule:run</code> on schedule — uptime numbers on this page are current.
                    @else
                        crontab should spawn <code class="font-data">php artisan schedule:run</code> every minute.
                        A missing tick means this page’s uptime numbers will drift.
                        <a href="{{ route('docs.show', 'runbooks/scheduler-stuck') }}" class="text-[var(--color-primary-600)] hover:underline">Runbook</a>
                    @endif
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
            <div class="rounded-full border-4 {{ $heroColor }} w-16 h-16 flex items-center justify-center font-display font-bold text-[11px] leading-tight text-center px-1 shrink-0 {{ $currentlyNotOurFault > 0 ? 'cursor-pointer hover:opacity-90' : '' }}"
                 @if ($currentlyNotOurFault > 0) @click="filterSearch('not our fault')" onclick="window.monitoringFilterSearch('not our fault')" title="Click to filter by excused / not our fault sites" @endif>
                {{ $heroLabel }}
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-2">
                @if ($currentlyNotOurFault > 0 && $currentlyDown === 0)
                    <button type="button" @click="filterSearch('not our fault')" onclick="window.monitoringFilterSearch('not our fault')" class="text-amber-600 dark:text-amber-400 font-medium hover:underline cursor-pointer">Infrastructure OK ({{ $currentlyNotOurFault }} excused)</button> ·
                @endif
                {{ $sites->count() }} sites monitored
            </p>
        </div>

        <div class="card p-5">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Currently up</div>
            <div id="monitoring-stat-up" class="text-3xl font-bold font-data text-[var(--color-status-green)]">{{ $currentlyUp }}</div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">of {{ $sites->count() }} sites</div>
        </div>

        <div class="card p-5">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Currently down</div>
            <div id="monitoring-stat-down" class="text-3xl font-bold font-data {{ $currentlyDown > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }}">{{ $currentlyDown }}</div>
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
                x-model="searchQuery"
                @input="onSearchInput()"
                value="{{ request('q', '') }}"
                placeholder="Search monitored sites (domain, server, state)…"
                autocomplete="off"
                class="w-full pl-11 pr-10 py-3 rounded-full border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-base text-[var(--color-ink-strong)]"
            >
            <button type="button"
                    id="monitoring-search-clear"
                    x-show="searchQuery.trim().length > 0"
                    @click="clearSearch()"
                    x-cloak
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] w-6 h-6 rounded-full flex items-center justify-center cursor-pointer"
                    aria-label="Clear search">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>

    {{-- Per-site uptime table --}}
    <div class="card mb-6" id="monitoring-sites-card">
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
                                <td class="px-5 py-2 site-state-cell">
                                    @if ($isNotOurFault)
                                        <button type="button"
                                                @click="openModal({{ $site->id }}, '{{ addslashes($site->domain) }}', '{{ $exemptionReason }}', '{{ addslashes($site->uptime_ignore_reason ?? '') }}')"
                                                onclick="window.monitoringOpenClassify({{ $site->id }}, '{{ addslashes($site->domain) }}', '{{ $exemptionReason }}', '{{ addslashes($site->uptime_ignore_reason ?? '') }}')"
                                                class="classify-btn px-2 py-0.5 rounded-full text-xs font-semibold font-data inline-flex items-center gap-1 {{ $statePill }} hover:opacity-85 transition-opacity cursor-pointer text-left"
                                                title="External downtime ({{ $reasonLabel }}) — click to view or edit exemption details">
                                            <i class="fa-solid fa-shield-halved text-[10px]"></i>
                                            {{ $stateLabel }}
                                        </button>
                                    @elseif ($isDown)
                                        <button type="button"
                                                @click="openModal({{ $site->id }}, '{{ addslashes($site->domain) }}')"
                                                onclick="window.monitoringOpenClassify({{ $site->id }}, '{{ addslashes($site->domain) }}')"
                                                class="classify-btn px-2 py-0.5 rounded-full text-xs font-semibold font-data inline-flex items-center gap-1 {{ $statePill }} hover:opacity-85 transition-opacity cursor-pointer text-left"
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
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] site-last-event-cell">
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
                                <td class="px-5 py-2 text-right whitespace-nowrap site-actions-cell">
                                    <button type="button"
                                            class="monitoring-recheck-btn btn-pill-nav text-[11px] py-1 px-2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] mr-1.5 cursor-pointer inline-flex items-center justify-center"
                                            data-url="{{ route('sites.uptime.recheck', $site) }}"
                                            data-domain="{{ $site->domain }}"
                                            title="Run instant uptime probe for {{ $site->domain }}"
                                            aria-label="Re-check uptime for {{ $site->domain }}">
                                        <i class="fa-solid fa-rotate text-[10px]"></i>
                                    </button>
                                    @if ($isDown)
                                        @if ($isNotOurFault)
                                            <button type="button"
                                                    @click="openModal({{ $site->id }}, '{{ addslashes($site->domain) }}', '{{ $exemptionReason }}', '{{ addslashes($site->uptime_ignore_reason ?? '') }}')"
                                                    onclick="window.monitoringOpenClassify({{ $site->id }}, '{{ addslashes($site->domain) }}', '{{ $exemptionReason }}', '{{ addslashes($site->uptime_ignore_reason ?? '') }}')"
                                                    class="classify-btn btn-pill-nav text-[11px] py-0.5 px-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/30 mr-1.5 cursor-pointer"
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
                                                    class="classify-btn btn-pill-nav text-[11px] py-0.5 px-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/30 mr-2 cursor-pointer"
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

            {{-- Pagination footer bar --}}
            <div x-show="totalSites > 0"
                 x-cloak
                 class="px-5 py-3 border-t border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3 text-xs"
                 id="monitoring-pagination">
                <div class="text-[var(--color-ink-muted)]">
                    <template x-if="searchQuery.trim()">
                        <span>
                            Showing <strong class="font-data text-[var(--color-ink-strong)]" x-text="pageStart"></strong> to <strong class="font-data text-[var(--color-ink-strong)]" x-text="pageEnd"></strong> of <strong class="font-data text-[var(--color-ink-strong)]" x-text="totalFilteredSites"></strong> matching sites
                            <span class="text-[var(--color-ink-soft)] font-data">(of <span x-text="totalSites"></span> total)</span>
                        </span>
                    </template>
                    <template x-if="!searchQuery.trim()">
                        <span x-show="perPage === 'all' || totalSites <= perPage">
                            Showing all <strong class="font-data text-[var(--color-ink-strong)]" x-text="totalSites"></strong> monitored sites
                        </span>
                    </template>
                    <template x-if="!searchQuery.trim() && perPage !== 'all' && totalSites > perPage">
                        <span>
                            Showing <strong class="font-data text-[var(--color-ink-strong)]" x-text="pageStart"></strong> to <strong class="font-data text-[var(--color-ink-strong)]" x-text="pageEnd"></strong> of <strong class="font-data text-[var(--color-ink-strong)]" x-text="totalSites"></strong> monitored sites
                        </span>
                    </template>
                </div>

                {{-- Pagination controls --}}
                <div x-show="totalPages > 1" class="flex items-center gap-1">
                    <button type="button"
                            @click="prevPage()"
                            :disabled="currentPage <= 1"
                            class="btn-pill-nav text-xs py-1 px-2.5 flex items-center gap-1 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                            aria-label="Previous page">
                        <i class="fa-solid fa-chevron-left text-[10px]"></i>
                        <span>Prev</span>
                    </button>

                    <div class="flex items-center gap-1 px-1">
                        <template x-for="(p, idx) in pagesList" :key="idx">
                            <span>
                                <template x-if="p === '...'">
                                    <span class="px-2 py-1 text-[var(--color-ink-soft)] font-data">…</span>
                                </template>
                                <template x-if="p !== '...'">
                                    <button type="button"
                                            @click="goToPage(p)"
                                            :class="currentPage === p ? 'bg-[var(--color-brand)] text-white font-bold border-[var(--color-brand)] shadow-xs' : 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] hover:border-[var(--color-brand)] border-[var(--color-border-light)]'"
                                            class="min-w-7 h-7 px-2 rounded border text-xs font-data flex items-center justify-center transition-all cursor-pointer"
                                            x-text="p">
                                    </button>
                                </template>
                            </span>
                        </template>
                    </div>

                    <button type="button"
                            @click="nextPage()"
                            :disabled="currentPage >= totalPages"
                            class="btn-pill-nav text-xs py-1 px-2.5 flex items-center gap-1 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                            aria-label="Next page">
                        <span>Next</span>
                        <i class="fa-solid fa-chevron-right text-[10px]"></i>
                    </button>
                </div>
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
@endsection
