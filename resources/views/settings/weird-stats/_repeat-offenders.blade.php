<div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Worst repeat-offender IPs by fleet reach</h2>
        <p class="text-xs text-[var(--color-ink-muted)] mt-1">Ranked by distinct servers an IP has been banned on. Coordinated campaigns hit multiple targets.</p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
            <tr>
                <th class="px-5 py-2 text-left">IP</th>
                <th class="px-5 py-2 text-right">Servers hit</th>
                <th class="px-5 py-2 text-right">Sites hit</th>
                <th class="px-5 py-2 text-right">Total bans</th>
                <th class="px-5 py-2 text-left">First seen</th>
                <th class="px-5 py-2 text-left">Last seen</th>
                <th class="px-5 py-2 text-left">Decided by</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[var(--color-border-light)]">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-5 py-2 font-data">
                        <a href="https://ipinfo.io/{{ $r['ip'] }}" target="_blank" rel="noopener" class="text-[var(--color-primary-600)] hover:underline">{{ $r['ip'] }}</a>
                    </td>
                    <td class="px-5 py-2 text-right font-data">{{ number_format($r['servers_hit']) }}</td>
                    <td class="px-5 py-2 text-right font-data text-[var(--color-ink-muted)]">{{ number_format($r['sites_hit']) }}</td>
                    <td class="px-5 py-2 text-right font-data text-[var(--color-ink-muted)]">{{ number_format($r['total_bans']) }}</td>
                    <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)]">{{ $r['first_seen']?->diffForHumans() ?? '—' }}</td>
                    <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)]">{{ $r['last_seen']?->diffForHumans() ?? '—' }}</td>
                    <td class="px-5 py-2">
                        @foreach ($r['decided_by'] as $by => $count)
                            <span class="text-xs font-data text-[var(--color-ink-muted)] mr-2">{{ $by }}:{{ $count }}</span>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-5 py-6 text-center text-[var(--color-ink-soft)]">No banned IPs yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
