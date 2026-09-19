@extends('layouts.app')

@section('title', 'Capacity · Clockwork')

@section('content')
    @include('operations._tabs')

    <div x-data="{
        filter: (new URLSearchParams(window.location.search)).get('fleet') || (window.location.hash ? window.location.hash.replace('#', '') : 'all'),
        showFloating: false,
        setFilter(f) {
            this.filter = f;
            if (history.replaceState) {
                const url = new URL(window.location);
                if (f === 'all') {
                    url.searchParams.delete('fleet');
                } else {
                    url.searchParams.set('fleet', f);
                }
                history.replaceState(null, '', url);
            }
            if (f === 'pressable') {
                this.$nextTick(() => {
                    document.getElementById('pressable-capacity')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            } else if (f === 'shared') {
                this.$nextTick(() => {
                    document.getElementById('shared-vps-sections')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
        },
        scrollTo(id) {
            if (id === 'pressable-capacity' && this.filter === 'shared') {
                this.filter = 'all';
            }
            if ((id === 'over-quota' || id === 'pressure-headroom' || id === 'shared-vps-sections') && this.filter === 'pressable') {
                this.filter = 'all';
            }
            this.$nextTick(() => {
                const el = document.getElementById(id);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }
    }"
    @scroll.window="showFloating = (window.pageYOffset > 350)"
    class="relative">

    <x-page-header title="Capacity"
        subtitle="Fleet capacity, resource pressure, headroom, and visit-threshold overages across SpinupWP shared servers, Pressable cloud, and standalone sites.">
        <x-slot:actions>
            @if (! empty($pressableCapacity))
                <button type="button"
                        @click="scrollTo('pressable-capacity')"
                        class="btn-pill-nav text-sm font-medium text-[var(--color-primary-600)] hover:text-[var(--color-primary-700)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-hover)] border-[var(--color-border-light)] hover:border-[var(--color-primary-500)] flex items-center gap-1.5 transition-all shadow-xs cursor-pointer"
                        title="Jump directly down to Pressable Fleet Capacity">
                    <i class="fa-solid fa-cloud text-[var(--color-primary-600)]"></i>
                    <span>Pressable ({{ $pressableCapacity['dbSitesCount'] ?? 108 }})</span>
                    <i class="fa-solid fa-arrow-down text-[10px] opacity-70"></i>
                </button>
            @endif
            <button type="button"
                    @click="scrollTo('shared-vps-sections')"
                    class="btn-pill-nav text-sm font-medium text-[var(--color-ink-strong)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-hover)] border-[var(--color-border-light)] flex items-center gap-1.5 transition-all shadow-xs cursor-pointer"
                    title="Jump to SpinupWP Shared VPS servers section">
                <i class="fa-solid fa-server text-[var(--color-brand)]"></i>
                <span>Shared VPS</span>
                <i class="fa-solid fa-arrow-down text-[10px] opacity-70"></i>
            </button>
            <a href="{{ route('capacity.settings') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-sliders text-[var(--color-ink-muted)]"></i>
                <span>Capacity settings</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Fleet Filter & Quick Jump Bar --}}
    <div class="flex items-center justify-between flex-wrap gap-3 mb-8 p-3 rounded-xl bg-[var(--color-surface-alt)]/60 border border-[var(--color-border-light)]">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] font-semibold mr-1">
                <i class="fa-solid fa-filter mr-1 text-[10px]"></i> View:
            </span>

            <button type="button"
                    @click="setFilter('all')"
                    :class="filter === 'all' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)] border-[var(--color-border)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] border-transparent hover:bg-[var(--color-surface)]/50'"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs transition-all border cursor-pointer">
                <i class="fa-solid fa-layer-group text-[10px]"></i>
                <span>All Fleets</span>
            </button>

            <button type="button"
                    @click="setFilter('shared')"
                    :class="filter === 'shared' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)] border-[var(--color-border)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] border-transparent hover:bg-[var(--color-surface)]/50'"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs transition-all border cursor-pointer">
                <i class="fa-solid fa-server text-[10px] text-[var(--color-brand)]"></i>
                <span>SpinupWP / Shared VPS</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-[var(--color-border-light)] text-[var(--color-ink-soft)] font-mono">
                    {{ $pressure->count() + $headroom->count() }} servers
                </span>
            </button>

            @if (! empty($pressableCapacity))
                <button type="button"
                        @click="setFilter('pressable')"
                        :class="filter === 'pressable' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-primary-600)] border-[var(--color-primary-300)] dark:border-[var(--color-primary-700)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] border-transparent hover:bg-[var(--color-surface)]/50'"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs transition-all border cursor-pointer">
                    <i class="fa-solid fa-cloud text-[10px] text-[var(--color-primary-600)]"></i>
                    <span>Pressable Cloud</span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-[var(--color-primary-50)] text-[var(--color-primary-700)] dark:bg-[var(--color-primary-950)] dark:text-[var(--color-primary-300)] font-mono">
                        {{ $pressableCapacity['dbSitesCount'] ?? 108 }} sites
                    </span>
                </button>
            @endif

            <button type="button"
                    @click="setFilter('eol')"
                    :class="filter === 'eol' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)] border-[var(--color-border)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] border-transparent hover:bg-[var(--color-surface)]/50'"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs transition-all border cursor-pointer">
                <i class="fa-solid fa-clock-rotate-left text-[10px] text-[var(--color-ink-muted)]"></i>
                <span>Runtime EOL</span>
            </button>
        </div>

        {{-- Jump Shortcuts when in All Fleets mode --}}
        <div x-show="filter === 'all'" class="flex items-center gap-2 text-xs text-[var(--color-ink-soft)] ml-auto">
            <span class="text-[11px] uppercase tracking-wider font-semibold opacity-70">Jump to:</span>
            <button type="button" @click="scrollTo('over-quota')" class="hover:text-[var(--color-ink-strong)] hover:underline cursor-pointer">
                Shared Overages
            </button>
            <span class="opacity-30">&middot;</span>
            <button type="button" @click="scrollTo('pressure-headroom')" class="hover:text-[var(--color-ink-strong)] hover:underline cursor-pointer">
                Pressure &amp; Headroom
            </button>
            @if (! empty($pressableCapacity))
                <span class="opacity-30">&middot;</span>
                <button type="button"
                        @click="scrollTo('pressable-capacity')"
                        class="px-2 py-0.5 rounded-md bg-[var(--color-primary-50)] dark:bg-[var(--color-primary-950)] text-[var(--color-primary-700)] dark:text-[var(--color-primary-300)] font-medium hover:bg-[var(--color-primary-100)] transition-colors flex items-center gap-1 cursor-pointer">
                    <i class="fa-solid fa-cloud text-[10px]"></i>
                    <span>Pressable</span>
                    <i class="fa-solid fa-arrow-down text-[9px] opacity-70"></i>
                </button>
            @endif
            <span class="opacity-30">&middot;</span>
            <button type="button" @click="scrollTo('runtime-eol')" class="hover:text-[var(--color-ink-strong)] hover:underline cursor-pointer">
                Runtime EOL
            </button>
        </div>
    </div>

    @if ($sharedTagMissing)
        <div x-show="filter === 'all' || filter === 'shared'" class="card p-6 status-yellow mb-10">
            No <code>Shared</code> tag exists. Add one in
            <a href="{{ route('settings.tags.index') }}" class="hover:underline">Settings → Tags</a>
            and tag at least one server with it.
        </div>
    @else
        @php
            $pressureClass = function ($v) {
                if ($v === null) return 'text-[var(--color-ink-soft)]';
                if ($v >= 90) return 'text-[var(--color-status-red)] font-semibold';
                if ($v >= 70) return 'text-[var(--color-status-yellow)] font-semibold';
                return 'text-[var(--color-ink-strong)]';
            };
            $fmtPct = fn ($v) => $v === null ? '—' : number_format($v, 1) . '%';
        @endphp

        {{-- Shared VPS Sections Wrapper --}}
        <div id="shared-vps-sections" x-show="filter === 'all' || filter === 'shared'">
            <div x-show="filter === 'shared'" class="mb-6 px-4 py-2.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-xs flex items-center justify-between">
                <span class="text-[var(--color-ink-muted)]">
                    <i class="fa-solid fa-server text-[var(--color-brand)] mr-1.5"></i>
                    Showing <strong>SpinupWP / Shared VPS</strong> capacity only.
                </span>
                <button type="button" @click="setFilter('all')" class="text-[var(--color-primary-600)] hover:underline cursor-pointer">
                    View all fleets
                </button>
            </div>

            {{-- Over-quota — surface this first; it's the action item --}}
            <section id="over-quota" class="mb-10">
            <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
                <h2 class="font-display text-xl text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)] mr-1"></i>
                    Over visit threshold
                    <span class="text-sm font-normal text-[var(--color-ink-soft)] ml-1">
                        (calendar MTD &gt; {{ number_format($threshold) }} visits &middot;
                        <a href="{{ route('capacity.settings') }}" class="text-[var(--color-primary-600)] hover:underline text-xs">edit threshold</a>, Shared servers only)
                    </span>
                </h2>
                <div class="text-sm text-[var(--color-ink-soft)]">{{ $overQuota->count() }} site(s)</div>
            </div>
            <div class="card overflow-hidden">
                @if ($overQuota->isEmpty())
                    <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">
                        No shared-server sites are over the {{ $monthLabel }} MTD {{ number_format($threshold) }}-visit invoice threshold.
                        <div class="mt-1.5">
                            <a href="{{ route('capacity.settings') }}" class="text-xs text-[var(--color-primary-600)] hover:underline">
                                <i class="fa-solid fa-sliders text-[10px]"></i> Change threshold in Capacity settings
                            </a>
                        </div>
                    </div>
                @else
                    <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'rolling', defaultDir: 'desc' })">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <x-sort-th key="site" class="px-5 py-3">Site</x-sort-th>
                                <x-sort-th key="server" class="px-5 py-3">Server</x-sort-th>
                                <x-sort-th key="mtd" align="right" class="px-5 py-3" title="Calendar-month-to-date — the invoice tripwire">MTD ({{ $monthLabel }})</x-sort-th>
                                <x-sort-th key="overby" align="right" class="px-5 py-3">Over by</x-sort-th>
                                <x-sort-th key="pctover" align="right" class="px-5 py-3">% over</x-sort-th>
                                <x-sort-th key="rolling" align="right" class="px-5 py-3" title="Rolling {{ $rollingDays }}d — early-warning column, not the tripwire">Visits {{ $rollingDays }}d</x-sort-th>
                                <x-sort-th key="last7" align="right" class="px-5 py-3">Last 7 days</x-sort-th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($overQuota as $row)
                                <tr
                                    data-sort-site="{{ $row['site']->domain }}"
                                    data-sort-server="{{ $row['site']->server?->name ?? '' }}"
                                    data-sort-rolling="{{ $row['rolling_visits'] }}"
                                    data-sort-overby="{{ $row['over_by'] }}"
                                    data-sort-pctover="{{ $row['pct_over'] }}"
                                    data-sort-mtd="{{ $row['month_visits'] }}"
                                    data-sort-last7="{{ $row['last_7d_visits'] }}">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('sites.show', $row['site']) }}" class="font-data text-[var(--color-ink-strong)] hover:underline">
                                            @if ($row['site']->is_wordpress)
                                                <i class="fa-brands fa-wordpress text-[var(--color-brand)] mr-1"></i>
                                            @endif
                                            {{ $row['site']->domain }}
                                            @if ($row['site']->cloudflare_state === 'proxied')
                                                <i class="fa-solid fa-cloud text-orange-500 ml-1" title="Cloudflare proxied — traffic goes through CF edge before hitting origin"></i>
                                            @endif
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-[var(--color-ink-muted)]">
                                        @if ($row['site']->server)
                                            <a href="{{ route('servers.show', $row['site']->server) }}" class="hover:underline" title="{{ $row['site']->server->name }}">{{ $row['site']->server->display_name }}</a>
                                        @else — @endif
                                    </td>
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-ink-strong)]">{{ number_format($row['month_visits']) }}</td>
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-status-red)]">+{{ number_format($row['over_by']) }}</td>
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-status-red)]">+{{ number_format($row['pct_over'], 1) }}%</td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)]">{{ number_format($row['rolling_visits']) }}</td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)]">{{ number_format($row['last_7d_visits']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>

        {{-- Trending toward overage — sites NOT yet over calendar MTD
             but whose current month pace projects past the invoice
             threshold by month-end. Hidden when no sites match. --}}
        @if (! empty($trending) && $trending->isNotEmpty())
            <section id="trending-overage" class="mb-10">
                <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
                    <h2 class="font-display text-xl text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-arrow-trend-up text-[var(--color-status-yellow)] mr-1"></i>
                        Trending toward overage
                        <span class="text-sm font-normal text-[var(--color-ink-soft)] ml-1">
                            (MTD pace projects past {{ number_format($threshold) }} by month-end)
                        </span>
                    </h2>
                    <div class="text-sm text-[var(--color-ink-soft)]">{{ $trending->count() }} site(s)</div>
                </div>
                <div class="card overflow-hidden">
                    <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'projected', defaultDir: 'desc' })">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <x-sort-th key="site" class="px-5 py-3">Site</x-sort-th>
                                <x-sort-th key="server" class="px-5 py-3">Server</x-sort-th>
                                <x-sort-th key="mtd" align="right" class="px-5 py-3" title="Calendar month-to-date">MTD</x-sort-th>
                                <x-sort-th key="rolling" align="right" class="px-5 py-3" title="Rolling {{ $rollingDays }}d — early warning">Visits {{ $rollingDays }}d</x-sort-th>
                                <x-sort-th key="last7" align="right" class="px-5 py-3">Last {{ $trendingWindow }}d</x-sort-th>
                                <x-sort-th key="projected" align="right" class="px-5 py-3" title="If current MTD pace continues through month-end">Projected month-end</x-sort-th>
                                <x-sort-th key="projectedover" align="right" class="px-5 py-3">Projected over by</x-sort-th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($trending as $row)
                                <tr
                                    data-sort-site="{{ $row['site']->domain }}"
                                    data-sort-server="{{ $row['site']->server?->name ?? '' }}"
                                    data-sort-mtd="{{ $row['month_visits'] }}"
                                    data-sort-rolling="{{ $row['rolling_visits'] }}"
                                    data-sort-last7="{{ $row['last_7d_visits'] }}"
                                    data-sort-projected="{{ $row['projected_30d'] }}"
                                    data-sort-projectedover="{{ $row['projected_over_by'] }}">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('sites.show', $row['site']) }}" class="font-data text-[var(--color-ink-strong)] hover:underline">
                                            @if ($row['site']->is_wordpress)
                                                <i class="fa-brands fa-wordpress text-[var(--color-brand)] mr-1"></i>
                                            @endif
                                            {{ $row['site']->domain }}
                                            @if ($row['site']->cloudflare_state === 'proxied')
                                                <i class="fa-solid fa-cloud text-orange-500 ml-1" title="Cloudflare proxied — traffic goes through CF edge before hitting origin"></i>
                                            @endif
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-[var(--color-ink-muted)]">
                                        @if ($row['site']->server)
                                            <a href="{{ route('servers.show', $row['site']->server) }}" class="hover:underline" title="{{ $row['site']->server->name }}">{{ $row['site']->server->display_name }}</a>
                                        @else — @endif
                                    </td>
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-ink-strong)]">{{ number_format($row['month_visits']) }}</td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)]">{{ number_format($row['rolling_visits']) }}</td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)]">{{ number_format($row['last_7d_visits']) }}</td>
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-status-yellow)]">{{ number_format($row['projected_30d']) }}</td>
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-status-yellow)]">+{{ number_format($row['projected_over_by']) }} (+{{ number_format($row['projected_pct_over'], 1) }}%)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- Resource leaderboard: avg + peak CPU/memory per server, last 7d
             and 30d. Surfaces sustained-hot vs occasionally-spiky boxes
             that the 24h-window Pressure / Headroom split below doesn't
             distinguish. Reads from server_metrics (poll-servers cron). --}}
        @if (! empty($resourceLeaderboard) && $resourceLeaderboard->isNotEmpty())
            @php
                // Color thresholds match the Pressure section: yellow ≥ 70 cpu / 80 mem, red ≥ 90 cpu / 95 mem.
                $cpuClass = fn ($v) => $v === null
                    ? 'text-[var(--color-ink-soft)]'
                    : ($v >= 90 ? 'text-[var(--color-status-red)]' : ($v >= 70 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-ink-strong)]'));
                $memClass = fn ($v) => $v === null
                    ? 'text-[var(--color-ink-soft)]'
                    : ($v >= 95 ? 'text-[var(--color-status-red)]' : ($v >= 80 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-ink-strong)]'));
                $fmt = fn ($v) => $v === null ? '—' : number_format($v, 1) . '%';
            @endphp
            <section id="resource-leaderboard" class="mb-10">
                <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
                    <h2 class="font-display text-xl text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-gauge text-[var(--color-ink-muted)] mr-1"></i>
                        Resource leaderboard
                        <span class="text-sm font-normal text-[var(--color-ink-soft)] ml-1">
                            (avg + peak CPU/memory per server, 7d and 30d)
                        </span>
                    </h2>
                    <div class="text-sm text-[var(--color-ink-soft)]">{{ $resourceLeaderboard->count() }} server(s)</div>
                </div>
                <div class="card overflow-hidden">
                    @php
                        // Inline-SVG sparkline. Tiny, no library, no JS.
                        // Maps cpu_pct values (0-100) to a 100x24 SVG path.
                        $renderSpark = function (array $values, int $w = 100, int $h = 24): string {
                            $values = array_values(array_filter($values, fn ($v) => $v !== null && is_numeric($v)));
                            if (count($values) < 2) {
                                return '<svg width="'.$w.'" height="'.$h.'" aria-hidden="true"></svg>';
                            }
                            $n = count($values);
                            $pts = [];
                            foreach ($values as $i => $v) {
                                $x = $n === 1 ? 0 : ($i / ($n - 1)) * ($w - 2) + 1;
                                $y = $h - 2 - (max(0, min(100, $v)) / 100) * ($h - 4);
                                $pts[] = round($x, 1).','.round($y, 1);
                            }
                            $path = 'M '.implode(' L ', $pts);
                            $last = end($values);
                            $stroke = $last >= 90 ? 'var(--color-status-red)' : ($last >= 70 ? 'var(--color-status-yellow)' : 'var(--color-status-green)');
                            return sprintf(
                                '<svg width="%d" height="%d" viewBox="0 0 %d %d" aria-hidden="true">'
                                .'<path d="%s" fill="none" stroke="%s" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />'
                                .'</svg>',
                                $w, $h, $w, $h, $path, $stroke
                            );
                        };
                    @endphp
                    <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'cpu7avg', defaultDir: 'desc' })">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <x-sort-th key="server" class="px-5 py-3">Server</x-sort-th>
                                <th class="px-5 py-3 text-left" title="CPU over last 7 days, ~6h buckets">7d trend</th>
                                <x-sort-th key="cpu7avg"  align="right" class="px-5 py-3" title="Average CPU over last 7 days">CPU avg 7d</x-sort-th>
                                <x-sort-th key="cpu7peak" align="right" class="px-5 py-3" title="Peak CPU sample over last 7 days">CPU peak 7d</x-sort-th>
                                <x-sort-th key="cpu30avg" align="right" class="px-5 py-3" title="Average CPU over last 30 days">CPU avg 30d</x-sort-th>
                                <x-sort-th key="cpu30peak" align="right" class="px-5 py-3" title="Peak CPU sample over last 30 days">CPU peak 30d</x-sort-th>
                                <x-sort-th key="mem7avg"  align="right" class="px-5 py-3 border-l border-[var(--color-border-light)]" title="Average memory over last 7 days">MEM avg 7d</x-sort-th>
                                <x-sort-th key="mem7peak" align="right" class="px-5 py-3" title="Peak memory sample over last 7 days">MEM peak 7d</x-sort-th>
                                <x-sort-th key="mem30avg" align="right" class="px-5 py-3" title="Average memory over last 30 days">MEM avg 30d</x-sort-th>
                                <x-sort-th key="mem30peak" align="right" class="px-5 py-3" title="Peak memory sample over last 30 days">MEM peak 30d</x-sort-th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($resourceLeaderboard as $row)
                                <tr
                                    data-sort-server="{{ $row['server']->name }}"
                                    data-sort-cpu7avg="{{ $row['avg_cpu_7d']  ?? -1 }}"
                                    data-sort-cpu7peak="{{ $row['peak_cpu_7d'] ?? -1 }}"
                                    data-sort-cpu30avg="{{ $row['avg_cpu_30d']  ?? -1 }}"
                                    data-sort-cpu30peak="{{ $row['peak_cpu_30d'] ?? -1 }}"
                                    data-sort-mem7avg="{{ $row['avg_mem_7d']  ?? -1 }}"
                                    data-sort-mem7peak="{{ $row['peak_mem_7d'] ?? -1 }}"
                                    data-sort-mem30avg="{{ $row['avg_mem_30d']  ?? -1 }}"
                                    data-sort-mem30peak="{{ $row['peak_mem_30d'] ?? -1 }}">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('servers.show', $row['server']) }}" class="font-data text-[var(--color-ink-strong)] hover:underline">{{ $row['server']->name }}</a>
                                        @if ($row['samples_7d'] === 0)
                                            <span class="text-[10px] text-[var(--color-ink-soft)] ml-1" title="No metrics in last 7 days">no data</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3" title="Last 7d CPU, ~6h buckets · ends in current state color (green &lt;70%, yellow 70-90%, red ≥90%)">
                                        {!! $renderSpark($row['cpu_spark_7d']) !!}
                                    </td>
                                    <td class="px-5 py-3 text-right font-display {{ $cpuClass($row['avg_cpu_7d']) }}">{{ $fmt($row['avg_cpu_7d']) }}</td>
                                    <td class="px-5 py-3 text-right {{ $cpuClass($row['peak_cpu_7d']) }}">{{ $fmt($row['peak_cpu_7d']) }}</td>
                                    <td class="px-5 py-3 text-right {{ $cpuClass($row['avg_cpu_30d']) }}">{{ $fmt($row['avg_cpu_30d']) }}</td>
                                    <td class="px-5 py-3 text-right {{ $cpuClass($row['peak_cpu_30d']) }}">{{ $fmt($row['peak_cpu_30d']) }}</td>
                                    <td class="px-5 py-3 text-right font-display border-l border-[var(--color-border-light)] {{ $memClass($row['avg_mem_7d']) }}">{{ $fmt($row['avg_mem_7d']) }}</td>
                                    <td class="px-5 py-3 text-right {{ $memClass($row['peak_mem_7d']) }}">{{ $fmt($row['peak_mem_7d']) }}</td>
                                    <td class="px-5 py-3 text-right {{ $memClass($row['avg_mem_30d']) }}">{{ $fmt($row['avg_mem_30d']) }}</td>
                                    <td class="px-5 py-3 text-right {{ $memClass($row['peak_mem_30d']) }}">{{ $fmt($row['peak_mem_30d']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- Per-site CPU leaderboard. PHP CPU time + peak resident memory
             aggregated over the last 7 days, attributed to each site by
             Companion's per-request getrusage() sampler. Populated by
             clockwork:pull-site-metrics every 15 min. Operator can pause
             collection via the section header toggle — historical data
             remains visible while paused. --}}
        @if ((! empty($siteLeaderboard) && $siteLeaderboard->isNotEmpty()) || ! ($siteMetricsEnabled ?? true))
            @php
                $fmtBytes = function (int $bytes): string {
                    if ($bytes <= 0) return '—';
                    $units = ['B', 'KB', 'MB', 'GB'];
                    $i = 0;
                    $v = (float) $bytes;
                    while ($v >= 1024 && $i < count($units) - 1) {
                        $v /= 1024;
                        $i++;
                    }
                    return number_format($v, $v >= 100 ? 0 : 1) . ' ' . $units[$i];
                };
                $fmtSeconds = function (int $s): string {
                    if ($s < 60) return $s . ' s';
                    if ($s < 3600) return number_format($s / 60, 1) . ' min';
                    return number_format($s / 3600, 1) . ' hr';
                };
            @endphp
            <section id="site-leaderboard" class="mb-10">
                <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
                    <h2 class="font-display text-xl text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-chart-line text-[var(--color-ink-muted)] mr-1"></i>
                        Top sites by CPU (7d)
                        <span class="text-sm font-normal text-[var(--color-ink-soft)] ml-1">
                            (PHP CPU time per site, 7-day window)
                        </span>
                    </h2>
                    <div class="flex items-center gap-3">
                        @if (! empty($siteLeaderboard) && $siteLeaderboard->isNotEmpty())
                            <div class="text-sm text-[var(--color-ink-soft)]">
                                {{ $siteLeaderboard->count() }} site{{ $siteLeaderboard->count() === 1 ? '' : 's' }}
                            </div>
                        @endif
                        <form method="POST" action="{{ route('capacity.site-metrics.toggle') }}">
                            @csrf
                            @if ($siteMetricsEnabled ?? true)
                                <button type="submit"
                                        class="btn-pill-nav text-xs"
                                        title="Pause the 15-min ingest cron. Historical data stays visible; collection resumes when re-enabled."
                                        data-confirm="Pause per-site CPU collection?"
                                        data-confirm-details="Historical data will still be visible; the 15-min ingest will stop until re-enabled."
                                        data-confirm-btn="Pause Collection"
                                        data-confirm-variant="warning">
                                    <i class="fa-solid fa-pause text-[10px]"></i> Pause collection
                                </button>
                            @else
                                <button type="submit"
                                        class="btn-pill-nav text-xs"
                                        title="Resume the 15-min ingest cron — new data appears within ~15 minutes.">
                                    <i class="fa-solid fa-play text-[10px]"></i> Resume collection
                                </button>
                            @endif
                        </form>
                    </div>
                </div>

                @unless ($siteMetricsEnabled ?? true)
                    <div class="card p-4 mb-3 flex items-start gap-3 status-yellow">
                        <i class="fa-solid fa-pause mt-0.5"></i>
                        <div class="text-sm">
                            <div class="font-medium text-[var(--color-ink-strong)]">Collection paused</div>
                            <div class="text-[var(--color-ink-muted)]">
                                The 15-min ingest is on hold. The numbers below are historical (the last data we successfully pulled) and will not refresh until you click <strong>Resume collection</strong>.
                            </div>
                        </div>
                    </div>
                @endunless

                @if (! empty($siteLeaderboard) && $siteLeaderboard->isNotEmpty())
                <div class="card overflow-hidden">
                    <div class="px-5 py-2.5 text-xs text-[var(--color-ink-muted)] border-b border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                        <i class="fa-solid fa-circle-info text-[10px] mr-1"></i>
                        PHP-FPM worker CPU only via Companion's <code class="font-data">getrusage()</code> sampler.
                        Does <strong>not</strong> include MySQL, nginx, or Redis CPU — DB-heavy sites may rank lower than their real load.
                        Cross-reference the per-server leaderboard above for the fuller picture.
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-5 py-3 text-left">Site</th>
                                <th class="px-5 py-3 text-left">Server</th>
                                <th class="px-5 py-3 text-right" title="Sum of PHP user+system CPU microseconds across all requests in the last 7 days">CPU (7d)</th>
                                <th class="px-5 py-3 text-right" title="Highest per-request peak memory observed in the 7-day window">Peak memory</th>
                                <th class="px-5 py-3 text-right" title="Total PHP requests in the 7-day window">Requests</th>
                                <th class="px-5 py-3 text-right" title="Average CPU per request">µs / req</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($siteLeaderboard as $row)
                                @php
                                    $avgUs = $row['requests_7d'] > 0
                                        ? (int) round($row['cpu_seconds_7d'] * 1_000_000 / $row['requests_7d'])
                                        : 0;
                                @endphp
                                <tr>
                                    <td class="px-5 py-3">
                                        <a href="{{ route('sites.show', $row['site']) }}" class="font-data text-[var(--color-ink-strong)] hover:underline">
                                            {{ $row['site']->domain }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($row['site']->server)
                                            <a href="{{ route('servers.show', $row['site']->server) }}" class="text-[var(--color-ink-muted)] hover:underline text-xs">
                                                {{ $row['site']->server->name }}
                                            </a>
                                        @elseif ($row['site']->isPressable())
                                            <span class="status-pill status-unknown text-[10px]"><i class="fa-solid fa-cloud"></i> Pressable</span>
                                        @else
                                            <span class="text-[var(--color-ink-soft)] text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-ink-strong)] tabular-nums">
                                        {{ $fmtSeconds($row['cpu_seconds_7d']) }}
                                    </td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-strong)] tabular-nums">
                                        {{ $fmtBytes($row['peak_mem_bytes_7d']) }}
                                    </td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)] tabular-nums">
                                        {{ number_format($row['requests_7d']) }}
                                    </td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)] tabular-nums">
                                        {{ number_format($avgUs) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    {{-- Empty state when collection is paused before any data
                         was ingested. (When enabled + empty, the outer @if
                         skips the section entirely.) --}}
                    <div class="card p-6 text-center text-sm text-[var(--color-ink-muted)]">
                        No per-site CPU data has been collected yet. Resume collection above to start populating the leaderboard.
                    </div>
                @endif
            </section>
        @endif

        <div id="pressure-headroom" class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            {{-- Pressure: who's hot? --}}
            <section>
                <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
                    <h2 class="font-display text-xl text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-fire text-[var(--color-status-red)] mr-1"></i>
                        Getting Hot <span class="text-sm font-normal text-[var(--color-ink-soft)]">({{ $pressure->count() }})</span>
                    </h2>
                    <div class="text-xs text-[var(--color-ink-soft)] text-right">
                        Any 24h avg ≥ CPU {{ $pressureThresholds['cpu'] }}% · MEM {{ $pressureThresholds['memory'] }}% · DSK {{ $pressureThresholds['disk'] }}%
                    </div>
                </div>
                <div class="card overflow-hidden">
                    @if ($pressure->isEmpty())
                        <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">
                            No shared servers under pressure right now.
                        </div>
                    @else
                        <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'cpu', defaultDir: 'desc' })">
                            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                                <tr>
                                    <x-sort-th key="server">Server</x-sort-th>
                                    <x-sort-th key="cpu" align="right">CPU</x-sort-th>
                                    <x-sort-th key="mem" align="right">MEM</x-sort-th>
                                    <x-sort-th key="dsk" align="right">DSK</x-sort-th>
                                    <x-sort-th key="sites" align="right">Sites</x-sort-th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--color-border-light)]">
                                @foreach ($pressure as $row)
                                    <tr
                                        data-sort-server="{{ $row['server']->name }}"
                                        data-sort-cpu="{{ $row['avg_cpu'] ?? '' }}"
                                        data-sort-mem="{{ $row['avg_memory'] ?? '' }}"
                                        data-sort-dsk="{{ $row['avg_disk'] ?? '' }}"
                                        data-sort-sites="{{ $row['site_count'] }}">
                                        <td class="px-4 py-2">
                                            <a href="{{ route('servers.show', $row['server']) }}" class="font-data text-[var(--color-ink-strong)] hover:underline">{{ $row['server']->name }}</a>
                                        </td>
                                        <td class="px-4 py-2 text-right font-data {{ $pressureClass($row['avg_cpu']) }}">{{ $fmtPct($row['avg_cpu']) }}</td>
                                        <td class="px-4 py-2 text-right font-data {{ $pressureClass($row['avg_memory']) }}">{{ $fmtPct($row['avg_memory']) }}</td>
                                        <td class="px-4 py-2 text-right font-data {{ $pressureClass($row['avg_disk']) }}">{{ $fmtPct($row['avg_disk']) }}</td>
                                        <td class="px-4 py-2 text-right text-[var(--color-ink-muted)]">{{ $row['site_count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>

            {{-- Headroom: who can absorb a migration? --}}
            <section>
                <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
                    <h2 class="font-display text-xl text-[var(--color-ink-strong)]" title="…like Bob Dylan.">
                        <i class="fa-solid fa-feather text-[var(--color-status-green)] mr-1"></i>
                        Chillin' <span class="text-sm font-normal text-[var(--color-ink-soft)]">({{ $headroom->count() }})</span>
                    </h2>
                    <div class="text-xs text-[var(--color-ink-soft)]">Coolest first · {{ $rollingDays }}d visits</div>
                </div>
                <div class="card overflow-hidden">
                    @if ($headroom->isEmpty())
                        <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">No shared servers tagged.</div>
                    @else
                        <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'mem', defaultDir: 'asc' })">
                            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                                <tr>
                                    <x-sort-th key="server">Server</x-sort-th>
                                    <x-sort-th key="cpu" align="right">CPU</x-sort-th>
                                    <x-sort-th key="mem" align="right">MEM</x-sort-th>
                                    <x-sort-th key="sites" align="right">Sites</x-sort-th>
                                    <x-sort-th key="visits" align="right">Visits {{ $rollingDays }}d</x-sort-th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--color-border-light)]">
                                @foreach ($headroom as $row)
                                    <tr
                                        data-sort-server="{{ $row['server']->name }}"
                                        data-sort-cpu="{{ $row['avg_cpu'] ?? '' }}"
                                        data-sort-mem="{{ $row['avg_memory'] ?? '' }}"
                                        data-sort-sites="{{ $row['site_count'] }}"
                                        data-sort-visits="{{ $row['visits_rolling'] }}">
                                        <td class="px-4 py-2">
                                            <a href="{{ route('servers.show', $row['server']) }}" class="font-data text-[var(--color-ink-strong)] hover:underline">{{ $row['server']->name }}</a>
                                        </td>
                                        <td class="px-4 py-2 text-right font-data {{ $pressureClass($row['avg_cpu']) }}">{{ $fmtPct($row['avg_cpu']) }}</td>
                                        <td class="px-4 py-2 text-right font-data {{ $pressureClass($row['avg_memory']) }}">{{ $fmtPct($row['avg_memory']) }}</td>
                                        <td class="px-4 py-2 text-right text-[var(--color-ink-muted)]">{{ $row['site_count'] }}</td>
                                        <td class="px-4 py-2 text-right text-[var(--color-ink-muted)]">{{ number_format($row['visits_rolling']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>
        </div>
        </div> {{-- End of #shared-vps-sections --}}
    @endif

    {{-- Pressable Fleet Capacity --}}
    @if (! empty($pressableCapacity))
        <section id="pressable-capacity" class="mb-10" x-show="filter === 'all' || filter === 'pressable'">
            <div x-show="filter === 'pressable'" class="mb-6 px-4 py-2.5 rounded-lg bg-[var(--color-primary-50)] dark:bg-[var(--color-primary-950)] border border-[var(--color-primary-200)] dark:border-[var(--color-primary-800)] text-xs flex items-center justify-between text-[var(--color-primary-900)] dark:text-[var(--color-primary-200)]">
                <span>
                    <i class="fa-solid fa-cloud text-[var(--color-primary-600)] mr-1.5"></i>
                    Showing <strong>Pressable Cloud</strong> fleet capacity &amp; telemetry only.
                </span>
                <button type="button" @click="setFilter('all')" class="text-[var(--color-primary-600)] font-medium hover:underline cursor-pointer">
                    View all fleets
                </button>
            </div>

            <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
                <div>
                    <h2 class="font-display text-xl text-[var(--color-ink-strong)] flex items-center gap-2">
                        <i class="fa-solid fa-cloud text-[var(--color-primary-600)]"></i>
                        Pressable Fleet Capacity
                        @if (! empty($pressableCapacity['planName']))
                            <span class="status-pill status-cyan font-medium text-xs">
                                {{ $pressableCapacity['planName'] }}
                            </span>
                        @endif
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        @if (! empty($pressableCapacity['organization']))
                            {{ $pressableCapacity['organization'] }} &middot;
                        @endif
                        Account quota pool &amp; local edge telemetry (zero excess API overhead).
                    </p>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <button type="button"
                            @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
                            class="btn-pill-nav text-xs flex items-center gap-1 hover:text-[var(--color-ink-strong)] cursor-pointer"
                            title="Return to the top of the page">
                        <i class="fa-solid fa-arrow-up text-[10px]"></i>
                        <span>Back to top</span>
                    </button>
                    <a href="{{ route('sites.index', ['provider' => 'pressable']) }}" class="text-[var(--color-primary-600)] hover:underline flex items-center gap-1">
                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                        View all {{ $pressableCapacity['dbSitesCount'] }} Pressable sites
                    </a>
                </div>
            </div>

            {{-- Summary Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                {{-- Card 1: Account Site Allocation --}}
                <div class="card p-4">
                    <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] mb-1">
                        <span class="font-medium uppercase tracking-wide">Account Sites</span>
                        <i class="fa-solid fa-server text-[var(--color-ink-muted)]"></i>
                    </div>
                    <div class="text-2xl font-display font-semibold text-[var(--color-ink-strong)]">
                        {{ $pressableCapacity['billableSites'] }}
                        @if ($pressableCapacity['maxBillable'] > 0)
                            <span class="text-sm font-normal text-[var(--color-ink-soft)]">/ {{ $pressableCapacity['maxBillable'] }}</span>
                        @endif
                    </div>
                    <div class="text-xs text-[var(--color-ink-muted)] mt-1 flex items-center gap-1.5">
                        <span>Billable sites</span>
                        @if ($pressableCapacity['stagingSites'] > 0)
                            &middot; <span class="text-[var(--color-status-cyan)]">+{{ $pressableCapacity['stagingSites'] }} staging</span>
                        @endif
                    </div>
                </div>

                {{-- Card 2: Companion Agent Adoption --}}
                <div class="card p-4">
                    <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] mb-1">
                        <span class="font-medium uppercase tracking-wide">Companion Adoption</span>
                        <i class="fa-solid fa-shield-halved text-[var(--color-ink-muted)]"></i>
                    </div>
                    <div class="text-2xl font-display font-semibold text-[var(--color-ink-strong)]">
                        {{ $pressableCapacity['companionInstalled'] }}
                        <span class="text-sm font-normal text-[var(--color-ink-soft)]">/ {{ $pressableCapacity['dbSitesCount'] }}</span>
                    </div>
                    <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                        <span class="font-medium text-[var(--color-status-green)]">{{ $pressableCapacity['companionAdoptionPct'] }}%</span> telemetry coverage
                    </div>
                </div>

                {{-- Card 3: Rolling 30d Fleet Visits --}}
                <div class="card p-4">
                    <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] mb-1">
                        <span class="font-medium uppercase tracking-wide">Fleet Visits (30d)</span>
                        <i class="fa-solid fa-chart-line text-[var(--color-ink-muted)]"></i>
                    </div>
                    <div class="text-2xl font-display font-semibold text-[var(--color-ink-strong)]">
                        {{ number_format($pressableCapacity['totalRollingVisits']) }}
                    </div>
                    <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                        {{ number_format($pressableCapacity['totalMonthVisits']) }} MTD &middot; {{ number_format($pressableCapacity['totalRollingRequests']) }} reqs
                    </div>
                </div>

                {{-- Card 4: Over-Quota / Health Status --}}
                <div class="card p-4">
                    <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] mb-1">
                        <span class="font-medium uppercase tracking-wide">Threshold Alerts</span>
                        <i class="fa-solid fa-triangle-exclamation text-[var(--color-ink-muted)]"></i>
                    </div>
                    <div class="text-2xl font-display font-semibold">
                        @if ($pressableCapacity['overQuota']->isNotEmpty())
                            <span class="text-[var(--color-status-red)]">{{ $pressableCapacity['overQuota']->count() }}</span>
                            <span class="text-xs font-normal text-[var(--color-status-red)]">over quota</span>
                        @elseif ($pressableCapacity['trending']->isNotEmpty())
                            <span class="text-[var(--color-status-yellow)]">{{ $pressableCapacity['trending']->count() }}</span>
                            <span class="text-xs font-normal text-[var(--color-status-yellow)]">trending</span>
                        @else
                            <span class="text-[var(--color-status-green)]">All OK</span>
                        @endif
                    </div>
                    <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                        Invoice threshold: {{ number_format($threshold) }} visits MTD
                    </div>
                </div>
            </div>

            {{-- Over-quota Pressable sites if any --}}
            @if ($pressableCapacity['overQuota']->isNotEmpty())
                <div class="card overflow-hidden mb-4 border border-[var(--color-status-red)]">
                    <div class="px-5 py-3 bg-[var(--color-status-red-soft)] text-xs font-medium text-[var(--color-status-red)] flex items-center justify-between">
                        <span><i class="fa-solid fa-triangle-exclamation mr-1.5"></i> Pressable Sites Exceeding Quota ({{ $pressableCapacity['overQuota']->count() }})</span>
                        <span>Calendar MTD &gt; {{ number_format($threshold) }}</span>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-5 py-2.5 text-left">Site</th>
                                <th class="px-5 py-2.5 text-right">MTD</th>
                                <th class="px-5 py-2.5 text-right">Over By</th>
                                <th class="px-5 py-2.5 text-right">% Over</th>
                                <th class="px-5 py-2.5 text-right">Visits {{ $rollingDays }}d</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($pressableCapacity['overQuota'] as $row)
                                <tr>
                                    <td class="px-5 py-2.5 font-data">
                                        <a href="{{ route('sites.show', $row['site']) }}" class="text-[var(--color-primary-600)] hover:underline">
                                            {{ $row['site']->domain }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-2.5 text-right font-display text-[var(--color-ink-strong)]">{{ number_format($row['month_visits']) }}</td>
                                    <td class="px-5 py-2.5 text-right font-display text-[var(--color-status-red)]">+{{ number_format($row['over_by']) }}</td>
                                    <td class="px-5 py-2.5 text-right font-display text-[var(--color-status-red)]">+{{ number_format($row['pct_over'], 1) }}%</td>
                                    <td class="px-5 py-2.5 text-right text-[var(--color-ink-muted)]">{{ number_format($row['rolling_visits']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Trending Pressable sites if any --}}
            @if ($pressableCapacity['trending']->isNotEmpty())
                <div class="card overflow-hidden mb-4 border border-[var(--color-status-yellow)]">
                    <div class="px-5 py-3 bg-[var(--color-status-yellow-soft)] text-xs font-medium text-amber-800 dark:text-amber-300 flex items-center justify-between">
                        <span><i class="fa-solid fa-arrow-trend-up mr-1.5"></i> Pressable Sites Trending Toward Overage ({{ $pressableCapacity['trending']->count() }})</span>
                        <span>MTD pace projects past {{ number_format($threshold) }} by month-end</span>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-5 py-2.5 text-left">Site</th>
                                <th class="px-5 py-2.5 text-right">MTD</th>
                                <th class="px-5 py-2.5 text-right">Visits {{ $rollingDays }}d</th>
                                <th class="px-5 py-2.5 text-right">Last 7d</th>
                                <th class="px-5 py-2.5 text-right">Projected month-end</th>
                                <th class="px-5 py-2.5 text-right">Projected Overage</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($pressableCapacity['trending'] as $row)
                                <tr>
                                    <td class="px-5 py-2.5 font-data">
                                        <a href="{{ route('sites.show', $row['site']) }}" class="text-[var(--color-primary-600)] hover:underline">
                                            {{ $row['site']->domain }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-2.5 text-right font-display text-[var(--color-ink-strong)]">{{ number_format($row['month_visits']) }}</td>
                                    <td class="px-5 py-2.5 text-right text-[var(--color-ink-muted)]">{{ number_format($row['rolling_visits']) }}</td>
                                    <td class="px-5 py-2.5 text-right text-[var(--color-ink-muted)]">{{ number_format($row['last_7d_visits']) }}</td>
                                    <td class="px-5 py-2.5 text-right font-display text-[var(--color-status-yellow)]">{{ number_format($row['projected_30d']) }}</td>
                                    <td class="px-5 py-2.5 text-right font-display text-[var(--color-status-yellow)]">+{{ number_format($row['projected_over_by']) }} (+{{ $row['projected_pct_over'] }}%)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Top Pressable Sites by Traffic --}}
            <div class="card overflow-hidden">
                <div class="px-5 py-3 bg-[var(--color-surface-alt)] border-b border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)] flex items-center justify-between flex-wrap gap-2">
                    <span class="font-medium text-[var(--color-ink-strong)]">
                        Top Pressable Sites by Traffic (Rolling {{ $rollingDays }}d)
                    </span>
                    <span class="text-[var(--color-ink-soft)]">
                        Ranked by total visits recorded in local telemetry
                    </span>
                </div>
                @if ($pressableCapacity['topSites']->isEmpty())
                    <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">
                        No traffic data recorded for Pressable sites in the last {{ $rollingDays }} days.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                                <tr>
                                    <th class="px-5 py-2.5 text-left">Site</th>
                                    <th class="px-5 py-2.5 text-left">Agent Status</th>
                                    <th class="px-5 py-2.5 text-right">30d Visits</th>
                                    <th class="px-5 py-2.5 text-right">MTD Visits</th>
                                    <th class="px-5 py-2.5 text-right">Last 7d</th>
                                    <th class="px-5 py-2.5 text-right">Requests (30d)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--color-border-light)]">
                                @foreach ($pressableCapacity['topSites'] as $row)
                                    <tr class="hover:bg-[var(--color-surface-hover)] transition-colors">
                                        <td class="px-5 py-2.5 font-data">
                                            <a href="{{ route('sites.show', $row['site']) }}" class="text-[var(--color-primary-600)] hover:underline flex items-center gap-1.5">
                                                <i class="fa-solid fa-cloud text-xs text-[var(--color-ink-muted)]"></i>
                                                {{ $row['site']->domain }}
                                            </a>
                                        </td>
                                        <td class="px-5 py-2.5 text-xs">
                                            @if ($row['site']->companion_installed)
                                                <span class="status-pill status-green text-[10px]">Companion Active</span>
                                            @else
                                                <span class="status-pill status-unknown text-[10px]">Unmanaged</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-2.5 text-right font-display text-[var(--color-ink-strong)] tabular-nums">
                                            {{ number_format($row['rolling_visits']) }}
                                        </td>
                                        <td class="px-5 py-2.5 text-right text-[var(--color-ink-muted)] tabular-nums">
                                            {{ number_format($row['month_visits']) }}
                                        </td>
                                        <td class="px-5 py-2.5 text-right text-[var(--color-ink-muted)] tabular-nums">
                                            {{ number_format($row['last_7d_visits']) }}
                                        </td>
                                        <td class="px-5 py-2.5 text-right text-[var(--color-ink-muted)] tabular-nums">
                                            {{ number_format($row['rolling_requests']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- Runtime EOL: Fleet PHP Lifecycle & End of Life --}}
    <section id="runtime-eol" class="mb-10" x-show="filter === 'all' || filter === 'eol' || filter === 'shared' || filter === 'pressable'">
        <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
            <div>
                <h2 class="font-display text-xl text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-[var(--color-primary-600)]"></i>
                    Runtime EOL & Lifecycle
                </h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                    Fleet PHP version classification against official endoflife.date lifecycle definitions.
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <button type="button"
                        @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
                        class="btn-pill-nav text-xs flex items-center gap-1 hover:text-[var(--color-ink-strong)] cursor-pointer mr-1"
                        title="Return to top of page">
                    <i class="fa-solid fa-arrow-up text-[10px]"></i>
                    <span>Top</span>
                </button>
                <span class="status-pill status-red font-medium">
                    {{ $runtimeEol['counts']['eol'] }} EOL
                </span>
                <span class="status-pill status-yellow font-medium">
                    {{ $runtimeEol['counts']['security_only'] }} Security only
                </span>
                <span class="status-pill status-green font-medium">
                    {{ $runtimeEol['counts']['active_support'] }} Supported
                </span>
                @if ($runtimeEol['counts']['unknown'] > 0)
                    <span class="status-pill status-unknown font-medium">
                        {{ $runtimeEol['counts']['unknown'] }} Unknown
                    </span>
                @endif
            </div>
        </div>

        <div class="card overflow-hidden">
            @if ($runtimeEol['isStale'])
                <div class="px-5 py-3 bg-[var(--color-surface-alt)] border-b border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)] flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-amber-600"></i>
                        <span>EOL data is stale or unavailable. Last synchronized: {{ $runtimeEol['fetchedAt'] ? \Illuminate\Support\Carbon::parse($runtimeEol['fetchedAt'])->diffForHumans() : 'never' }}.</span>
                    </div>
                    <code class="text-[11px] text-[var(--color-ink-soft)]">clockwork:refresh-runtime-eol</code>
                </div>
            @endif

            @if (empty($runtimeEol['rows']))
                <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">
                    No monitored sites currently have PHP version data in their Companion snapshots.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'status', defaultDir: 'asc' })">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <x-sort-th key="site">Site</x-sort-th>
                                <x-sort-th key="server">Server</x-sort-th>
                                <x-sort-th key="version">PHP Version</x-sort-th>
                                <x-sort-th key="status">Status</x-sort-th>
                                <x-sort-th key="detail">Lifecycle Support Window</x-sort-th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($runtimeEol['rows'] as $row)
                                @php
                                    $pillClass = match ($row['status']) {
                                        'eol' => 'status-red',
                                        'security_only' => 'status-yellow',
                                        'active_support' => 'status-green',
                                        default => 'status-unknown',
                                    };
                                    $statusLabel = match ($row['status']) {
                                        'eol' => 'EOL',
                                        'security_only' => 'Security only',
                                        'active_support' => 'Supported',
                                        default => 'Unknown',
                                    };
                                    // Numeric severity so the default sort puts the worst rows first
                                    // (asc: eol → security_only → active_support → unknown).
                                    $statusSeverity = match ($row['status']) {
                                        'eol' => 0,
                                        'security_only' => 1,
                                        'active_support' => 2,
                                        default => 3,
                                    };
                                @endphp
                                <tr
                                    data-sort-site="{{ $row['site']->domain }}"
                                    data-sort-server="{{ $row['site']->server?->name ?? 'Standalone' }}"
                                    data-sort-version="{{ $row['php_version'] }}"
                                    data-sort-status="{{ $statusSeverity }}"
                                    data-sort-detail="{{ $row['detail'] }}"
                                    class="hover:bg-[var(--color-surface-hover)] transition-colors">
                                    <td class="px-5 py-2.5 font-data">
                                        <a href="{{ route('sites.show', $row['site']) }}" class="text-[var(--color-primary-600)] hover:underline flex items-center gap-1.5">
                                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-[var(--color-ink-muted)]"></i>
                                            {{ $row['site']->domain }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-2.5 text-xs text-[var(--color-ink-muted)]">
                                        @if ($row['site']->server)
                                            {{ $row['site']->server->name }}
                                        @elseif ($row['site']->isPressable())
                                            <span class="status-pill status-unknown text-[10px]"><i class="fa-solid fa-cloud"></i> Pressable</span>
                                        @else
                                            <span class="status-pill status-unknown text-[10px]"><i class="fa-solid fa-globe"></i> Standalone</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2.5 font-mono text-xs font-medium text-[var(--color-ink-strong)]">
                                        {{ $row['php_version'] }}
                                    </td>
                                    <td class="px-5 py-2.5 text-xs">
                                        <span class="status-pill {{ $pillClass }} font-semibold text-[10px]">
                                            {{ $statusLabel }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-2.5 text-xs text-[var(--color-ink-soft)]">
                                        {{ $row['detail'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    {{-- Floating Quick-Jump Pill (visible when scrolled down) --}}
    <div x-show="showFloating"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-3"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-3"
         class="fixed bottom-6 right-6 z-40 flex items-center gap-1 bg-[var(--color-surface)] border border-[var(--color-border)] p-1.5 rounded-full shadow-lg text-xs"
         style="display: none;">
        <button type="button"
                @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
                class="px-2.5 py-1 rounded-full text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-hover)] flex items-center gap-1 transition-colors cursor-pointer"
                title="Scroll to top of page">
            <i class="fa-solid fa-arrow-up text-[10px]"></i>
            <span class="font-medium">Top</span>
        </button>
        <div class="h-3.5 w-px bg-[var(--color-border)]"></div>
        <button type="button"
                @click="scrollTo('shared-vps-sections')"
                class="px-2.5 py-1 rounded-full text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-hover)] flex items-center gap-1 transition-colors cursor-pointer"
                title="Jump to SpinupWP Shared VPS">
            <i class="fa-solid fa-server text-[10px] text-[var(--color-brand)]"></i>
            <span>Shared VPS</span>
        </button>
        @if (! empty($pressableCapacity))
            <div class="h-3.5 w-px bg-[var(--color-border)]"></div>
            <button type="button"
                    @click="scrollTo('pressable-capacity')"
                    class="px-2.5 py-1 rounded-full text-[var(--color-primary-600)] font-medium hover:bg-[var(--color-surface-hover)] flex items-center gap-1 transition-colors cursor-pointer"
                    title="Jump directly down to Pressable Fleet">
                <i class="fa-solid fa-cloud text-[10px]"></i>
                <span>Pressable</span>
            </button>
        @endif
    </div>

    </div> {{-- End outer Alpine wrapper --}}
@endsection
