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
        <div class="mb-3">
            <a href="{{ route('docs.index') }}" class="docs-sidebar__home group">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="w-6 h-6 rounded-md bg-[var(--color-primary-50)] dark:bg-[var(--color-primary-950)] text-[var(--color-primary-600)] dark:text-sky-400 flex items-center justify-center text-xs shrink-0 border border-[var(--color-border-light)]">
                        <i class="fa-solid fa-book-open"></i>
                    </span>
                    <span class="truncate font-semibold group-hover:text-[var(--color-brand)] transition-colors">Documentation Home</span>
                </div>
                <i class="fa-solid fa-arrow-left text-[10px] text-[var(--color-ink-soft)] opacity-60 group-hover:opacity-100 group-hover:-translate-x-0.5 transition-all"></i>
            </a>
        </div>

        {{-- Interactive Search with Category Badges & '/' Shortcut --}}
        <div
            x-data="docsSearch({{ \Illuminate\Support\Js::from($searchIndex) }})"
            @keydown.escape.window="open = false"
            @keydown.window.slash="if (document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') { $event.preventDefault(); $refs.docsSearchInput.focus(); open = true; }"
            class="docs-sidebar__search"
        >
            <div class="docs-sidebar__search-input-wrap">
                <i class="fa-solid fa-magnifying-glass docs-sidebar__search-icon"></i>
                <input
                    x-ref="docsSearchInput"
                    type="text"
                    x-model="query"
                    @focus="open = true"
                    @click.outside="open = false"
                    placeholder="Search docs &amp; integrations…"
                    class="docs-sidebar__search-input"
                    autocomplete="off"
                >
                <div class="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none">
                    <kbd class="cmd-kbd text-[9px] px-1 py-0.5 text-[var(--color-ink-soft)]">/</kbd>
                </div>
            </div>
            <div x-show="open && query.trim().length >= 2" x-cloak class="docs-sidebar__search-results">
                <template x-if="results.length === 0">
                    <div class="docs-sidebar__search-empty">No matching pages found for "<span class="font-medium text-[var(--color-ink-strong)]" x-text="query"></span>".</div>
                </template>
                <template x-for="r in results" :key="r.url">
                    <a :href="r.url" class="docs-sidebar__search-result group">
                        <div class="min-w-0 flex-1">
                            <div class="docs-sidebar__search-result-title group-hover:text-[var(--color-brand)]" x-text="r.title"></div>
                            <template x-if="r.excerpt">
                                <div class="text-[11px] text-[var(--color-ink-muted)] truncate mt-0.5" x-text="r.excerpt"></div>
                            </template>
                        </div>
                        <span class="docs-sidebar__search-result-section ml-2">
                            <span x-text="r.section"></span>
                            <template x-if="r.category && r.category !== r.section">
                                <span class="opacity-75 font-normal" x-text="' › ' + r.category"></span>
                            </template>
                        </span>
                    </a>
                </template>
            </div>
        </div>

        {{-- Collapsible Sections Accordion --}}
        <div class="space-y-2">
            @foreach ($orderedSections as $section)
                @php
                    $pages = $tree->get($section, collect());
                    $icon = $sectionIcons[$section] ?? 'fa-folder text-[var(--color-ink-soft)]';
                    $hasSubcategories = isset($groupedTree) && $groupedTree->has($section) && $groupedTree->get($section)->count() > 1;
                @endphp
                <div class="docs-sidebar__section rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface)] overflow-hidden shadow-2xs">
                    {{-- Section Accordion Header --}}
                    <button type="button"
                            @click="toggleSection('{{ $section }}')"
                            class="w-full flex items-center justify-between px-3 py-2.5 text-left text-xs font-semibold text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors cursor-pointer select-none">
                        <div class="flex items-center gap-2 min-w-0">
                            <i class="fa-solid {{ $icon }} text-xs shrink-0"></i>
                            <span class="truncate">{{ $section }}</span>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0 ml-2">
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
                                            <span class="font-mono text-[9px] opacity-70">{{ $catPages->count() }}</span>
                                        </div>
                                    @endif
                                    <ul class="docs-sidebar__list space-y-0.5">
                                        @foreach ($catPages as $p)
                                            <li>
                                                <a href="{{ $p->url() }}"
                                                   class="docs-sidebar__link {{ $currentSlug === $p->slug ? 'docs-sidebar__link--active' : '' }}">
                                                    <span class="truncate">{{ $p->title }}</span>
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
                                            <span class="truncate">{{ $p->title }}</span>
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
                isSectionOpen(name) { return !!this.openSections[name]; }
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
