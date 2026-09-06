@php
    $updatesCapable = $site->companion_installed
        && is_array($site->companion_capabilities ?? null)
        && in_array('updates', $site->companion_capabilities, true);
    $updatesAvailableCount = (int) ($site->companion_snapshot['plugins']['counts']['updates_available'] ?? 0);

    // Traffic (nginx access-log rollup) and Bans (fail2ban/LLAR) both require
    // SSH/server access this app doesn't have for Pressable sites — hide
    // rather than render a permanently-empty tab with SSH-only instructions.
    $sshCapable = $site->host()->supports(\Modules\Core\Contracts\HostingProvider::CAP_SSH);

    $tabs = [
        'overview' => ['label' => 'Overview', 'icon' => 'fa-gauge-high'],
        'traffic' => ['label' => 'Traffic', 'icon' => 'fa-chart-line', 'when' => $sshCapable],
        'bans' => ['label' => 'Bans', 'icon' => 'fa-ban', 'when' => $sshCapable],
        'security' => ['label' => 'Security', 'icon' => 'fa-shield-halved'],
        'performance' => ['label' => 'Performance', 'icon' => 'fa-bolt', 'when' => (bool) $site->care_plan_enabled],
        'updates' => ['label' => 'Updates', 'icon' => 'fa-arrow-up-from-bracket', 'when' => $updatesCapable],
        'forms' => ['label' => 'Forms', 'icon' => 'fa-envelope-circle-check', 'when' => (bool) $site->care_plan_enabled && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('contact-forms')],
        'settings' => ['label' => 'Settings', 'icon' => 'fa-gear'],
    ];
@endphp

<div class="border-b border-[var(--color-border-light)] mb-6 -mx-1">
    <nav class="flex items-center gap-1 px-1 overflow-x-auto overflow-y-hidden" role="tablist">
        @foreach ($tabs as $key => $meta)
            @if (array_key_exists('when', $meta) && ! $meta['when']) @continue @endif
            @php $active = $tab === $key; @endphp
            <a href="{{ route('sites.show', ['site' => $site, 'tab' => $key]) }}"
               role="tab"
               aria-selected="{{ $active ? 'true' : 'false' }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
                      {{ $active
                          ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]'
                          : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
                <i class="fa-solid {{ $meta['icon'] }} text-xs"></i>
                {{ $meta['label'] }}
                @if ($key === 'bans' && $bansCount > 0)
                    <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-red)] text-white">{{ $bansCount }}</span>
                @endif
                @if ($key === 'updates' && $updatesAvailableCount > 0)
                    <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-yellow)] text-white">{{ $updatesAvailableCount }}</span>
                @endif
            </a>
        @endforeach
    </nav>
</div>
