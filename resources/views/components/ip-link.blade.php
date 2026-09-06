@props([
    'ip' => null,
])

@php
    $ip = trim((string) ($ip ?? $slot));
@endphp

<span class="inline-flex items-center gap-1.5 whitespace-nowrap">
    <span class="font-data">{{ $ip }}</span>
    @if (filter_var($ip, FILTER_VALIDATE_IP))
        <a href="https://ipinfo.io/{{ $ip }}" target="_blank" rel="noopener noreferrer"
           class="text-[var(--color-ink-soft)] hover:text-[var(--color-primary-600)]"
           title="Open geo info for {{ $ip }} on ipinfo.io"
           onclick="event.stopPropagation()">
            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
        </a>
    @endif
</span>
