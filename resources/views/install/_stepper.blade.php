@php
    $steps = [
        1 => 'Welcome',
        2 => 'Database',
        3 => 'App',
        4 => 'Mail',
        5 => 'Google OAuth',
        6 => 'Admin',
        7 => 'Hosting',
        8 => 'Review',
    ];
@endphp

<div class="card p-3 mb-6 overflow-x-auto shadow-sm">
    <div class="flex items-center justify-between min-w-[620px] px-2">
        @foreach ($steps as $num => $label)
            @php
                $isPast = $num < $currentStep;
                $isCurrent = $num === $currentStep;
            @endphp
            <div class="flex items-center {{ $num < count($steps) ? 'flex-1' : '' }}">
                <div class="flex items-center gap-2 group">
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold transition-all
                        {{ $isPast ? 'bg-[var(--color-brand)] text-white' : '' }}
                        {{ $isCurrent ? 'bg-[var(--color-brand)] text-white ring-4 ring-[var(--color-brand)]/20 shadow' : '' }}
                        {{ ! $isPast && ! $isCurrent ? 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] border border-[var(--color-border-light)]' : '' }}">
                        @if ($isPast)
                            <i class="fa-solid fa-check text-[10px]"></i>
                        @else
                            {{ $num }}
                        @endif
                    </div>
                    <span class="text-xs font-medium whitespace-nowrap
                        {{ $isCurrent ? 'text-[var(--color-brand)] font-semibold' : '' }}
                        {{ $isPast ? 'text-[var(--color-ink-strong)]' : '' }}
                        {{ ! $isPast && ! $isCurrent ? 'text-[var(--color-ink-soft)]' : '' }}">
                        {{ $label }}
                    </span>
                </div>
                @if ($num < count($steps))
                    <div class="flex-1 h-0.5 mx-2 {{ $num < $currentStep ? 'bg-[var(--color-brand)]' : 'bg-[var(--color-border-light)]' }}"></div>
                @endif
            </div>
        @endforeach
    </div>
</div>
