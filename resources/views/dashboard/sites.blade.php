@extends('layouts.app')

@section('title', 'Sites · Clockwork')

@section('content')
    <x-page-header title="Sites"
        :subtitle="$counts['all'] . ' sites across all hosting providers'">
    </x-page-header>

    <div x-data="{
        view: localStorage.getItem('clockwork_sites_view') || 'list',
        setView(v) {
            this.view = v;
            localStorage.setItem('clockwork_sites_view', v);
        }
    }">
        {{-- Search bar --}}
        <div class="mb-6">
            <form method="GET" action="{{ route('sites.index') }}" id="sites-search-form" class="relative">
                @if ($activeProvider !== 'all')
                    <input type="hidden" name="provider" id="sites-provider-filter" value="{{ $activeProvider }}">
                @endif
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)]"></i>
                <input
                    type="search"
                    name="q"
                    id="sites-search"
                    value="{{ $q }}"
                    placeholder="Search by domain…"
                    autocomplete="off"
                    class="w-full pl-11 pr-10 py-3 rounded-full border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-base text-[var(--color-ink-strong)]"
                >
                <button type="button" id="sites-search-clear" class="{{ $q !== '' ? '' : 'hidden' }} absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] w-6 h-6 rounded-full flex items-center justify-center" aria-label="Clear search">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </form>
        </div>

        {{-- Toolbar: Host Filter Tabs on Left + View Mode Switcher on Right --}}
        <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
            @if (count($providerTabs) > 1)
                <div class="flex items-center gap-2 flex-wrap" id="sites-provider-tabs">
                    <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mr-1">Host:</span>
                    @foreach ($providerTabs as $key => $label)
                        <a href="{{ route('sites.index', array_filter(['provider' => $key === 'all' ? null : $key, 'q' => $q ?: null])) }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium transition-colors border
                                  {{ $activeProvider === $key ? 'bg-[var(--color-nav-active-bg)] text-[var(--color-nav-active-ink)] border-[var(--color-nav-active-border)]' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] border-transparent hover:bg-[var(--color-border-light)]' }}">
                            {{ $label }}
                            <span class="opacity-70">{{ $counts[$key] ?? 0 }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <div></div>
            @endif

            {{-- View Mode Toggle: List vs Visual Grid --}}
            <div class="inline-flex items-center bg-[var(--color-surface-alt)] p-1 rounded-xl border border-[var(--color-border-light)] text-xs ml-auto">
                <button type="button" @click="setView('list')"
                        :class="view === 'list' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]'"
                        class="px-3 py-1 rounded-lg flex items-center gap-1.5 transition-all"
                        title="List view">
                    <i class="fa-solid fa-list text-xs"></i>
                    <span class="hidden sm:inline">List</span>
                </button>
                <button type="button" @click="setView('grid')"
                        :class="view === 'grid' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]'"
                        class="px-3 py-1 rounded-lg flex items-center gap-1.5 transition-all"
                        title="Visual Grid view">
                    <i class="fa-solid fa-table-cells text-xs"></i>
                    <span class="hidden sm:inline">Grid</span>
                </button>
            </div>
        </div>

        {{-- Global No-Match Placeholder --}}
        <div id="sites-no-match" class="p-10 text-center text-[var(--color-ink-soft)] card {{ $sites->isEmpty() ? '' : 'hidden' }} mb-6">
            No sites match.
        </div>

        {{-- 1. Standard List View --}}
        <div class="card overflow-hidden mb-6" id="sites-list-card" x-show="view === 'list'">
            @if ($sites->isNotEmpty())
                <ul class="divide-y divide-[var(--color-border-light)]" id="sites-list">
                    @foreach ($sites as $site)
                        @php
                            $sslState = $site->sslState();
                            $sslMeta = match (true) {
                                $site->cert_source === 'redirect_only' => ['class' => 'status-unknown', 'icon' => 'fa-arrow-up-right-from-square', 'title' => 'Redirect-only — SSL monitoring skipped'],
                                $sslState === 'green' => ['class' => 'status-green', 'icon' => 'fa-lock', 'title' => 'Cert OK'],
                                $sslState === 'yellow' => ['class' => 'status-yellow', 'icon' => 'fa-clock-rotate-left', 'title' => 'Renewal needed'],
                                $sslState === 'red' => ['class' => 'status-red', 'icon' => 'fa-lock-open', 'title' => 'Expired'],
                                default => ['class' => 'status-unknown', 'icon' => 'fa-circle-question', 'title' => 'No SSL tracked'],
                            };
                            $uptimeMeta = match ($site->uptime_state) {
                                'up' => ['class' => 'status-green', 'icon' => 'fa-circle-check', 'title' => 'Up'],
                                'down' => ['class' => 'status-red', 'icon' => 'fa-circle-exclamation', 'title' => 'Down since ' . optional($site->uptime_down_since)->diffForHumans()],
                                'maintenance' => ['class' => 'status-yellow', 'icon' => 'fa-wrench', 'title' => 'In maintenance' . ($site->uptime_maintenance_since ? ' since ' . $site->uptime_maintenance_since->diffForHumans() : '')],
                                default => ['class' => 'status-unknown', 'icon' => 'fa-circle-question', 'title' => 'Uptime unknown / not monitored'],
                            };
                        @endphp
                        <li class="site-row relative px-5 py-3 flex items-center gap-3 hover:bg-[var(--color-surface-alt)] transition-colors"
                            data-search="{{ strtolower($site->domain . ' ' . ($site->server->display_name ?? $site->server->name ?? '')) }}">
                            <a href="{{ route('sites.show', $site) }}" class="absolute inset-0 z-0" aria-label="Open {{ $site->domain }}"></a>

                            @if ($site->is_wordpress)
                                <i class="fa-brands fa-wordpress text-[var(--color-brand)] text-lg relative z-10 pointer-events-none"></i>
                            @else
                                <i class="fa-solid fa-globe text-[var(--color-ink-soft)] text-lg relative z-10 pointer-events-none"></i>
                            @endif

                            <span class="font-medium text-[var(--color-ink-strong)] truncate flex-1 relative z-10 pointer-events-none">{{ $site->domain }}</span>

                            <div class="flex items-center gap-3 relative z-10 pointer-events-none">
                                @if ($site->is_inactive)
                                    <span class="status-pill status-unknown" title="{{ $site->inactive_reason ? 'Inactive: '.$site->inactive_reason : 'Marked inactive — excluded from Issues and routine-maintenance alerts.' }}">
                                        <i class="fa-solid fa-moon"></i>
                                    </span>
                                @endif
                                @if ($site->isPressable())
                                    <span class="status-pill status-unknown" title="Hosted on Pressable">
                                        <i class="fa-solid fa-cloud"></i> Pressable
                                    </span>
                                @elseif ($site->server)
                                    <span class="text-xs font-data text-[var(--color-ink-muted)] truncate max-w-[10rem]" title="{{ $site->server->name }}">
                                        <i class="fa-solid fa-server text-[var(--color-ink-soft)]"></i> {{ $site->server->display_name ?? $site->server->name }}
                                    </span>
                                @endif

                                <span class="status-pill {{ $uptimeMeta['class'] }}" title="{{ $uptimeMeta['title'] }}">
                                    <i class="fa-solid {{ $uptimeMeta['icon'] }}"></i>
                                </span>

                                <span class="status-pill {{ $sslMeta['class'] }}" title="{{ $sslMeta['title'] }}">
                                    <i class="fa-solid {{ $sslMeta['icon'] }}"></i>
                                </span>

                                @if ($site->companion_installed)
                                    <span class="status-pill status-green" title="Companion {{ $site->companion_version }} installed">
                                        <i class="fa-solid fa-plug"></i>
                                    </span>
                                @endif

                                @if ($site->care_plan_enabled)
                                    <span class="status-pill status-green" title="On a care plan">
                                        <i class="fa-solid fa-shield-heart"></i>
                                    </span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- 2. Visual Fleet Grid View --}}
        <div id="sites-grid-container" x-show="view === 'grid'" x-cloak class="mb-6">
            @if ($sites->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-4" id="sites-grid">
                    @foreach ($sites as $site)
                        @php
                            $health = $site->healthColor();
                            $healthBarClass = match ($health) {
                                'red' => 'bg-rose-500',
                                'yellow' => 'bg-amber-500',
                                default => 'bg-emerald-500',
                            };
                            $statusDotClass = match ($health) {
                                'red' => 'bg-rose-500',
                                'yellow' => 'bg-amber-500',
                                default => 'bg-emerald-500',
                            };
                        @endphp
                        <div class="site-card group card p-0 overflow-hidden flex flex-col justify-between hover:shadow-md transition-all duration-200 border border-[var(--color-border-light)] relative"
                             data-search="{{ strtolower($site->domain . ' ' . ($site->server->display_name ?? $site->server->name ?? '')) }}">
                            <a href="{{ route('sites.show', $site) }}" class="absolute inset-0 z-10" aria-label="Open {{ $site->domain }}"></a>

                            {{-- Screenshot Thumbnail --}}
                            <div class="aspect-[16/10] bg-slate-100 overflow-hidden relative flex items-center justify-center border-b border-[var(--color-border-light)]">
                                <img src="{{ $site->screenshotUrl() }}"
                                     alt="{{ $site->domain }}"
                                     loading="lazy"
                                     class="w-full h-full object-cover object-top transition-transform duration-300 group-hover:scale-105"
                                     onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden');">

                                <div class="hidden absolute inset-0 flex flex-col items-center justify-center bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] p-4 text-center">
                                    <i class="fa-solid fa-globe text-3xl mb-1 opacity-40"></i>
                                    <span class="text-[10px] font-mono truncate max-w-full text-[var(--color-ink-muted)]">{{ $site->domain }}</span>
                                </div>

                                @if ($site->is_inactive)
                                    <span class="absolute top-2 right-2 bg-black/60 text-white text-[10px] px-2 py-0.5 rounded-full backdrop-blur-xs flex items-center gap-1 z-20" title="Site is marked inactive">
                                        <i class="fa-solid fa-moon text-[9px]"></i> Inactive
                                    </span>
                                @endif
                            </div>

                            {{-- Health Indicator Accent Bar --}}
                            <div class="h-1 w-full {{ $healthBarClass }}"></div>

                            {{-- Card Footer / Metadata --}}
                            <div class="p-3">
                                <div class="font-semibold text-xs text-[var(--color-ink-strong)] truncate mb-1" title="{{ $site->domain }}">
                                    {{ $site->domain }}
                                </div>

                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="flex items-center gap-1.5 text-[var(--color-ink-muted)] truncate max-w-[75%]">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $statusDotClass }} shrink-0"></span>
                                        <span class="truncate text-[10px] font-data text-[var(--color-ink-soft)]">{{ $site->domain }}</span>
                                    </span>

                                    <div class="flex items-center gap-1.5 shrink-0">
                                        @if ($health !== 'green')
                                            <i class="fa-solid fa-triangle-exclamation text-amber-500 text-[11px]" title="Issues or alerts detected"></i>
                                        @endif

                                        @if ($site->isPressable())
                                            <span class="text-[10px] text-[var(--color-ink-muted)]" title="Pressable">
                                                <i class="fa-solid fa-cloud text-gray-400"></i>
                                            </span>
                                        @elseif ($site->server)
                                            <span class="text-[10px] text-[var(--color-ink-muted)]" title="{{ $site->server->name }}">
                                                <i class="fa-solid fa-server text-gray-400"></i>
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="mt-6" id="sites-pagination">
            {{ $sites->links() }}
        </div>
    </div>

    <script>
        (function () {
            const input = document.getElementById('sites-search');
            const clearBtn = document.getElementById('sites-search-clear');
            const form = document.getElementById('sites-search-form');
            const listCard = document.getElementById('sites-list-card');
            const gridContainer = document.getElementById('sites-grid-container');
            const paginationWrap = document.getElementById('sites-pagination');
            let abortCtrl = null;
            let debounceTimer = null;

            function applyLocalFilter(q) {
                const query = (q || '').trim().toLowerCase();
                if (clearBtn) {
                    clearBtn.classList.toggle('hidden', query.length === 0);
                }

                const rows = listCard ? listCard.querySelectorAll('.site-row') : [];
                const cards = gridContainer ? gridContainer.querySelectorAll('.site-card') : [];
                let visibleRows = 0;
                let visibleCards = 0;

                rows.forEach(row => {
                    const searchData = row.dataset.search || '';
                    const matched = query.length === 0 || searchData.includes(query);
                    row.style.display = matched ? '' : 'none';
                    if (matched) visibleRows++;
                });

                cards.forEach(card => {
                    const searchData = card.dataset.search || '';
                    const matched = query.length === 0 || searchData.includes(query);
                    card.style.display = matched ? '' : 'none';
                    if (matched) visibleCards++;
                });

                const noMatch = document.getElementById('sites-no-match');
                if (noMatch) {
                    const hasItems = rows.length > 0 || cards.length > 0;
                    const anyVisible = visibleRows > 0 || visibleCards > 0;
                    noMatch.classList.toggle('hidden', anyVisible || (!hasItems && !query));
                }
            }

            function fetchServerResults(q) {
                if (abortCtrl) {
                    abortCtrl.abort();
                }
                abortCtrl = new AbortController();

                const url = new URL(form.action, window.location.origin);
                const providerInput = document.getElementById('sites-provider-filter');
                if (providerInput && providerInput.value && providerInput.value !== 'all') {
                    url.searchParams.set('provider', providerInput.value);
                }
                const trimmed = (q || '').trim();
                if (trimmed) {
                    url.searchParams.set('q', trimmed);
                } else {
                    url.searchParams.delete('q');
                }
                url.searchParams.delete('page');

                window.history.replaceState(null, '', url.toString());

                fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: abortCtrl.signal
                })
                .then(res => res.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newCard = doc.getElementById('sites-list-card');
                    const newGrid = doc.getElementById('sites-grid-container');
                    const newPagination = doc.getElementById('sites-pagination');

                    if (newCard && listCard) {
                        listCard.innerHTML = newCard.innerHTML;
                    }
                    if (newGrid && gridContainer) {
                        gridContainer.innerHTML = newGrid.innerHTML;
                    }
                    if (newPagination && paginationWrap) {
                        paginationWrap.innerHTML = newPagination.innerHTML;
                    }

                    applyLocalFilter(input.value);
                })
                .catch(err => {
                    if (err.name !== 'AbortError') {
                        console.error('Error fetching sites:', err);
                    }
                });
            }

            if (input) {
                input.addEventListener('input', e => {
                    const val = e.target.value;
                    applyLocalFilter(val);

                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        fetchServerResults(val);
                    }, 250);
                });

                input.addEventListener('keydown', e => {
                    if (e.key === 'Escape') {
                        input.value = '';
                        applyLocalFilter('');
                        clearTimeout(debounceTimer);
                        fetchServerResults('');
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        const firstSite = document.querySelector('.site-row:not([style*="display: none"]) a, .site-card:not([style*="display: none"]) a');
                        if (firstSite) {
                            firstSite.click();
                        } else {
                            clearTimeout(debounceTimer);
                            fetchServerResults(input.value);
                        }
                    }
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    if (input) {
                        input.value = '';
                        applyLocalFilter('');
                        clearTimeout(debounceTimer);
                        fetchServerResults('');
                        input.focus();
                    }
                });
            }

            if (form) {
                form.addEventListener('submit', e => {
                    e.preventDefault();
                    clearTimeout(debounceTimer);
                    fetchServerResults(input ? input.value : '');
                });
            }

            // "/" focuses search from anywhere on the page
            document.addEventListener('keydown', e => {
                if (e.key === '/' && input && document.activeElement !== input
                        && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
                    e.preventDefault();
                    input.focus();
                    input.select();
                }
            });
        })();
    </script>
@endsection
