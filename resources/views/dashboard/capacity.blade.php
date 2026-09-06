@extends('layouts.app')

@section('title', 'Capacity · Clockwork')

@section('content')
    <x-page-header title="Capacity"
        subtitle="Shared-server pressure, headroom, and visit-threshold overages. Visit count uses WP Engine's definition: DISTINCT IP per UTC day, excluding 403s, static assets, and known bots.">
        <x-slot:actions>
            <a href="{{ route('capacity.settings') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-sliders text-[var(--color-ink-muted)]"></i>
                <span>Capacity settings</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($sharedTagMissing)
        <div class="card p-6 status-yellow">
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

        {{-- Over-quota — surface this first; it's the action item --}}
        <section id="over-quota" class="mb-10">
            <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
                <h2 class="font-display text-xl text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)] mr-1"></i>
                    Over visit threshold
                    <span class="text-sm font-normal text-[var(--color-ink-soft)] ml-1">
                        (rolling {{ $rollingDays }}d &gt; {{ number_format($threshold) }} visits &middot;
                        <a href="{{ route('capacity.settings') }}" class="text-[var(--color-primary-600)] hover:underline text-xs">edit threshold</a>, Shared servers only)
                    </span>
                </h2>
                <div class="text-sm text-[var(--color-ink-soft)]">{{ $overQuota->count() }} site(s)</div>
            </div>
            <div class="card overflow-hidden">
                @if ($overQuota->isEmpty())
                    <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">
                        No shared-server sites are over the rolling {{ $rollingDays }}-day {{ number_format($threshold) }}-visit threshold.
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
                                <x-sort-th key="rolling" align="right" class="px-5 py-3">Visits 30d</x-sort-th>
                                <x-sort-th key="overby" align="right" class="px-5 py-3">Over by</x-sort-th>
                                <x-sort-th key="pctover" align="right" class="px-5 py-3">% over</x-sort-th>
                                <x-sort-th key="mtd" align="right" class="px-5 py-3" title="Calendar-month-to-date — what {{ $monthLabel }} invoices on">MTD ({{ $monthLabel }})</x-sort-th>
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
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-ink-strong)]">{{ number_format($row['rolling_visits']) }}</td>
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-status-red)]">+{{ number_format($row['over_by']) }}</td>
                                    <td class="px-5 py-3 text-right font-display text-[var(--color-status-red)]">+{{ number_format($row['pct_over'], 1) }}%</td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)]">{{ number_format($row['month_visits']) }}</td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)]">{{ number_format($row['last_7d_visits']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>

        {{-- Trending toward overage — sites NOT yet over the rolling-30d
             threshold but whose last-7d rate, extrapolated to 30 days,
             projects past 30,000. Linear projection: visits in last N days
             × (30 / N). Catches ramping sites BEFORE they cross the line so
             you can have the conversation before the overage hits the
             invoice. Hidden when no sites match. --}}
        @if (! empty($trending) && $trending->isNotEmpty())
            <section id="trending-overage" class="mb-10">
                <div class="flex items-end justify-between mb-3 flex-wrap gap-2">
                    <h2 class="font-display text-xl text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-arrow-trend-up text-[var(--color-status-yellow)] mr-1"></i>
                        Trending toward overage
                        <span class="text-sm font-normal text-[var(--color-ink-soft)] ml-1">
                            (last {{ $trendingWindow }}d × 30/{{ $trendingWindow }} &gt; {{ number_format($threshold) }} projected)
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
                                <x-sort-th key="rolling" align="right" class="px-5 py-3" title="Current rolling-30d total — not yet over the threshold">Visits 30d (now)</x-sort-th>
                                <x-sort-th key="last7" align="right" class="px-5 py-3">Last {{ $trendingWindow }}d</x-sort-th>
                                <x-sort-th key="dailyavg" align="right" class="px-5 py-3" title="Average daily visits over the last {{ $trendingWindow }} days">Daily avg</x-sort-th>
                                <x-sort-th key="projected" align="right" class="px-5 py-3" title="Linear extrapolation: last_{{ $trendingWindow }}d × 30/{{ $trendingWindow }}">Projected 30d</x-sort-th>
                                <x-sort-th key="projectedover" align="right" class="px-5 py-3">Projected over by</x-sort-th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($trending as $row)
                                <tr
                                    data-sort-site="{{ $row['site']->domain }}"
                                    data-sort-server="{{ $row['site']->server?->name ?? '' }}"
                                    data-sort-rolling="{{ $row['rolling_visits'] }}"
                                    data-sort-last7="{{ $row['last_7d_visits'] }}"
                                    data-sort-dailyavg="{{ $row['daily_avg_7d'] }}"
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
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)]">{{ number_format($row['rolling_visits']) }}</td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)]">{{ number_format($row['last_7d_visits']) }}</td>
                                    <td class="px-5 py-3 text-right text-[var(--color-ink-muted)]">{{ number_format($row['daily_avg_7d']) }}/d</td>
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
                                        onclick="return confirm('Pause per-site CPU collection? Historical data will still be visible; the 15-min ingest will stop until re-enabled.');">
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

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
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
                                    <x-sort-th key="visits" align="right">Visits MTD</x-sort-th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--color-border-light)]">
                                @foreach ($headroom as $row)
                                    <tr
                                        data-sort-server="{{ $row['server']->name }}"
                                        data-sort-cpu="{{ $row['avg_cpu'] ?? '' }}"
                                        data-sort-mem="{{ $row['avg_memory'] ?? '' }}"
                                        data-sort-sites="{{ $row['site_count'] }}"
                                        data-sort-visits="{{ $row['visits_mtd'] }}">
                                        <td class="px-4 py-2">
                                            <a href="{{ route('servers.show', $row['server']) }}" class="font-data text-[var(--color-ink-strong)] hover:underline">{{ $row['server']->name }}</a>
                                        </td>
                                        <td class="px-4 py-2 text-right font-data {{ $pressureClass($row['avg_cpu']) }}">{{ $fmtPct($row['avg_cpu']) }}</td>
                                        <td class="px-4 py-2 text-right font-data {{ $pressureClass($row['avg_memory']) }}">{{ $fmtPct($row['avg_memory']) }}</td>
                                        <td class="px-4 py-2 text-right text-[var(--color-ink-muted)]">{{ $row['site_count'] }}</td>
                                        <td class="px-4 py-2 text-right text-[var(--color-ink-muted)]">{{ number_format($row['visits_mtd']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>
        </div>
    @endif
@endsection
