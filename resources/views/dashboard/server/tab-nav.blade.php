@php
    $tabs = [
        'sites' => ['label' => 'Sites', 'icon' => 'fa-globe'],
        'stats' => ['label' => 'Stats', 'icon' => 'fa-chart-line'],
        'updates' => ['label' => 'Updates', 'icon' => 'fa-cube'],
        'bans' => ['label' => 'Bans', 'icon' => 'fa-ban'],
        'settings' => ['label' => 'Settings', 'icon' => 'fa-gear'],
    ];
@endphp

<div class="border-b border-[var(--color-border-light)] mb-6 -mx-1">
    <nav class="flex items-center gap-1 px-1 overflow-x-auto overflow-y-hidden" role="tablist">
        @foreach ($tabs as $key => $meta)
            @php $active = $tab === $key; @endphp
            <a href="{{ route('servers.show', ['server' => $server, 'tab' => $key]) }}"
               role="tab"
               aria-selected="{{ $active ? 'true' : 'false' }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
                      {{ $active
                          ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]'
                          : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
                <i class="fa-solid {{ $meta['icon'] }} text-xs"></i>
                {{ $meta['label'] }}
                @if ($key === 'updates' && ! $server->is_ignored && ($server->upgrade_required || $server->reboot_required))
                    <span class="inline-flex w-1.5 h-1.5 rounded-full" style="background: var(--color-status-yellow)"
                          title="Patches or reboot pending"></span>
                @endif
            </a>
        @endforeach
    </nav>
</div>
