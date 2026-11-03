@php
    // Shared header + tab strip for the unified Security section. The title and subtitle are
    // identical on every tab so the tab bar never moves when switching tabs; per-tab text,
    // actions and stat cards are rendered by each page *below* this strip (pass 'intro'). Renders on /security/scans
    // (scans subtab) and /bans/* (queue/active/history subtabs). Each calling view
    // sets $activeTab to one of: 'scans', 'admins', 'email-auth', 'queue', 'active', 'history'.
    $tabs = [
        ['key' => 'scans',   'label' => 'Scans',   'route' => 'security.scans', 'badge' => null],
        ['key' => 'admins',  'label' => 'WP Admins', 'route' => 'security.admins', 'badge' => $flaggedWpAdminCount ?? null],
    ];

    if (app(\Modules\Core\ModuleStateResolver::class)->isEnabled('email-auth')) {
        $tabs[] = ['key' => 'email-auth', 'label' => 'Email Auth', 'route' => 'email-auth.index', 'badge' => null];
    }

    $tabs[] = ['key' => 'queue',   'label' => 'Bans',    'route' => 'bans.queue',     'badge' => $reviewQueueCount ?? 0];
    $tabs[] = ['key' => 'active',  'label' => 'Active',  'route' => 'bans.active',    'badge' => $activeBansCount ?? 0];
    $tabs[] = ['key' => 'history', 'label' => 'History', 'route' => 'bans.history',   'badge' => null];
@endphp

<x-page-header title="Security"
    subtitle="Malware scans, WordPress admins, email authentication and firewall bans across the fleet." />

<div class="cw-tab-bar {{ empty($intro) ? 'mb-6' : 'mb-3' }}">
    @foreach ($tabs as $t)
        <a href="{{ route($t['route']) }}"
           class="inline-flex items-center px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors
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

@if (! empty($intro))
    <p class="text-sm text-[var(--color-ink-muted)] mb-5">{{ $intro }}</p>
@endif
