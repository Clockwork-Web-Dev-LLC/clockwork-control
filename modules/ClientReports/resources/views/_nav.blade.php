@php
    $navTabs = [
        [
            'key' => 'reports',
            'label' => 'Reports',
            'icon' => 'fa-file-lines',
            'route' => 'client-reports.index',
            'active' => request()->routeIs('client-reports.index'),
        ],
        [
            'key' => 'templates',
            'label' => 'Templates',
            'icon' => 'fa-layer-group',
            'route' => 'client-reports.templates.index',
            'active' => request()->routeIs('client-reports.templates.*'),
        ],
        [
            'key' => 'schedules',
            'label' => 'Scheduling',
            'icon' => 'fa-clock',
            'route' => 'client-reports.schedules.index',
            'active' => request()->routeIs('client-reports.schedules.*'),
        ],
    ];
@endphp

<div class="cw-tab-bar mb-6">
    @foreach ($navTabs as $tab)
        <a href="{{ route($tab['route']) }}"
           class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
                  {{ $tab['active']
                        ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]'
                        : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
            <i class="fa-solid {{ $tab['icon'] }} text-xs"></i>
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
</div>
