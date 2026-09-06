@props([
    'key' => null,
    'align' => 'left',
])

@php
    $alignClass = match ($align) {
        'right' => 'text-right',
        'center' => 'text-center',
        default => 'text-left',
    };
@endphp

<th {{ $attributes->merge(['class' => "$alignClass px-4 py-2 select-none cursor-pointer hover:text-[var(--color-ink-strong)]"]) }}
    @click="sortBy('{{ $key }}')"
    role="button">
    @if ($align === 'right')
        <span class="inline-flex items-center gap-1.5">
            {{ $slot }}
            <i class="fa-solid text-[10px]"
               :class="[indicator('{{ $key }}'), isActive('{{ $key }}') ? 'text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-soft)]']"></i>
        </span>
    @else
        <span class="inline-flex items-center gap-1.5">
            {{ $slot }}
            <i class="fa-solid text-[10px]"
               :class="[indicator('{{ $key }}'), isActive('{{ $key }}') ? 'text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-soft)]']"></i>
        </span>
    @endif
</th>
