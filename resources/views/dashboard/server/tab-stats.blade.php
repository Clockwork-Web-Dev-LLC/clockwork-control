@php
    use App\Models\Server;
    use App\Services\CloudProvider\CloudProviderRegistry;

    // Card-header label is just the cloud name; card body shows whether
    // we successfully cross-referenced this server in that cloud's API.
    // An unrecognized provider string shows itself (ucfirst'd) rather than
    // silently claiming to be DigitalOcean — same reasoning as
    // Server::getProviderLabelAttribute()'s own carve-out.
    $cloudProvider = app(CloudProviderRegistry::class)->resolve($server->provider);
    $isKnownProvider = $cloudProvider->id() !== 'unknown';
    $providerCardTitle = $isKnownProvider ? str_replace([' droplet', ' server', ' VM', ' instance'], '', $cloudProvider->label()) : ucfirst((string) $server->provider);
    $providerNoun = $cloudProvider->instanceNoun();
@endphp
{{-- Quick stats grid — Sites count / Cloud provider / Last polled --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
    <div class="card p-5">
        <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Sites</div>
        <div class="font-display text-2xl text-[var(--color-ink-strong)]">{{ $server->sites->count() }}</div>
        <div class="text-sm text-[var(--color-ink-muted)] mt-1">
            {{ $server->sites->where('is_wordpress', true)->count() }} WordPress ·
            {{ $server->sites->where('is_wordpress', false)->count() }} other
        </div>
    </div>

    <div class="card p-5">
        <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">{{ $providerCardTitle }}</div>
        @if ($server->provider_id)
            <div class="font-display text-2xl text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="{{ $cloudProvider->iconClass() }}" style="color: {{ $cloudProvider->iconColor() }}"></i>
                Linked
            </div>
            <div class="text-sm text-[var(--color-ink-muted)] mt-1 font-data">{{ $providerNoun }} #{{ $server->provider_id }}</div>
        @else
            <div class="font-display text-2xl text-[var(--color-ink-soft)]">Not linked</div>
            <div class="text-sm text-[var(--color-ink-muted)] mt-1">No matching {{ $providerNoun }} IP</div>
        @endif
    </div>

    <div class="card p-5">
        <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Last polled</div>
        <div class="font-display text-2xl text-[var(--color-ink-strong)]">
            {{ $server->last_polled_at?->diffForHumans() ?? 'Never' }}
        </div>
        <div class="text-sm text-[var(--color-ink-muted)] mt-1">
            {{ $server->last_alert_at ? 'Last alert ' . $server->last_alert_at->diffForHumans() : 'No alerts on record' }}
        </div>
    </div>
</div>

@if ($metrics->isNotEmpty())
    @php
        $metricsForChart = $metrics->map(function ($m) {
            return [
                'recorded_at' => $m->recorded_at->toIso8601String(),
                'cpu_pct' => $m->cpu_pct === null ? null : (float) $m->cpu_pct,
                'memory_pct' => $m->memory_pct === null ? null : (float) $m->memory_pct,
                'disk_pct' => $m->disk_pct === null ? null : (float) $m->disk_pct,
                'load_1' => $m->load_1 === null ? null : (float) $m->load_1,
            ];
        })->values();
    @endphp
    <div class="card p-5 mb-6">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-3">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Resource trends</h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">{{ $rangeConfig['label'] }} · {{ $metrics->count() }} samples (5-min poll)</p>
            </div>
            @if ($latestMetric)
                <div class="grid grid-cols-4 gap-4 text-xs text-right">
                    <div>
                        <div class="text-[var(--color-ink-soft)] uppercase tracking-wide text-[10px]">CPU</div>
                        <div class="font-display text-base text-[var(--color-ink-strong)]">{{ $latestMetric->cpu_pct !== null ? number_format($latestMetric->cpu_pct, 1) . '%' : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--color-ink-soft)] uppercase tracking-wide text-[10px]">Memory</div>
                        <div class="font-display text-base text-[var(--color-ink-strong)]">{{ $latestMetric->memory_pct !== null ? number_format($latestMetric->memory_pct, 1) . '%' : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--color-ink-soft)] uppercase tracking-wide text-[10px]">Disk</div>
                        <div class="font-display text-base text-[var(--color-ink-strong)]">{{ $latestMetric->disk_pct !== null ? number_format($latestMetric->disk_pct, 1) . '%' : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--color-ink-soft)] uppercase tracking-wide text-[10px]">Load 1m</div>
                        <div class="font-display text-base text-[var(--color-ink-strong)]">{{ $latestMetric->load_1 !== null ? number_format($latestMetric->load_1, 2) : '—' }}</div>
                    </div>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-1 mb-4 text-xs">
            @foreach (['1h' => '1h', '6h' => '6h', '24h' => '24h', '7d' => '7d'] as $key => $label)
                <a href="{{ route('servers.show', ['server' => $server, 'tab' => 'stats', 'range' => $key]) }}"
                   class="px-2.5 py-1 rounded-md font-medium transition-colors border {{ $chartRange === $key ? 'bg-[var(--color-nav-active-bg)] text-[var(--color-nav-active-ink)] border-[var(--color-nav-active-border)]' : 'border-transparent text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div style="height: 280px"><canvas id="metrics-chart"></canvas></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@3.6.0/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script>
        (function () {
            const data = @json($metricsForChart);

            const ctx = document.getElementById('metrics-chart');
            if (!ctx || !window.Chart) return;

            const labels = data.map(d => new Date(d.recorded_at));

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'CPU %',    data: data.map(d => d.cpu_pct),    borderColor: '#3b82f6', backgroundColor: 'transparent', tension: 0.2, pointRadius: 0, borderWidth: 1.5, yAxisID: 'pct' },
                        { label: 'Memory %', data: data.map(d => d.memory_pct), borderColor: '#10b981', backgroundColor: 'transparent', tension: 0.2, pointRadius: 0, borderWidth: 1.5, yAxisID: 'pct' },
                        { label: 'Disk %',   data: data.map(d => d.disk_pct),   borderColor: '#f59e0b', backgroundColor: 'transparent', tension: 0.2, pointRadius: 0, borderWidth: 1.5, yAxisID: 'pct' },
                        { label: 'Load 1m',  data: data.map(d => d.load_1),     borderColor: '#a78bfa', backgroundColor: 'transparent', tension: 0.2, pointRadius: 0, borderWidth: 1.5, borderDash: [4, 4], yAxisID: 'load' },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 14, font: { size: 11 } } },
                        tooltip: { callbacks: { title: (items) => new Date(items[0].parsed.x).toLocaleString() } },
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: @json($rangeConfig['unit']),
                                stepSize: @json($rangeConfig['step']),
                                tooltipFormat: 'MMM d, HH:mm',
                                displayFormats: {
                                    minute: @json($rangeConfig['fmt']),
                                    hour: @json($rangeConfig['fmt']),
                                    day: @json($rangeConfig['fmt']),
                                },
                            },
                            grid: { color: 'rgba(0,0,0,0.04)', drawTicks: false },
                            ticks: { font: { size: 10 }, maxRotation: 0, autoSkipPadding: 12 },
                        },
                        pct: {
                            type: 'linear', position: 'left', min: 0, max: 100,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: { callback: v => v + '%', font: { size: 10 } },
                        },
                        load: {
                            type: 'linear', position: 'right',
                            grid: { display: false },
                            ticks: { font: { size: 10 } },
                        },
                    },
                },
            });
        })();
    </script>
@endif

{{-- Live diagnostics --}}
@if ($liveLoad)
    @php
        $catColors = [
            'mysql' => 'var(--color-status-yellow)',
            'php-fpm' => 'var(--color-primary-500)',
            'wp-cron' => 'var(--color-status-yellow)',
            'nginx' => 'var(--color-ink-strong)',
            'redis' => '#dc382c',
            'backup' => 'var(--color-ink-muted)',
            'apt' => 'var(--color-ink-muted)',
            'fail2ban' => 'var(--color-ink-muted)',
            'other' => 'var(--color-ink-soft)',
        ];
        $catIcons = [
            'mysql' => 'fa-database',
            'php-fpm' => 'fa-code',
            'wp-cron' => 'fa-clock',
            'nginx' => 'fa-server',
            'redis' => 'fa-bolt-lightning',
            'backup' => 'fa-box-archive',
            'apt' => 'fa-cube',
            'fail2ban' => 'fa-shield-halved',
            'other' => 'fa-circle',
        ];
    @endphp
    <div class="card p-5 mb-6">
        <div class="flex items-start justify-between mb-3 flex-wrap gap-3">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Live diagnostics</h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Top processes + MySQL queries running &gt;1s right now. Cached 30s.</p>
            </div>
            @if ($liveLoad['loadavg'])
                <div class="text-xs text-right">
                    <div class="text-[var(--color-ink-soft)] uppercase tracking-wide text-[10px]">load avg (1m · 5m · 15m)</div>
                    <div class="font-data text-[var(--color-ink-strong)]">
                        {{ number_format($liveLoad['loadavg'][0], 2) }} ·
                        {{ number_format($liveLoad['loadavg'][1], 2) }} ·
                        {{ number_format($liveLoad['loadavg'][2], 2) }}
                        <span class="text-[var(--color-ink-soft)]">/ {{ $liveLoad['cores'] }} {{ Str::plural('core', $liveLoad['cores']) }}</span>
                    </div>
                </div>
            @endif
        </div>

        @if ($liveLoad['error'])
            <div class="text-xs text-[var(--color-status-red)] mb-3">Probe failed: {{ $liveLoad['error'] }}</div>
        @endif

        @if (count($liveLoad['top']) === 0)
            <div class="text-sm text-[var(--color-ink-muted)] py-3">No significant processes captured.</div>
        @else
            <table class="w-full text-sm">
                <thead class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)]">
                    <tr>
                        <th class="text-left py-2 font-medium">Process</th>
                        <th class="text-left py-2 font-medium">User</th>
                        <th class="text-right py-2 font-medium">CPU</th>
                        <th class="text-right py-2 font-medium">Mem</th>
                        <th class="text-left py-2 font-medium pl-3">Runtime</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($liveLoad['top'] as $p)
                        <tr>
                            <td class="py-2 pr-3">
                                <span class="inline-flex items-center gap-1.5">
                                    <i class="fa-solid {{ $catIcons[$p['category']] ?? 'fa-circle' }} text-xs"
                                       style="color: {{ $catColors[$p['category']] ?? 'var(--color-ink-soft)' }}"
                                       title="{{ $p['category'] }}"></i>
                                    <span class="font-data text-xs text-[var(--color-ink-strong)] truncate max-w-[36ch]"
                                          title="{{ $p['command'] }}">{{ $p['command'] }}</span>
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-xs text-[var(--color-ink-muted)] font-data">{{ $p['user'] }}</td>
                            <td class="py-2 text-right font-data text-xs"
                                @if ($p['cpu'] >= 50) style="color: var(--color-status-red); font-weight: 600;"
                                @elseif ($p['cpu'] >= 25) style="color: var(--color-status-yellow); font-weight: 600;"
                                @endif>
                                {{ number_format($p['cpu'], 1) }}%
                            </td>
                            <td class="py-2 text-right font-data text-xs text-[var(--color-ink-muted)]">{{ number_format($p['mem'], 1) }}%</td>
                            <td class="py-2 pl-3 text-xs text-[var(--color-ink-soft)] font-data">{{ $p['etime'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($liveLoad['mysql_ok'])
            <details class="mt-4" {{ count($liveLoad['mysql']) > 0 ? 'open' : '' }}>
                <summary class="text-xs text-[var(--color-ink-muted)] cursor-pointer">
                    <i class="fa-solid fa-database mr-1"></i>
                    MySQL queries running &gt;1s
                    <span class="text-[var(--color-ink-soft)]">({{ count($liveLoad['mysql']) }})</span>
                </summary>
                @if (count($liveLoad['mysql']) === 0)
                    <p class="text-sm text-[var(--color-ink-muted)] mt-2">No long-running queries — MySQL is idle (or queries are completing fast).</p>
                @else
                    <table class="w-full text-sm mt-3">
                        <thead class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)]">
                            <tr>
                                <th class="text-left py-2 font-medium">DB / user</th>
                                <th class="text-right py-2 font-medium">Time</th>
                                <th class="text-left py-2 font-medium pl-3">State</th>
                                <th class="text-left py-2 font-medium pl-3">Query</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($liveLoad['mysql'] as $q)
                                <tr>
                                    <td class="py-2 pr-3 font-data text-xs text-[var(--color-ink-strong)]">
                                        {{ $q['db'] }}
                                        <span class="text-[var(--color-ink-soft)]">· {{ $q['user'] }}</span>
                                    </td>
                                    <td class="py-2 text-right font-data text-xs"
                                        @if ($q['time'] >= 30) style="color: var(--color-status-red); font-weight: 600;"
                                        @elseif ($q['time'] >= 5) style="color: var(--color-status-yellow); font-weight: 600;"
                                        @endif>
                                        {{ $q['time'] }}s
                                    </td>
                                    <td class="py-2 pl-3 text-xs text-[var(--color-ink-muted)]">{{ $q['state'] }}</td>
                                    <td class="py-2 pl-3 text-xs text-[var(--color-ink-muted)] font-data">
                                        <span title="{{ $q['query'] }}">{{ \Illuminate\Support\Str::limit($q['query'], 80) }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </details>
        @endif
    </div>
@endif
