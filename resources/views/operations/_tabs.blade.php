@php
    $tools = [
        [
            'label' => 'Capacity',
            'icon' => 'fa-solid fa-gauge-high',
            'route' => 'capacity.index',
            'active' => request()->routeIs('capacity.*'),
        ],
        [
            'label' => 'Fleet Updates',
            'icon' => 'fa-solid fa-cube',
            'route' => 'operations.server-updates.index',
            'active' => request()->routeIs('operations.server-updates.*'),
        ],
        [
            'label' => 'Maintenance History',
            'icon' => 'fa-solid fa-clock-rotate-left',
            'route' => 'maintenance-history.index',
            'active' => request()->routeIs('maintenance-history.*'),
        ],
        [
            'label' => 'SSH Credentials',
            'icon' => 'fa-solid fa-key',
            'route' => 'servers.credentials.bulk',
            'active' => request()->routeIs('servers.credentials.*'),
        ],
    ];
@endphp

<div class="mb-6">
    <div class="flex items-center gap-1 border-b border-[var(--color-border-light)] overflow-x-auto">
        @foreach ($tools as $tool)
            <a href="{{ route($tool['route']) }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
                      {{ $tool['active']
                            ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)] font-semibold'
                            : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
                <i class="{{ $tool['icon'] }} text-xs {{ $tool['active'] ? 'text-[var(--color-brand)]' : '' }}"></i>
                <span>{{ $tool['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
