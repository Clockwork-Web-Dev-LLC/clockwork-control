{{-- One client's SEO pages. Included on every /seo/sites/{site}/... page. --}}
@php
    $siteTabs = [
        ['Numbers', 'fa-table', 'seo.sites.show', ['seo.sites.show']],
        ['Rankings', 'fa-ranking-star', 'seo.sites.rankings', ['seo.sites.rankings']],
        ['Keyword map', 'fa-map', 'seo.sites.keyword-map', ['seo.sites.keyword-map', 'seo.sites.keyword-map.*']],
        ['Keywords', 'fa-key', 'seo.sites.keywords', ['seo.sites.keywords', 'seo.sites.keywords.*']],
        ['Research', 'fa-binoculars', 'seo.sites.research', ['seo.sites.research', 'seo.sites.research.*']],
        ['Page audit', 'fa-file-circle-check', 'seo.sites.page-audit', ['seo.sites.page-audit']],
        ['Profile', 'fa-sliders', 'seo.sites.profile', ['seo.sites.profile']],
    ];
@endphp
<nav class="flex flex-wrap gap-2 mb-6" aria-label="This client's SEO pages" data-site-tabs>
    @foreach ($siteTabs as [$label, $icon, $routeName, $patterns])
        @php $active = request()->routeIs(...$patterns); @endphp
        <a href="{{ route($routeName, $site) }}" @if ($active) aria-current="page" @endif
           class="inline-flex items-center gap-2 px-3 py-1.5 text-sm rounded-full border transition-colors
                  {{ $active
                        ? 'border-[var(--color-ink-strong)] bg-[var(--color-ink-strong)] text-[var(--color-surface)]'
                        : 'border-[var(--color-border)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-ink-muted)]' }}">
            <i class="fa-solid {{ $icon }} text-xs"></i><span>{{ $label }}</span>
        </a>
    @endforeach
</nav>
