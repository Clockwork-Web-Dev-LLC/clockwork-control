<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="card px-4 py-3">
        <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Total WP sites</div>
        <div class="text-2xl font-display text-[var(--color-ink-strong)]">{{ number_format($summary['total_sites']) }}</div>
    </div>
    <div class="card px-4 py-3">
        <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Attacks (7d)</div>
        <div class="text-2xl font-display text-[var(--color-ink-strong)]">{{ number_format($summary['attacks_7d']) }}</div>
    </div>
    <div class="card px-4 py-3">
        <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Active bans</div>
        <div class="text-2xl font-display text-[var(--color-ink-strong)]">{{ number_format($summary['active_bans']) }}</div>
    </div>
    <div class="card px-4 py-3">
        <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Unprotected sites</div>
        <div class="text-2xl font-display {{ $summary['unprotected_count'] > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-status-green)]' }}">
            {{ number_format($summary['unprotected_count']) }}
        </div>
    </div>
</div>
