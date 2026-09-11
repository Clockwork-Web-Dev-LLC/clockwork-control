@php
    // Shared tab strip for the unified Security section. Renders on /security/scans
    // (scans subtab) and /bans/* (queue/active/history subtabs). Each calling view
    // sets $activeTab to one of: 'scans', 'queue', 'active', 'history'.
    $tabs = [
        ['key' => 'scans',   'label' => 'Scans',   'route' => 'security.scans', 'badge' => null],
        ['key' => 'admins',  'label' => 'WP Admins', 'route' => 'security.admins', 'badge' => $flaggedWpAdminCount ?? null],
        ['key' => 'queue',   'label' => 'Bans',    'route' => 'bans.queue',     'badge' => $reviewQueueCount ?? 0],
        ['key' => 'active',  'label' => 'Active',  'route' => 'bans.active',    'badge' => $activeBansCount ?? 0],
        ['key' => 'history', 'label' => 'History', 'route' => 'bans.history',   'badge' => null],
    ];
@endphp

<div class="flex items-center gap-1 mb-6 border-b border-[var(--color-border-light)]">
    @foreach ($tabs as $t)
        <a href="{{ route($t['route']) }}"
           class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors
                  {{ ($activeTab ?? '') === $t['key']
                        ? 'border-[var(--color-primary-500)] text-[var(--color-primary-600)]'
                        : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
            {{ $t['label'] }}
            @if ($t['badge'] !== null && $t['badge'] > 0)
                <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold
                             {{ ($activeTab ?? '') === $t['key']
                                    ? 'bg-[var(--color-primary-500)] text-white'
                                    : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]' }}">
                    {{ number_format($t['badge']) }}
                </span>
            @endif
        </a>
    @endforeach
</div>
