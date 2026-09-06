<div class="card p-5 mb-10">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <div>
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-chart-line text-[var(--color-ink-soft)] mr-1"></i>
                Traffic
            </h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                From nginx access logs · today's bar updates hourly · totals use
                <a href="https://wpengine.com/support/count-visits/" target="_blank" rel="noopener"
                   class="text-[var(--color-primary-600)] hover:underline">WP-Engine-style visits</a>
                (DISTINCT IP/day, ex-403, ex-static)
            </p>
        </div>
        @if ($trafficData['has_data'])
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-right">
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Today</div>
                    <div class="font-display text-xl text-[var(--color-ink-strong)]">{{ number_format($trafficData['totals']['day']) }}</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">7 day</div>
                    <div class="font-display text-xl text-[var(--color-ink-strong)]">{{ number_format($trafficData['totals']['week']) }}</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">30 day</div>
                    <div class="font-display text-xl text-[var(--color-ink-strong)]">{{ number_format($trafficData['totals']['month']) }}</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">365 day</div>
                    <div class="font-display text-xl text-[var(--color-ink-strong)]">{{ number_format($trafficData['totals']['year']) }}</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Lifetime</div>
                    <div class="font-display text-xl text-[var(--color-ink-strong)]">{{ number_format($trafficData['totals']['lifetime']) }}</div>
                </div>
            </div>
        @endif
    </div>

    @if (! $trafficData['has_data'])
        <div class="text-center py-12 text-sm text-[var(--color-ink-soft)]">
            <i class="fa-solid fa-hourglass-half text-2xl mb-2 block"></i>
            No traffic rollups yet. Run <code class="font-data text-xs bg-[var(--color-surface-alt)] px-1 rounded">php artisan clockwork:rollup-traffic --backfill=30</code>
            to populate from existing nginx logs.
        </div>
    @else
        <div id="traffic-daily" class="w-full" style="height: 280px"></div>
        <div class="mt-2 text-[10px] text-[var(--color-ink-soft)] text-center">
            <i class="fa-solid fa-circle-info"></i>
            Drag the slider below the chart to zoom in. Hover any bar for the per-status breakdown.
        </div>

        <div class="mt-8 mb-2 flex items-end justify-between flex-wrap gap-2">
            <div>
                <h3 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">
                    <i class="fa-regular fa-calendar text-[var(--color-ink-soft)] mr-1"></i>
                    365-day activity
                </h3>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                    One square per day, last 12 months. Darker blue = more requests. Hover for the exact count. Empty squares = no traffic recorded that day.
                </p>
            </div>
        </div>
        <div id="traffic-calendar" class="w-full" style="height: 200px"></div>

        @php
            // Three-bucket top paths. Backwards-compat: a flat array (pre-
            // categorisation rollup) goes into 'pages' so historical rows
            // still render something during the deploy → backfill window.
            $rawTop = $trafficData['latest_top_paths'] ?? [];
            if (is_array($rawTop) && array_is_list($rawTop)) {
                $topBuckets = ['pages' => $rawTop, 'api' => [], 'uploads' => []];
            } else {
                $topBuckets = [
                    'pages' => is_array($rawTop['pages'] ?? null) ? $rawTop['pages'] : [],
                    'api' => is_array($rawTop['api'] ?? null) ? $rawTop['api'] : [],
                    'uploads' => is_array($rawTop['uploads'] ?? null) ? $rawTop['uploads'] : [],
                ];
            }
            $bucketLabels = [
                'pages' => ['title' => 'Pages/Posts', 'sub' => 'What your visitors actually viewed.'],
                'api' => ['title' => 'API/Bots', 'sub' => 'WP internals, sitemap, REST, theme/plugin assets — mostly bot traffic.'],
                'uploads' => ['title' => 'Media Library', 'sub' => 'Files served from /wp-content/uploads/.'],
            ];
            $hasAny = collect($topBuckets)->contains(fn ($rows) => ! empty($rows));
        @endphp
        @if ($hasAny)
            <div class="mt-6 text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-2">Top paths · most recent day</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                @foreach (['pages', 'api', 'uploads'] as $key)
                    @if (! empty($topBuckets[$key]))
                        <div>
                            <div class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ $bucketLabels[$key]['title'] }}</div>
                            <p class="text-[10px] text-[var(--color-ink-soft)] mt-0.5 mb-2">{{ $bucketLabels[$key]['sub'] }}</p>
                            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'hits', defaultDir: 'desc' })">
                                <thead class="text-[var(--color-ink-soft)] text-xs uppercase tracking-wide">
                                    <tr>
                                        <x-sort-th key="path" class="py-1.5">Path</x-sort-th>
                                        <x-sort-th key="hits" align="right" class="py-1.5">Hits</x-sort-th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[var(--color-border-light)]">
                                    @foreach ($topBuckets[$key] as $row)
                                        <tr
                                            data-sort-path="{{ $row['path'] }}"
                                            data-sort-hits="{{ $row['hits'] }}">
                                            <td class="py-1.5 font-data text-xs text-[var(--color-ink-muted)] truncate max-w-md" title="{{ $row['path'] }}">{{ $row['path'] }}</td>
                                            <td class="py-1.5 text-right font-display text-sm text-[var(--color-ink-strong)]">{{ number_format($row['hits']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        <script type="module">
            (function () {
                const daily = @json($trafficData['daily']);
                const calendar = @json($trafficData['calendar']);

                const dates = daily.map(d => d.date);
                const s2xx = daily.map(d => d.status_2xx);
                const s3xx = daily.map(d => d.status_3xx);
                const s4xx = daily.map(d => d.status_4xx);
                const s5xx = daily.map(d => d.status_5xx);

                function init() {
                    if (! window.echarts) {
                        return setTimeout(init, 50);
                    }

                    const dailyEl = document.getElementById('traffic-daily');
                    if (dailyEl) {
                        const dailyChart = window.echarts.init(dailyEl);
                        dailyChart.setOption({
                            grid: { left: 50, right: 16, top: 20, bottom: 50 },
                            tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                            legend: {
                                data: ['2xx', '3xx', '4xx', '5xx'],
                                bottom: 0,
                                textStyle: { fontSize: 11 },
                            },
                            xAxis: {
                                type: 'category',
                                data: dates,
                                axisLabel: { fontSize: 10, formatter: (v) => v.slice(5) },
                            },
                            yAxis: {
                                type: 'value',
                                axisLabel: {
                                    fontSize: 10,
                                    formatter: (v) => v >= 1000 ? (v / 1000).toFixed(1) + 'k' : v,
                                },
                            },
                            dataZoom: [
                                { type: 'inside', start: 50, end: 100 },
                                { type: 'slider', height: 18, bottom: 28, start: 50, end: 100 },
                            ],
                            series: [
                                { name: '2xx', type: 'bar', stack: 'total', data: s2xx, itemStyle: { color: '#10b981' } },
                                { name: '3xx', type: 'bar', stack: 'total', data: s3xx, itemStyle: { color: '#6366f1' } },
                                { name: '4xx', type: 'bar', stack: 'total', data: s4xx, itemStyle: { color: '#f59e0b' } },
                                { name: '5xx', type: 'bar', stack: 'total', data: s5xx, itemStyle: { color: '#ef4444' } },
                            ],
                        });
                        window.addEventListener('resize', () => dailyChart.resize());
                    }

                    const calEl = document.getElementById('traffic-calendar');
                    if (calEl && calendar.length > 0) {
                        const calChart = window.echarts.init(calEl);
                        const max = Math.max(...calendar.map(c => c[1]), 1);
                        const today = new Date();
                        const start = new Date(today);
                        start.setDate(start.getDate() - 364);
                        const fmt = (d) => d.toISOString().slice(0, 10);

                        calChart.setOption({
                            tooltip: {
                                formatter: (p) => `${p.value[0]}<br/>${Number(p.value[1]).toLocaleString()} requests`,
                            },
                            visualMap: {
                                min: 0, max,
                                type: 'piecewise',
                                orient: 'horizontal',
                                left: 'center',
                                bottom: 0,
                                pieces: [
                                    { min: 0, max: 0, label: '0', color: '#f3f4f6' },
                                    { min: 1, max: Math.max(1, max * 0.1), color: '#dbeafe' },
                                    { min: Math.max(1, max * 0.1), max: Math.max(1, max * 0.3), color: '#93c5fd' },
                                    { min: Math.max(1, max * 0.3), max: Math.max(1, max * 0.6), color: '#3b82f6' },
                                    { min: Math.max(1, max * 0.6), color: '#1e40af' },
                                ],
                                textStyle: { fontSize: 10 },
                            },
                            calendar: {
                                top: 20, bottom: 50, left: 40, right: 20,
                                range: [fmt(start), fmt(today)],
                                cellSize: ['auto', 14],
                                itemStyle: { borderColor: '#fff', borderWidth: 2 },
                                splitLine: { show: false },
                                yearLabel: { show: false },
                                monthLabel: { fontSize: 10, color: '#6b7280' },
                                dayLabel: { fontSize: 10, color: '#9ca3af' },
                            },
                            series: {
                                type: 'heatmap',
                                coordinateSystem: 'calendar',
                                data: calendar,
                            },
                        });
                        window.addEventListener('resize', () => calChart.resize());
                    }
                }

                init();
            })();
        </script>
    @endif
</div>
