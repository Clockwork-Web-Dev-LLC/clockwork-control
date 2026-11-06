@php
    $seoTabs = [
        ['label' => 'Overview', 'icon' => 'fa-magnifying-glass-chart', 'url' => route('seo.index'), 'active' => request()->routeIs('seo.index', 'seo.sites.*')],
    ];
    if (auth()->user()?->isAdmin()) {
        $seoTabs[] = ['label' => 'Settings', 'icon' => 'fa-gear', 'url' => route('seo.settings'), 'active' => request()->routeIs('seo.settings')];
    }
@endphp

<nav class="cw-tab-bar mb-6" aria-label="SEO sections">
    @foreach ($seoTabs as $tab)
        <a href="{{ $tab['url'] }}" @if ($tab['active']) aria-current="page" @endif
           class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
                  {{ $tab['active']
                        ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]'
                        : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
            <i class="fa-solid {{ $tab['icon'] }} text-xs"></i>
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
</nav>
