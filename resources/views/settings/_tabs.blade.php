@php
    $isAdmin = auth()->user()?->isAdmin() ?? false;

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
            'key' => 'system',
            'label' => 'System & Workspace',
            'icon' => 'fa-server',
            'route' => $isAdmin ? 'settings.users.index' : 'settings.maintenance.index',
            'active' => request()->routeIs('settings.users.*') || request()->routeIs('settings.updates.*') || request()->routeIs('settings.maintenance.*') || request()->routeIs('settings.diagnostics.*') || request()->routeIs('settings.weird-stats.*') || request()->routeIs('setup.*') || request()->routeIs('docs.*'),
        ],
    ];

    $activeKey = collect($tabs)->firstWhere('active', true)['key'] ?? null;

    $categoryTools = [
        'fleet' => [
            ['label' => 'White Labeling', 'icon' => 'fa-solid fa-paintbrush', 'route' => 'settings.companion.index', 'active' => request()->routeIs('settings.companion.*')],
            ['label' => 'Server Tags', 'icon' => 'fa-solid fa-tags', 'route' => 'settings.tags.index', 'active' => request()->routeIs('settings.tags.*')],
            ['label' => 'WordPress Plugins', 'icon' => 'fa-brands fa-wordpress', 'route' => 'settings.wordpress-plugins.index', 'active' => request()->routeIs('settings.wordpress-plugins.*')],
            ['label' => 'Scheduling & Ingest', 'icon' => 'fa-solid fa-clock-rotate-left', 'route' => 'settings.ingest.index', 'active' => request()->routeIs('settings.ingest.*')],
            ['label' => 'Security Scans', 'icon' => 'fa-solid fa-shield-halved', 'route' => 'settings.security-scans.index', 'active' => request()->routeIs('settings.security-scans.*')],
            ['label' => 'Backup Relay', 'icon' => 'fa-solid fa-cloud-arrow-up', 'route' => 'settings.backup-relay.index', 'active' => request()->routeIs('settings.backup-relay.*')],
        ],
        'integrations' => [
            ['label' => 'API Credentials', 'icon' => 'fa-solid fa-key', 'route' => 'settings.integrations.index', 'active' => request()->routeIs('settings.integrations.*')],
            ['label' => 'Module Directory', 'icon' => 'fa-solid fa-boxes-stacked', 'route' => 'settings.modules.index', 'active' => request()->routeIs('settings.modules.*')],
            ['label' => 'SMS Alerts', 'icon' => 'fa-solid fa-comment-sms', 'route' => 'settings.notifications.index', 'active' => request()->routeIs('settings.notifications.*')],
            ['label' => 'Slack Alerts', 'icon' => 'fa-brands fa-slack', 'route' => 'settings.slack.index', 'active' => request()->routeIs('settings.slack.*')],
            ['label' => 'Mattermost', 'icon' => 'fa-solid fa-comment-dots', 'route' => 'settings.mattermost.index', 'active' => request()->routeIs('settings.mattermost.*')],
            ['label' => 'Bill.com Sync', 'icon' => 'fa-solid fa-file-invoice-dollar', 'route' => 'settings.bill-com.index', 'active' => request()->routeIs('settings.bill-com.*')],
        ],
        'system' => array_values(array_filter([
            $isAdmin ? ['label' => 'Team & Users', 'icon' => 'fa-solid fa-people-group', 'route' => 'settings.users.index', 'active' => request()->routeIs('settings.users.*')] : null,
            ['label' => 'System Updates', 'icon' => 'fa-solid fa-arrows-rotate', 'route' => 'settings.updates.index', 'active' => request()->routeIs('settings.updates.*')],
            ['label' => 'Database Maintenance', 'icon' => 'fa-solid fa-database', 'route' => 'settings.maintenance.index', 'active' => request()->routeIs('settings.maintenance.*')],
            ['label' => 'Diagnostics & Health', 'icon' => 'fa-solid fa-stethoscope', 'route' => 'settings.diagnostics.index', 'active' => request()->routeIs('settings.diagnostics.*')],
            ['label' => 'Weird Stats', 'icon' => 'fa-solid fa-chart-pie', 'route' => 'settings.weird-stats.index', 'active' => request()->routeIs('settings.weird-stats.*')],
        ])),
    ];

    $currentTools = array_filter($categoryTools[$activeKey] ?? [], fn ($item) => Route::has($item['route']));
@endphp

<div class="mb-6 space-y-2.5">
    {{-- Tier 1: Core Settings Pillars --}}
    <div class="flex items-center gap-1 border-b border-[var(--color-border-light)] overflow-x-auto">
        @foreach ($tabs as $t)
            <a href="{{ route($t['route']) }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
                      {{ $t['active']
                            ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)] font-semibold'
                            : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
                <i class="fa-solid {{ $t['icon'] }} text-xs"></i>
                <span>{{ $t['label'] }}</span>
            </a>
        @endforeach
    </div>

    {{-- Tier 2: Category Tools Ribbon (visible when viewing a specific section) --}}
    @if (!empty($currentTools) && $activeKey !== 'hub')
        <div class="p-1 rounded-xl bg-[var(--color-surface-alt)]/70 border border-[var(--color-border-light)] flex items-center gap-1 overflow-x-auto text-xs">
            @foreach ($currentTools as $tool)
                <a href="{{ route($tool['route']) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all whitespace-nowrap
                          {{ $tool['active']
                                ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] font-semibold shadow-xs border border-[var(--color-border-light)]'
                                : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface)]/50' }}">
                    <i class="{{ $tool['icon'] }} text-[11px] {{ $tool['active'] ? 'text-[var(--color-brand)]' : 'text-[var(--color-ink-soft)]' }}"></i>
                    <span>{{ $tool['label'] }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
