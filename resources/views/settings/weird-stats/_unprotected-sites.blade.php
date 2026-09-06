@php
    $tierFor = function ($server) {
        if (! $server) return null;
        foreach ($server->tags ?? [] as $tag) {
            if (in_array($tag->name, ['Dedicated', 'Shared', 'Staging'], true)) return $tag->name;
        }
        return null;
    };
    $cfPill = function ($state) {
        return match ($state) {
            'proxied' => ['class' => 'status-cf', 'label' => 'CF proxied', 'icon' => 'fa-cloud'],
            'dns_only' => ['class' => 'status-yellow', 'label' => 'DNS only', 'icon' => 'fa-cloud'],
            'not_using' => ['class' => 'status-unknown', 'label' => 'No CF', 'icon' => 'fa-cloud-slash'],
            default => ['class' => 'status-unknown', 'label' => 'Unknown', 'icon' => 'fa-circle-question'],
        };
    };
@endphp

<div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Unprotected sites (LLAR + Wordfence both off)</h2>
        <p class="text-xs text-[var(--color-ink-muted)] mt-1">
            Sorted by 30-day visits — high-traffic unprotected sites are real risk; 0-visit are probably parked.
            <span class="text-[var(--color-ink-soft)]">Visits = distinct IPs per UTC day, ex. 403s + static assets; not bot-filtered.</span>
        </p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
            <tr>
                <th class="px-5 py-2 text-left">Site</th>
                <th class="px-5 py-2 text-left">Server</th>
                <th class="px-5 py-2 text-left">Tier</th>
                <th class="px-5 py-2 text-left">CF state</th>
                <th class="px-5 py-2 text-right">30d visits</th>
                <th class="px-5 py-2 text-right">Last probe</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[var(--color-border-light)]">
            @forelse ($sites as $site)
                @php
                    $tier = $tierFor($site->server);
                    $cf = $cfPill($site->cloudflare_state);
                    $highRisk = ($site->visits_30d ?? 0) > 1000;
                @endphp
                <tr class="{{ $highRisk ? 'bg-[rgba(194,46,46,0.04)]' : '' }}">
                    <td class="px-5 py-2 font-data">
                        <a href="{{ route('sites.show', [$site, 'settings']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                    </td>
                    <td class="px-5 py-2 text-xs">
                        @if ($site->server)
                            <a href="{{ route('servers.show', $site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $site->server->name }}">{{ $site->server->display_name }}</a>
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
                    <td class="px-5 py-2">
                        <span class="status-pill {{ $cf['class'] }} text-[10px]">
                            <i class="fa-solid {{ $cf['icon'] }}"></i> {{ $cf['label'] }}
                        </span>
                    </td>
                    <td class="px-5 py-2 text-right font-data {{ $highRisk ? 'text-[var(--color-status-red)] font-semibold' : '' }}">
                        {{ number_format($site->visits_30d ?? 0) }}
                    </td>
                    <td class="px-5 py-2 text-right text-xs text-[var(--color-ink-soft)]">
                        @if ($site->days_since_probe !== null)
                            {{ $site->days_since_probe }}d ago
                        @else
                            never
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-6 text-center text-[var(--color-status-green)]">All WordPress sites have at least one protection plugin enabled.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
