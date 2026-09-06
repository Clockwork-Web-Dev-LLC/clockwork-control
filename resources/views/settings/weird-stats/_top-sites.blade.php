@php
    $tierFor = function ($server) {
        if (! $server) return null;
        foreach ($server->tags ?? [] as $tag) {
            if (in_array($tag->name, ['Dedicated', 'Shared', 'Staging'], true)) return $tag->name;
        }
        return null;
    };
@endphp

<div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Top 10 sites by 30-day visits</h2>
        <p class="text-xs text-[var(--color-ink-muted)] mt-1">
            Concentration check — if traffic skews to a handful of sites, capacity decisions follow.
            <span class="text-[var(--color-ink-soft)]">Visits = distinct IPs per UTC day, ex. 403s + static assets; not bot-filtered. CF-proxied sites under-count.</span>
        </p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
            <tr>
                <th class="px-5 py-2 text-left w-12">#</th>
                <th class="px-5 py-2 text-left">Site</th>
                <th class="px-5 py-2 text-left">Server</th>
                <th class="px-5 py-2 text-left">Tier</th>
                <th class="px-5 py-2 text-right">30d visits</th>
                <th class="px-5 py-2 text-right">% of fleet</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[var(--color-border-light)]">
            @forelse ($top['rows'] as $r)
                @php $tier = $tierFor($r['site']->server); @endphp
                <tr>
                    <td class="px-5 py-2 text-[var(--color-ink-soft)] font-data">{{ $r['rank'] }}</td>
                    <td class="px-5 py-2 font-data">
                        <a href="{{ route('sites.show', [$r['site'], 'traffic']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $r['site']->domain }}</a>
                    </td>
                    <td class="px-5 py-2 text-xs">
                        @if ($r['site']->server)
                            <a href="{{ route('servers.show', $r['site']->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $r['site']->server->name }}">{{ $r['site']->server->display_name }}</a>
                        @else
                            <span class="text-[var(--color-ink-soft)]">—</span>
                        @endif
                    </td>
                    <td class="px-5 py-2 text-xs">
                        @if ($tier)
                            <span class="status-pill status-unknown text-[10px]">{{ $tier }}</span>
                        @else
                            <span class="text-[var(--color-ink-soft)]">—</span>
                        @endif
                    </td>
                    <td class="px-5 py-2 text-right font-data">{{ number_format($r['visits_30d']) }}</td>
                    <td class="px-5 py-2 text-right font-data text-[var(--color-ink-muted)]">{{ number_format($r['pct'], 1) }}%</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-6 text-center text-[var(--color-ink-soft)]">No traffic rollups yet — needs the hourly rollup to have run.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if (! empty($top['rows']))
        <div class="px-5 py-3 bg-[var(--color-surface-alt)] text-xs text-[var(--color-ink-muted)] border-t border-[var(--color-border-light)]">
            Top 10 sites = <span class="text-[var(--color-ink-strong)] font-medium">{{ number_format($top['top10_pct_of_fleet'], 1) }}%</span> of total fleet visits.
            @if ($top['ratio_first_to_tenth'])
                #1 is <span class="text-[var(--color-ink-strong)] font-medium">{{ number_format($top['ratio_first_to_tenth'], 1) }}×</span> #10.
            @endif
            <span class="text-[var(--color-ink-soft)]">Fleet total: {{ number_format($top['fleet_visits_30d']) }} visits.</span>
        </div>
    @endif
</div>
