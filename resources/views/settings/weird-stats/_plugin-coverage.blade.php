@php
    // Sort the rows so proxied sits up top, then dns_only, then not_using, then unknown.
    $order = ['proxied' => 0, 'dns_only' => 1, 'not_using' => 2, 'unknown' => 3];
    $sorted = collect($rows)->sortBy(fn ($r) => $order[$r['cf_state']] ?? 99)->values();

    $pct = fn ($n, $total) => $total > 0 ? round(($n / $total) * 100, 1) : 0;
    $cfLabel = fn ($state) => match ($state) {
        'proxied' => ['label' => 'Cloudflare proxied', 'class' => 'status-cf', 'icon' => 'fa-cloud'],
        'dns_only' => ['label' => 'CF DNS only', 'class' => 'status-yellow', 'icon' => 'fa-cloud'],
        'not_using' => ['label' => 'No Cloudflare', 'class' => 'status-unknown', 'icon' => 'fa-cloud-slash'],
        default => ['label' => 'Unknown', 'class' => 'status-unknown', 'icon' => 'fa-circle-question'],
    };
@endphp

<div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Plugin coverage by Cloudflare state</h2>
        <p class="text-xs text-[var(--color-ink-muted)] mt-1">Are CF-proxied sites better-protected than dns_only or non-CF ones? Red = real exposure (no plugin at all).</p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
            <tr>
                <th class="px-5 py-2 text-left">CF state</th>
                <th class="px-5 py-2 text-right">Sites</th>
                <th class="px-5 py-2 text-right">LLAR</th>
                <th class="px-5 py-2 text-right">Wordfence</th>
                <th class="px-5 py-2 text-right">Both</th>
                <th class="px-5 py-2 text-right">Neither</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[var(--color-border-light)]">
            @forelse ($sorted as $row)
                @php $meta = $cfLabel($row['cf_state']); @endphp
                <tr>
                    <td class="px-5 py-2">
                        <span class="status-pill {{ $meta['class'] }}">
                            <i class="fa-solid {{ $meta['icon'] }}"></i> {{ $meta['label'] }}
                        </span>
                    </td>
                    <td class="px-5 py-2 text-right font-data">{{ number_format($row['total']) }}</td>
                    <td class="px-5 py-2 text-right">
                        <span class="font-data">{{ $pct($row['llar'], $row['total']) }}%</span>
                        <span class="text-xs text-[var(--color-ink-soft)] ml-1">({{ $row['llar'] }})</span>
                    </td>
                    <td class="px-5 py-2 text-right">
                        <span class="font-data">{{ $pct($row['wf'], $row['total']) }}%</span>
                        <span class="text-xs text-[var(--color-ink-soft)] ml-1">({{ $row['wf'] }})</span>
                    </td>
                    <td class="px-5 py-2 text-right">
                        <span class="font-data text-[var(--color-status-green)]">{{ $pct($row['both'], $row['total']) }}%</span>
                        <span class="text-xs text-[var(--color-ink-soft)] ml-1">({{ $row['both'] }})</span>
                    </td>
                    <td class="px-5 py-2 text-right">
                        @if ($row['neither'] > 0)
                            <span class="font-data text-[var(--color-status-red)] font-semibold">{{ $pct($row['neither'], $row['total']) }}%</span>
                            <span class="text-xs text-[var(--color-status-red)] ml-1">({{ $row['neither'] }})</span>
                        @else
                            <span class="font-data text-[var(--color-ink-soft)]">0%</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-6 text-center text-[var(--color-ink-soft)]">No site data yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
