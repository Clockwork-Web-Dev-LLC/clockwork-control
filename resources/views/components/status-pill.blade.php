{{-- Standard Status Pill component.
     Renders a themed semantic status badge with icon and label.
     Example: <x-status-pill status="success" icon="fa-circle-check">Active</x-status-pill> --}}
@props([
    'status' => 'neutral',
    'icon' => null,
    'pulse' => false,
])

@php
    $statusMap = [
        'green' => 'status-green',
        'success' => 'status-green',
        'active' => 'status-green',
        'yellow' => 'status-yellow',
        'warning' => 'status-yellow',
        'pending' => 'status-yellow',
        'red' => 'status-red',
        'danger' => 'status-red',
        'error' => 'status-red',
        'gray' => 'status-gray',
        'neutral' => 'status-gray',
        'inactive' => 'status-gray',
        'blue' => 'status-blue',
        'info' => 'status-blue',
    ];

    $resolvedClass = $statusMap[strtolower($status)] ?? 'status-gray';
@endphp

<span {{ $attributes->merge(['class' => "status-pill {$resolvedClass} text-xs inline-flex items-center gap-1.5 font-medium px-2 py-0.5 rounded-full"]) }}>
    @if ($pulse)
        <span class="relative flex h-2 w-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 bg-current"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-current"></span>
        </span>
    @elseif ($icon)
        <i class="fa-solid {{ $icon }} text-[10px]"></i>
    @endif
    <span>{{ $slot }}</span>
</span>
