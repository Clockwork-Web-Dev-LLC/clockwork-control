@extends('layouts.app')

@section('title', 'Sites · Clockwork')

@section('content')
    <x-page-header title="Sites"
        :subtitle="$counts['all'] . ' sites across all hosting providers'">
    </x-page-header>

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

    <div class="flex items-center gap-2 flex-wrap mb-6" id="sites-provider-tabs">
        <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mr-1">Host:</span>
        @php
            $providerTabs = [
                'all' => 'All',
                \App\Models\Site::HOSTING_PROVIDER_SPINUPWP => 'SpinupWP',
                \App\Models\Site::HOSTING_PROVIDER_PRESSABLE => 'Pressable',
            ];
        @endphp
        @foreach ($providerTabs as $key => $label)
            <a href="{{ route('sites.index', array_filter(['provider' => $key === 'all' ? null : $key, 'q' => $q ?: null])) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium transition-colors border
                      {{ $activeProvider === $key ? 'bg-[var(--color-nav-active-bg)] text-[var(--color-nav-active-ink)] border-[var(--color-nav-active-border)]' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] border-transparent hover:bg-[var(--color-border-light)]' }}">
                {{ $label }}
                <span class="opacity-70">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <div class="card overflow-hidden" id="sites-list-card">
        <div id="sites-no-match" class="p-10 text-center text-[var(--color-ink-soft)] {{ $sites->isEmpty() ? '' : 'hidden' }}">
            No sites match.
        </div>
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

    <div class="mt-6" id="sites-pagination">
        {{ $sites->links() }}
    </div>

    <script>
        (function () {
            const input = document.getElementById('sites-search');
            const clearBtn = document.getElementById('sites-search-clear');
            const form = document.getElementById('sites-search-form');
            const listCard = document.getElementById('sites-list-card');
            const paginationWrap = document.getElementById('sites-pagination');
            let abortCtrl = null;
            let debounceTimer = null;

            function applyLocalFilter(q) {
                const query = (q || '').trim().toLowerCase();
                if (clearBtn) {
                    clearBtn.classList.toggle('hidden', query.length === 0);
                }

                const rows = listCard ? listCard.querySelectorAll('.site-row') : [];
                let visibleCount = 0;

                rows.forEach(row => {
                    const searchData = row.dataset.search || '';
                    const matched = query.length === 0 || searchData.includes(query);
                    row.style.display = matched ? '' : 'none';
                    if (matched) visibleCount++;
                });

                const noMatch = document.getElementById('sites-no-match');
                if (noMatch) {
                    noMatch.classList.toggle('hidden', visibleCount > 0 || (rows.length === 0 && !query));
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
                    const newPagination = doc.getElementById('sites-pagination');

                    if (newCard && listCard) {
                        listCard.innerHTML = newCard.innerHTML;
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
                        const firstSite = listCard.querySelector('.site-row:not([style*="display: none"]) a');
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
