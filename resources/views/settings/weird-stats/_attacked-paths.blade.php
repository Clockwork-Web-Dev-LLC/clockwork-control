@php
    $statusBadge = function ($class, $count) {
        $color = match ($class) {
            '2xx' => 'text-[var(--color-status-green)]',
            '3xx' => 'text-[var(--color-ink-muted)]',
            '4xx' => 'text-[var(--color-status-yellow)]',
            '5xx' => 'text-[var(--color-status-red)]',
            default => 'text-[var(--color-ink-soft)]',
        };
        return '<span class="' . $color . ' text-xs font-data mr-2">' . $class . ':' . number_format($count) . '</span>';
    };
@endphp

<div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Most-attacked request paths (last 7 days)</h2>
        <p class="text-xs text-[var(--color-ink-muted)] mt-1">What scanners are targeting fleet-wide. Heavy 4xx mix = scanner probes; heavy 2xx mix = something else going on.</p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
            <tr>
                <th class="px-5 py-2 text-left w-12">#</th>
                <th class="px-5 py-2 text-left">Path</th>
                <th class="px-5 py-2 text-right">Hits</th>
                <th class="px-5 py-2 text-right">Sites</th>
                <th class="px-5 py-2 text-right">IPs</th>
                <th class="px-5 py-2 text-left">Status mix</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[var(--color-border-light)]">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-5 py-2 text-[var(--color-ink-soft)] font-data">{{ $r['rank'] }}</td>
                    <td class="px-5 py-2 font-data text-xs">
                        <span class="break-all" title="{{ $r['path'] }}">{{ \Illuminate\Support\Str::limit($r['path'], 80) }}</span>
                    </td>
                    <td class="px-5 py-2 text-right font-data">{{ number_format($r['hits']) }}</td>
                    <td class="px-5 py-2 text-right font-data text-[var(--color-ink-muted)]">{{ number_format($r['sites']) }}</td>
                    <td class="px-5 py-2 text-right font-data text-[var(--color-ink-muted)]">{{ number_format($r['ips']) }}</td>
                    <td class="px-5 py-2">
                        @foreach ($r['status_codes'] as $class => $count)
                            {!! $statusBadge($class, $count) !!}
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-6 text-center text-[var(--color-ink-soft)]">No threat logs in the last 7 days — needs nginx tail to have ingested.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
