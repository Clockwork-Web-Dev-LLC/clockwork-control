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
            'key' => 'branding',
            'label' => 'Agency Branding',
            'icon' => 'fa-paintbrush',
            'route' => 'settings.companion.index',
            'active' => request()->routeIs('settings.companion.*'),
        ],
        [
            'key' => 'fleet',
            'label' => 'Fleet Policies',
            'icon' => 'fa-sliders',
            'route' => 'settings.wordpress-plugins.index',
            'active' => request()->routeIs('settings.wordpress-plugins.*') || request()->routeIs('settings.ingest.*') || request()->routeIs('settings.security-scans.*') || request()->routeIs('settings.backup-relay.*') || request()->routeIs('settings.care-plans.*') || request()->routeIs('settings.gatekeeper.*') || request()->routeIs('settings.tags.*'),
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
            'active' => request()->routeIs('settings.users.*') || request()->routeIs('settings.updates.*') || request()->routeIs('settings.maintenance.*') || request()->routeIs('settings.diagnostics.*') || request()->routeIs('settings.weird-stats.*') || request()->routeIs('settings.scheduled-jobs.*') || request()->routeIs('setup.*') || request()->routeIs('docs.*'),
        ],
    ];

    $activeKey = collect($tabs)->firstWhere('active', true)['key'] ?? null;

    $categoryTools = [
        'branding' => [
            ['label' => 'White Label & Styling Hub', 'icon' => 'fa-solid fa-paintbrush', 'route' => 'settings.companion.index', 'active' => request()->routeIs('settings.companion.*')],
        ],
        'fleet' => [
            ['label' => 'WordPress Plugins', 'icon' => 'fa-brands fa-wordpress', 'route' => 'settings.wordpress-plugins.index', 'active' => request()->routeIs('settings.wordpress-plugins.*')],
            ['label' => 'Scheduling & Ingest', 'icon' => 'fa-solid fa-clock-rotate-left', 'route' => 'settings.ingest.index', 'active' => request()->routeIs('settings.ingest.*')],
            ['label' => 'Security Scans', 'icon' => 'fa-solid fa-shield-halved', 'route' => 'settings.security-scans.index', 'active' => request()->routeIs('settings.security-scans.*')],
            ['label' => 'Backup Relay', 'icon' => 'fa-solid fa-cloud-arrow-up', 'route' => 'settings.backup-relay.index', 'active' => request()->routeIs('settings.backup-relay.*')],
            ['label' => 'Care Plans', 'icon' => 'fa-solid fa-shield-heart', 'route' => 'settings.care-plans.index', 'active' => request()->routeIs('settings.care-plans.*')],
            ['label' => 'Login Lockouts', 'icon' => 'fa-solid fa-lock', 'route' => 'settings.gatekeeper.index', 'active' => request()->routeIs('settings.gatekeeper.*')],
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
            ['label' => 'Scheduled Jobs', 'icon' => 'fa-solid fa-clock', 'route' => 'settings.scheduled-jobs.index', 'active' => request()->routeIs('settings.scheduled-jobs.*')],
            ['label' => 'Weird Stats', 'icon' => 'fa-solid fa-chart-pie', 'route' => 'settings.weird-stats.index', 'active' => request()->routeIs('settings.weird-stats.*')],
        ])),
    ];

    $currentTools = array_filter($categoryTools[$activeKey] ?? [], fn ($item) => Route::has($item['route']));
    $activeTab = collect($tabs)->firstWhere('active', true) ?? $tabs[0];
@endphp

<div class="mb-6 space-y-2.5">
    {{-- Phone / tablet: the five pillar names do not fit a tab row. A full-width
         picker lists every section so nothing is clipped off-screen. --}}
    <div class="lg:hidden" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
        <button type="button"
                class="settings-pillar-picker w-full flex items-center justify-between gap-3 px-3.5 py-2.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-left shadow-xs"
                @click="open = !open"
                :aria-expanded="open"
                aria-controls="settings-pillar-menu"
                aria-label="Settings section">
            <span class="inline-flex items-center gap-2.5 min-w-0">
                <i class="fa-solid {{ $activeTab['icon'] }} text-xs text-[var(--color-brand)] shrink-0"></i>
                <span class="truncate text-sm font-semibold text-[var(--color-ink-strong)]">{{ $activeTab['label'] }}</span>
            </span>
            <i class="fa-solid fa-chevron-down text-[11px] text-[var(--color-ink-muted)] shrink-0 transition-transform" :class="open && 'rotate-180'"></i>
        </button>
        <div id="settings-pillar-menu"
             x-show="open"
             x-cloak
             class="mt-1.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface)] shadow-lg overflow-hidden">
            @foreach ($tabs as $t)
                <a href="{{ route($t['route']) }}"
                   class="flex items-center gap-2.5 px-3.5 py-2.5 text-sm border-b border-[var(--color-border-light)] last:border-b-0
                          {{ $t['active']
                                ? 'bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] font-semibold'
                                : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]/70 hover:text-[var(--color-ink-strong)]' }}">
                    <i class="fa-solid {{ $t['icon'] }} text-xs w-4 text-center {{ $t['active'] ? 'text-[var(--color-brand)]' : 'text-[var(--color-ink-soft)]' }}"></i>
                    <span>{{ $t['label'] }}</span>
                    @if ($t['active'])
                        <i class="fa-solid fa-check ml-auto text-[11px] text-[var(--color-brand)]"></i>
                    @endif
                </a>
            @endforeach
        </div>
    </div>

    {{-- Desktop: underline tab row --}}
    <div class="hidden lg:flex items-center gap-1 border-b border-[var(--color-border-light)]">
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

    {{-- Tier 2: Category Tools Ribbon (visible when viewing a multi-tool section) --}}
    @if (!empty($currentTools) && count($currentTools) > 1 && $activeKey !== 'hub')
        <div class="p-1 rounded-xl bg-[var(--color-surface-alt)]/70 border border-[var(--color-border-light)] flex flex-wrap items-center gap-1 text-xs">
            @foreach ($currentTools as $tool)
                <a href="{{ route($tool['route']) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all
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
