<div class="card p-5 mb-10" @if ($trafficData['has_data']) x-data="trafficCharts({{ json_encode($trafficData) }})" @endif>
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


    @endif
</div>
