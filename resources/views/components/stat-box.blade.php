{{-- Standard Metric / Stat Box component.
     Renders a clean KPI tile with optional icon and subtext.
     Example: <x-stat-box label="Total Sites" :value="142" icon="fa-globe" /> --}}
@props([
    'label',
    'value',
    'icon' => null,
    'subtext' => null,
    'trend' => null,
])

<div {{ $attributes->merge(['class' => 'card p-5']) }}>
    <div class="flex items-center justify-between gap-2 mb-2">
        <span class="text-xs uppercase tracking-wider font-semibold text-[var(--color-ink-muted)]">{{ $label }}</span>
        @if ($icon)
            <i class="fa-solid {{ $icon }} text-[var(--color-ink-soft)] text-sm"></i>
        @endif
    </div>
    <div class="font-display text-2xl font-bold text-[var(--color-ink-strong)] tracking-tight">
        {{ $value }}
    </div>
    @if ($subtext || $trend)
        <div class="mt-2 text-xs text-[var(--color-ink-soft)] flex items-center gap-1.5">
            @if ($trend)
                <span class="{{ str_starts_with((string)$trend, '+') ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]' }} font-medium">
                    {{ $trend }}
                </span>
            @endif
            @if ($subtext)
                <span>{{ $subtext }}</span>
            @endif
        </div>
    @endif
</div>
