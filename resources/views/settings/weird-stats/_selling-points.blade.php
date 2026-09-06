@php
    $cf = $sellingPoints['cf_attack_reduction'];
    $auto = $sellingPoints['auto_ban_speedup'];
    $tiers = collect($sellingPoints['tier_density'])->keyBy('tier');
    $self = $sellingPoints['self_ban_prevention'];

    $secondsHuman = function (?float $s): string {
        if ($s === null) return '—';
        if ($s < 60) return number_format($s, 1).'s';
        if ($s < 3600) return number_format($s / 60, 1).'m';
        if ($s < 86400) return number_format($s / 3600, 1).'h';
        return number_format($s / 86400, 1).'d';
    };

    $dedicated = $tiers->get('Dedicated');
    $shared = $tiers->get('Shared');
    $tierRatio = ($dedicated && $shared && $dedicated['avg_sites_per_server'] > 0)
        ? $shared['avg_sites_per_server'] / $dedicated['avg_sites_per_server']
        : null;
@endphp

<div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Setup value — what your choices are doing for you</h2>
        <p class="text-xs text-[var(--color-ink-muted)] mt-1">Quantified value of CF proxy, auto-ban, dedicated tier, and self-ban defense. Use these when defending the cost of the setup to clients.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-px bg-[var(--color-border-light)]">

        {{-- 1. Cloudflare effectiveness --}}
        <div class="bg-[var(--color-surface)] px-5 py-5">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] mb-2">
                <i class="fa-solid fa-cloud mr-1"></i> Cloudflare proxy effectiveness
            </div>
            @if ($cf)
                @if ($cf['pct_reduction'] > 0)
                    <div class="text-3xl font-display text-[var(--color-status-green)] mb-1">
                        {{ number_format($cf['pct_reduction'], 1) }}%
                    </div>
                    <div class="text-sm text-[var(--color-ink-strong)] mb-2">
                        fewer attacks reach the origin on CF-proxied sites
                    </div>
                @else
                    <div class="text-3xl font-display text-[var(--color-status-yellow)] mb-1">
                        {{ number_format(abs($cf['pct_reduction']), 1) }}% more
                    </div>
                    <div class="text-sm text-[var(--color-ink-strong)] mb-2">
                        attacks at origin on CF-proxied sites (likely sample-size noise — see numbers below)
                    </div>
                @endif
                <div class="text-xs text-[var(--color-ink-muted)] space-y-1">
                    <div>CF-proxied: <span class="font-data">{{ number_format($cf['proxied_per_site'], 0) }}</span> origin attacks/site/week ({{ $cf['sites_proxied'] }} sites)</div>
                    <div>No CF: <span class="font-data">{{ number_format($cf['not_using_per_site'], 0) }}</span> origin attacks/site/week ({{ $cf['sites_not_using'] }} sites)</div>
                    <div class="text-[var(--color-ink-soft)] italic">This is origin-only — the real CF win is the much-bigger pile blocked at the edge that we can't see.</div>
                </div>
            @else
                <div class="text-sm text-[var(--color-ink-soft)]">Need at least one CF-proxied AND one non-CF WP site to compare. Skipped.</div>
            @endif
        </div>

        {{-- 2. Auto-ban coverage (NOT a speedup story — auto-repeat waits for 2nd
             sighting, so it's not strictly faster than manual. The value prop is
             coverage: the operator didn't have to click these.) --}}
        <div class="bg-[var(--color-surface)] px-5 py-5">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] mb-2">
                <i class="fa-solid fa-bolt mr-1"></i> Auto-protection coverage
            </div>
            @php
                $totalBans = ($auto['auto_count'] ?? 0) + ($auto['manual_count'] ?? 0);
                $autoPct = $totalBans > 0 ? (($auto['auto_count'] ?? 0) / $totalBans) * 100 : 0;
            @endphp
            @if ($auto && $auto['auto_count'] > 0)
                <div class="text-3xl font-display text-[var(--color-status-green)] mb-1">
                    {{ number_format($auto['auto_count']) }}
                </div>
                <div class="text-sm text-[var(--color-ink-strong)] mb-2">
                    IPs auto-banned without your involvement (last 30d) — {{ number_format($autoPct, 0) }}% of all bans
                </div>
                <div class="text-xs text-[var(--color-ink-muted)] space-y-1">
                    <div>Auto-repeat avg: <span class="font-data">{{ $secondsHuman($auto['auto_avg_seconds']) }}</span> from queue entry to ban</div>
                    @if ($auto['manual_count'] > 0)
                        <div>Manual review avg: <span class="font-data">{{ $secondsHuman($auto['manual_avg_seconds']) }}</span> ({{ number_format($auto['manual_count']) }} bans)</div>
                    @endif
                    <div class="text-[var(--color-ink-soft)] italic">Auto-repeat needs a 2nd sighting to fire, so it's not strictly faster than a fast operator. The win is hands-off coverage.</div>
                </div>
            @elseif ($auto && $auto['manual_count'] > 0)
                <div class="text-3xl font-display text-[var(--color-ink-strong)] mb-1">0</div>
                <div class="text-sm text-[var(--color-ink-strong)] mb-2">auto-bans in the last 30 days — auto-approve-repeats is off</div>
                <div class="text-xs text-[var(--color-ink-muted)]">
                    You manually reviewed <span class="font-data">{{ number_format($auto['manual_count']) }}</span> bans (avg {{ $secondsHuman($auto['manual_avg_seconds']) }} from queue entry to ban). Flip the toggle on /review to let the system handle repeat offenders without you.
                </div>
            @else
                <div class="text-sm text-[var(--color-ink-soft)]">No bans decided in the last 30 days yet.</div>
            @endif
        </div>

        {{-- 3. Tier density --}}
        <div class="bg-[var(--color-surface)] px-5 py-5">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] mb-2">
                <i class="fa-solid fa-server mr-1"></i> Dedicated tier isolation
            </div>
            @if ($dedicated && $shared && $tierRatio)
                <div class="text-3xl font-display text-[var(--color-status-green)] mb-1">
                    {{ number_format($tierRatio, 1) }}× headroom
                </div>
                <div class="text-sm text-[var(--color-ink-strong)] mb-2">
                    per-site on Dedicated vs. Shared tier
                </div>
                <div class="text-xs text-[var(--color-ink-muted)] space-y-1">
                    <div>Dedicated: <span class="font-data">{{ number_format($dedicated['avg_sites_per_server'], 1) }}</span> sites/server ({{ $dedicated['servers'] }} servers, {{ $dedicated['sites'] }} sites)</div>
                    <div>Shared: <span class="font-data">{{ number_format($shared['avg_sites_per_server'], 1) }}</span> sites/server ({{ $shared['servers'] }} servers, {{ $shared['sites'] }} sites)</div>
                </div>
            @else
                <div class="text-sm text-[var(--color-ink-soft)]">Need at least one Dedicated AND one Shared server with sites to compare.</div>
                @if ($dedicated)
                    <div class="text-xs text-[var(--color-ink-muted)] mt-2">Dedicated: {{ $dedicated['servers'] }} servers, {{ $dedicated['sites'] }} sites</div>
                @endif
                @if ($shared)
                    <div class="text-xs text-[var(--color-ink-muted)]">Shared: {{ $shared['servers'] }} servers, {{ $shared['sites'] }} sites</div>
                @endif
            @endif
        </div>

        {{-- 4. Self-ban prevention --}}
        <div class="bg-[var(--color-surface)] px-5 py-5">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] mb-2">
                <i class="fa-solid fa-shield-halved mr-1"></i> Self-ban prevention
            </div>
            @if ($self['filtered_at_ingest'] > 0)
                <div class="text-3xl font-display text-[var(--color-status-green)] mb-1">
                    {{ number_format($self['filtered_at_ingest']) }}
                </div>
                <div class="text-sm text-[var(--color-ink-strong)] mb-2">
                    would-be self-inflicted bans caught at ingest (last 30d)
                </div>
            @else
                <div class="text-3xl font-display text-[var(--color-status-green)] mb-1">0</div>
                <div class="text-sm text-[var(--color-ink-strong)] mb-2">protected IPs sneaking into review queue this month</div>
            @endif
            <div class="text-xs text-[var(--color-ink-muted)] space-y-1">
                <div>Active jails on <span class="font-data">{{ $self['fleet_servers'] }}</span> servers</div>
                <div>Whitelist covers <span class="font-data">{{ $self['cf_ranges_covered'] }}</span> Cloudflare CIDRs + every fleet IP</div>
                <div class="text-[var(--color-ink-soft)] italic">Without this, banning a CF edge takes a CF-proxied site offline.</div>
            </div>
        </div>

    </div>
</div>
