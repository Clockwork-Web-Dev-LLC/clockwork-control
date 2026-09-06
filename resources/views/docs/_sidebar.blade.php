@php
    $canonicalOrder = $sectionOrder ?? \App\Http\Controllers\DocsController::canonicalSectionOrder();
    $orderedSections = collect($canonicalOrder)
        ->merge($tree->keys()->diff($canonicalOrder))
        ->unique()
        ->filter(fn ($s) => $tree->has($s));
    $currentSlug = $page->slug ?? null;
    $currentSection = $page->section ?? ($orderedSections->first() ?? 'Getting Started');

    $initialOpen = [];
    foreach ($orderedSections as $s) {
        $initialOpen[$s] = ($s === $currentSection || (! $currentSlug && in_array($s, ['Getting Started', 'Concepts'], true)));
    }

    // Section icons for visual differentiation
    $sectionIcons = [
        'Getting Started' => 'fa-rocket text-indigo-500',
        'Concepts' => 'fa-lightbulb text-amber-500',
        'Features' => 'fa-layer-group text-blue-500',
        'Integrations' => 'fa-plug text-emerald-500',
        'Runbooks' => 'fa-kit-medical text-rose-500',
        'Architecture' => 'fa-network-wired text-purple-500',
        'Reference' => 'fa-book-bookmark text-sky-500',
        'Internal' => 'fa-wrench text-slate-500',
    ];

    // Flat search index across every page with category metadata
    $searchIndex = $tree->flatten(1)->map(fn ($p) => [
        'title' => $p->title,
        'section' => $p->section,
        'category' => $p->category ?? '',
        'excerpt' => $p->excerpt,
        'url' => $p->url(),
    ])->values();
@endphp

<aside class="docs-sidebar" x-data="docsSidebar({{ \Illuminate\Support\Js::from($initialOpen) }})">
    <div class="docs-sidebar__inner">
        <div class="flex items-center justify-between mb-3">
            <a href="{{ route('docs.index') }}" class="docs-sidebar__home mb-0">
                <i class="fa-solid fa-book text-[var(--color-primary-600)]"></i>
                <span>Documentation Home</span>
            </a>
            <div class="flex items-center gap-1.5 text-[11px] text-[var(--color-ink-soft)]">
                <button type="button" @click="toggleAll(true)" class="hover:text-[var(--color-ink-strong)] cursor-pointer" title="Expand all sections">
                    Expand
                </button>
                <span>&bull;</span>
                <button type="button" @click="toggleAll(false)" class="hover:text-[var(--color-ink-strong)] cursor-pointer" title="Collapse all sections">
                    Collapse
                </button>
            </div>
        </div>

        {{-- Interactive Search with Category Badges --}}
        <div
            x-data="docsSearch({{ \Illuminate\Support\Js::from($searchIndex) }})"
            @keydown.escape.window="open = false"
            class="docs-sidebar__search"
        >
            <div class="docs-sidebar__search-input-wrap">
                <i class="fa-solid fa-magnifying-glass docs-sidebar__search-icon"></i>
                <input
                    type="text"
                    x-model="query"
                    @focus="open = true"
                    @click.outside="open = false"
                    placeholder="Search docs &amp; integrations…"
                    class="docs-sidebar__search-input"
                    autocomplete="off"
                >
            </div>
            <div x-show="open && query.trim().length >= 2" x-cloak class="docs-sidebar__search-results">
                <template x-if="results.length === 0">
                    <div class="docs-sidebar__search-empty">No matching pages.</div>
                </template>
                <template x-for="r in results" :key="r.url">
                    <a :href="r.url" class="docs-sidebar__search-result">
                        <div class="min-w-0 flex-1">
                            <div class="docs-sidebar__search-result-title" x-text="r.title"></div>
                            <template x-if="r.excerpt">
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate mt-0.5" x-text="r.excerpt"></div>
                            </template>
                        </div>
                        <span class="docs-sidebar__search-result-section ml-2">
                            <span x-text="r.section"></span>
                            <template x-if="r.category && r.category !== r.section">
                                <span class="opacity-70 font-normal" x-text="' &rsaquo; ' + r.category"></span>
                            </template>
                        </span>
                    </a>
                </template>
            </div>
        </div>

        {{-- Collapsible Sections Accordion --}}
        <div class="space-y-3">
            @foreach ($orderedSections as $section)
                @php
                    $pages = $tree->get($section, collect());
                    $icon = $sectionIcons[$section] ?? 'fa-folder text-[var(--color-ink-soft)]';
                    $hasSubcategories = isset($groupedTree) && $groupedTree->has($section) && $groupedTree->get($section)->count() > 1;
                @endphp
                <div class="docs-sidebar__section rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)] overflow-hidden shadow-2xs">
                    {{-- Section Accordion Header --}}
                    <button type="button"
                            @click="toggleSection('{{ $section }}')"
                            class="w-full flex items-center justify-between px-3 py-2.5 text-left text-xs font-semibold text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors cursor-pointer select-none">
                        <div class="flex items-center gap-2 min-w-0">
                            <i class="fa-solid {{ $icon }} text-xs flex-shrink-0"></i>
                            <span class="truncate">{{ $section }}</span>
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0 ml-2">
                            <span class="text-[10px] font-mono px-1.5 py-0.2 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-[var(--color-ink-muted)]">
                                {{ $pages->count() }}
                            </span>
                            <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-soft)] transition-transform duration-200"
                               :class="isSectionOpen('{{ $section }}') ? 'rotate-90' : ''"></i>
                        </div>
                    </button>

                    {{-- Section Contents --}}
                    <div x-show="isSectionOpen('{{ $section }}')"
                         x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="px-2 pb-2.5 pt-1 border-t border-[var(--color-border-light)] bg-[var(--color-surface)]">
                        @if ($hasSubcategories)
                            @foreach ($groupedTree->get($section) as $categoryName => $catPages)
                                <div class="mt-2.5 first:mt-1">
                                    @if ($categoryName !== 'General' && $categoryName !== $section)
                                        <div class="docs-sidebar__category-title">
                                            <span>{{ $categoryName }}</span>
                                        </div>
                                    @endif
                                    <ul class="docs-sidebar__list space-y-0.5">
                                        @foreach ($catPages as $p)
                                            <li>
                                                <a href="{{ $p->url() }}"
                                                   class="docs-sidebar__link {{ $currentSlug === $p->slug ? 'docs-sidebar__link--active' : '' }}">
                                                    {{ $p->title }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        @else
                            <ul class="docs-sidebar__list space-y-0.5 mt-1">
                                @foreach ($pages as $p)
                                    <li>
                                        <a href="{{ $p->url() }}"
                                           class="docs-sidebar__link {{ $currentSlug === $p->slug ? 'docs-sidebar__link--active' : '' }}">
                                            {{ $p->title }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</aside>

<script>
    if (typeof window.docsSidebar === 'undefined') {
        window.docsSidebar = function(initialOpen) {
            return {
                openSections: initialOpen || {},
                toggleSection(name) { this.openSections[name] = !this.openSections[name]; },
                isSectionOpen(name) { return !!this.openSections[name]; },
                toggleAll(expand) {
                    Object.keys(this.openSections).forEach(k => { this.openSections[k] = expand; });
                }
            };
        };
    }
    if (typeof window.docsSearch === 'undefined') {
        window.docsSearch = function(index) {
            return {
                query: '',
                open: false,
                index: index || [],
                get results() {
                    const q = this.query.trim().toLowerCase();
                    if (q.length < 2) return [];
                    return this.index.filter(p =>
                        p.title.toLowerCase().includes(q) ||
                        p.section.toLowerCase().includes(q) ||
                        (p.category && p.category.toLowerCase().includes(q)) ||
                        (p.excerpt && p.excerpt.toLowerCase().includes(q))
                    ).slice(0, 10);
                }
            };
        };
    }
</script>
