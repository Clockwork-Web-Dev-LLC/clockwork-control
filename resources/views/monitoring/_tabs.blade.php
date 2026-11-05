@php
    $tabs = [
        'index' => ['route' => 'monitoring.index', 'label' => 'Uptime Activity', 'icon' => 'fa-wave-square'],
        'settings' => ['route' => 'monitoring.settings', 'label' => 'Settings', 'icon' => 'fa-gear'],
    ];
@endphp

<div class="mb-6">
    <nav class="cw-tab-bar" role="tablist">
        @foreach ($tabs as $key => $meta)
            @php $active = request()->routeIs($meta['route']); @endphp
            <a href="{{ route($meta['route']) }}"
               role="tab"
               aria-selected="{{ $active ? 'true' : 'false' }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
                      {{ $active
                          ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]'
                          : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
                <i class="fa-solid {{ $meta['icon'] }} text-xs"></i>
                {{ $meta['label'] }}
            </a>
        @endforeach
    </nav>
</div>
