@php
    $tabs = [
        [
            'key' => 'hub',
            'label' => 'Overview',
            'icon' => 'fa-grip',
            'route' => 'settings.index',
            'active' => request()->routeIs('settings.index'),
        ],
        [
            'key' => 'fleet',
            'label' => 'Fleet & Branding',
            'icon' => 'fa-sliders',
            'route' => 'settings.companion.index',
            'active' => request()->routeIs('settings.companion.*') || request()->routeIs('settings.tags.*') || request()->routeIs('settings.wordpress-plugins.*') || request()->routeIs('settings.ingest.*') || request()->routeIs('settings.security-scans.*') || request()->routeIs('settings.backup-relay.*'),
        ],
        [
            'key' => 'integrations',
            'label' => 'Integrations & Alerts',
            'icon' => 'fa-plug',
            'route' => 'settings.integrations.index',
            'active' => request()->routeIs('settings.integrations.*') || request()->routeIs('settings.modules.*') || request()->routeIs('settings.notifications.*') || request()->routeIs('settings.slack.*') || request()->routeIs('settings.mattermost.*') || request()->routeIs('settings.bill-com.*'),
        ],
        [
            'key' => 'operations',
            'label' => 'Operations & Tools',
            'icon' => 'fa-toolbox',
            'route' => 'capacity.index',
            'active' => request()->routeIs('capacity.*') || request()->routeIs('operations.*') || request()->routeIs('maintenance-history.*') || request()->routeIs('servers.credentials.*') || request()->routeIs('settings.weird-stats.*'),
        ],
        [
            'key' => 'system',
            'label' => 'System & Workspace',
            'icon' => 'fa-server',
            'route' => 'settings.users.index',
            'active' => request()->routeIs('settings.users.*') || request()->routeIs('settings.updates.*') || request()->routeIs('settings.maintenance.*') || request()->routeIs('settings.diagnostics.*') || request()->routeIs('setup.*') || request()->routeIs('docs.*'),
        ],
    ];
@endphp

<div class="flex items-center gap-1 mb-6 border-b border-[var(--color-border-light)] overflow-x-auto">
    @foreach ($tabs as $t)
        <a href="{{ route($t['route']) }}"
           class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
                  {{ $t['active']
                        ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]'
                        : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
            <i class="fa-solid {{ $t['icon'] }} text-xs"></i>
            <span>{{ $t['label'] }}</span>
        </a>
    @endforeach
</div>
