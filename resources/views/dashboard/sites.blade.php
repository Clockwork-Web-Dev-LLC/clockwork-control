@extends('layouts.app')

@section('title', 'Sites · Clockwork')

@section('content')
    <div x-data="sitesPage()">
        <x-page-header title="Sites"
            :subtitle="$counts['all'] . ' sites across all hosting providers'">
            <x-slot:actions>
                <a href="{{ route('downloads.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-[var(--color-surface-alt)] hover:bg-[var(--color-border-light)] border border-[var(--color-border)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] transition-colors" title="Download WordPress Plugins (Companion & Renegade)">
                    <i class="fa-solid fa-download text-[11px] text-[var(--color-ink-muted)]"></i>
                    <span>Download Plugins</span>
                </a>
                <button type="button" @click="showAddModal = true" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-[var(--color-brand)] text-white hover:bg-[var(--color-brand-hover)] shadow-xs transition-colors cursor-pointer">
                    <i class="fa-solid fa-plus text-xs"></i>
                    <span>Add Site</span>
                </button>
            </x-slot:actions>
        </x-page-header>
        {{-- Search bar --}}
        <div class="mb-6">
            <form method="GET" action="{{ route('sites.index') }}" id="sites-search-form" class="relative">
                @if ($activeProvider !== 'all')
                    <input type="hidden" name="provider" id="sites-provider-filter" value="{{ $activeProvider }}">
                @endif
                <input type="hidden" name="sort" id="sites-sort-filter" value="{{ $sort }}">
                <input type="hidden" name="dir" id="sites-dir-filter" value="{{ $dir }}">
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
                        <a href="{{ route('sites.index', array_filter(['provider' => $key === 'all' ? null : $key, 'q' => $q ?: null, 'sort' => $sort !== 'domain' ? $sort : null, 'dir' => $dir !== 'asc' ? $dir : null])) }}"
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

            <div class="flex items-center gap-2.5 ml-auto flex-wrap">
                {{-- Sort Dropdown --}}
                <div class="relative inline-flex items-center">
                    <label for="sites-sort-select" class="sr-only">Sort sites</label>
                    <div class="relative flex items-center">
                        <i class="fa-solid fa-arrow-down-short-wide absolute left-2.5 text-[11px] text-[var(--color-ink-soft)] pointer-events-none"></i>
                        <select id="sites-sort-select"
                                onchange="window.location.href = this.value"
                                class="appearance-none bg-[var(--color-surface-alt)] hover:bg-[var(--color-surface)] border border-[var(--color-border-light)] text-[var(--color-ink-strong)] py-1 pl-7 pr-7 rounded-xl text-xs font-medium cursor-pointer focus:outline-none focus:border-[var(--color-brand)] transition-colors shadow-2xs"
                                aria-label="Sort sites list">
                            <option value="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'domain', 'dir' => 'asc'])) }}" {{ $sort === 'domain' && $dir === 'asc' ? 'selected' : '' }}>Domain (A–Z)</option>
                            <option value="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'domain', 'dir' => 'desc'])) }}" {{ $sort === 'domain' && $dir === 'desc' ? 'selected' : '' }}>Domain (Z–A)</option>
                            <option value="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'server', 'dir' => 'asc'])) }}" {{ $sort === 'server' && $dir === 'asc' ? 'selected' : '' }}>Host / Server (A–Z)</option>
                            <option value="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'server', 'dir' => 'desc'])) }}" {{ $sort === 'server' && $dir === 'desc' ? 'selected' : '' }}>Host / Server (Z–A)</option>
                            <option value="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'status', 'dir' => 'asc'])) }}" {{ $sort === 'status' && $dir === 'asc' ? 'selected' : '' }}>Status (Issues First)</option>
                            <option value="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'status', 'dir' => 'desc'])) }}" {{ $sort === 'status' && $dir === 'desc' ? 'selected' : '' }}>Status (Healthy First)</option>
                            <option value="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'created_at', 'dir' => 'desc'])) }}" {{ $sort === 'created_at' && $dir === 'desc' ? 'selected' : '' }}>Date Added (Newest)</option>
                            <option value="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'created_at', 'dir' => 'asc'])) }}" {{ $sort === 'created_at' && $dir === 'asc' ? 'selected' : '' }}>Date Added (Oldest)</option>
                        </select>
                        <i class="fa-solid fa-chevron-down absolute right-2 text-[9px] text-[var(--color-ink-soft)] pointer-events-none"></i>
                    </div>
                </div>

                {{-- View Mode Toggle: List vs Visual Grid --}}
                <div class="inline-flex items-center bg-[var(--color-surface-alt)] p-1 rounded-xl border border-[var(--color-border-light)] text-xs">
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
        </div>

        {{-- Global No-Match Placeholder --}}
        <div id="sites-no-match" class="p-10 text-center text-[var(--color-ink-soft)] card {{ $sites->isEmpty() ? '' : 'hidden' }} mb-6">
            No sites match.
        </div>

        {{-- 1. Standard List View --}}
        <div class="card overflow-hidden mb-6" id="sites-list-card" x-show="view === 'list'">
            @if ($sites->isNotEmpty())
                @php
                    $domainNextDir = ($sort === 'domain' && $dir === 'asc') ? 'desc' : 'asc';
                    $serverNextDir = ($sort === 'server' && $dir === 'asc') ? 'desc' : 'asc';
                    $statusNextDir = ($sort === 'status' && $dir === 'asc') ? 'desc' : 'asc';
                @endphp
                {{-- List Header / Column Sorting --}}
                <div class="relative grid grid-cols-1 lg:grid-cols-2 bg-[var(--color-surface-alt)]/70 border-b border-[var(--color-border-light)] text-xs font-mono font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] select-none">
                    {{-- Vertical divider line between columns in header on wide screens --}}
                    <div class="hidden lg:block absolute inset-y-0 left-1/2 -translate-x-1/2 w-px bg-[var(--color-border-light)] pointer-events-none"></div>

                    {{-- Left Column Header --}}
                    <div class="px-3.5 sm:px-5 py-2.5 flex items-center gap-2.5 sm:gap-3">
                        <span class="w-5 sm:w-6 shrink-0 text-center" title="Site Type">
                            <i class="fa-solid fa-globe text-[11px] text-[var(--color-ink-soft)]"></i>
                        </span>
                        <div class="min-w-0 flex-1 flex items-center gap-4 sm:gap-6">
                            <a href="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'domain', 'dir' => $domainNextDir])) }}"
                               class="inline-flex items-center gap-1.5 hover:text-[var(--color-ink-strong)] transition-colors {{ $sort === 'domain' ? 'text-[var(--color-brand)] font-bold' : '' }}"
                               title="Sort by Domain ({{ $sort === 'domain' && $dir === 'asc' ? 'Z–A' : 'A–Z' }})">
                                <span>Domain</span>
                                @if ($sort === 'domain')
                                    <i class="fa-solid {{ $dir === 'asc' ? 'fa-arrow-up-a-z' : 'fa-arrow-down-z-a' }} text-[10px]"></i>
                                @else
                                    <i class="fa-solid fa-sort text-[10px] text-[var(--color-ink-soft)] opacity-60"></i>
                                @endif
                            </a>

                            <a href="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'server', 'dir' => $serverNextDir])) }}"
                               class="hidden sm:inline-flex items-center gap-1.5 hover:text-[var(--color-ink-strong)] transition-colors {{ $sort === 'server' ? 'text-[var(--color-brand)] font-bold' : '' }}"
                               title="Sort by Host / Server">
                                <span>Host / Server</span>
                                @if ($sort === 'server')
                                    <i class="fa-solid {{ $dir === 'asc' ? 'fa-arrow-up-a-z' : 'fa-arrow-down-z-a' }} text-[10px]"></i>
                                @else
                                    <i class="fa-solid fa-sort text-[10px] text-[var(--color-ink-soft)] opacity-60"></i>
                                @endif
                            </a>
                        </div>
                        <div class="shrink-0 flex items-center justify-end">
                            <a href="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'status', 'dir' => $statusNextDir])) }}"
                               class="inline-flex items-center gap-1.5 hover:text-[var(--color-ink-strong)] transition-colors {{ $sort === 'status' ? 'text-[var(--color-brand)] font-bold' : '' }}"
                               title="Sort by Health & Uptime Status">
                                <span>Status</span>
                                @if ($sort === 'status')
                                    <i class="fa-solid {{ $dir === 'asc' ? 'fa-arrow-up-wide-short' : 'fa-arrow-down-wide-short' }} text-[10px]"></i>
                                @else
                                    <i class="fa-solid fa-sort text-[10px] text-[var(--color-ink-soft)] opacity-60"></i>
                                @endif
                            </a>
                        </div>
                    </div>

                    {{-- Right Column Header (Visible on lg: screens and up) --}}
                    <div class="hidden lg:flex px-3.5 sm:px-5 py-2.5 items-center gap-2.5 sm:gap-3">
                        <span class="w-5 sm:w-6 shrink-0 text-center" title="Site Type">
                            <i class="fa-solid fa-globe text-[11px] text-[var(--color-ink-soft)]"></i>
                        </span>
                        <div class="min-w-0 flex-1 flex items-center gap-4 sm:gap-6">
                            <a href="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'domain', 'dir' => $domainNextDir])) }}"
                               class="inline-flex items-center gap-1.5 hover:text-[var(--color-ink-strong)] transition-colors {{ $sort === 'domain' ? 'text-[var(--color-brand)] font-bold' : '' }}"
                               title="Sort by Domain ({{ $sort === 'domain' && $dir === 'asc' ? 'Z–A' : 'A–Z' }})">
                                <span>Domain</span>
                                @if ($sort === 'domain')
                                    <i class="fa-solid {{ $dir === 'asc' ? 'fa-arrow-up-a-z' : 'fa-arrow-down-z-a' }} text-[10px]"></i>
                                @else
                                    <i class="fa-solid fa-sort text-[10px] text-[var(--color-ink-soft)] opacity-60"></i>
                                @endif
                            </a>

                            <a href="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'server', 'dir' => $serverNextDir])) }}"
                               class="inline-flex items-center gap-1.5 hover:text-[var(--color-ink-strong)] transition-colors {{ $sort === 'server' ? 'text-[var(--color-brand)] font-bold' : '' }}"
                               title="Sort by Host / Server">
                                <span>Host / Server</span>
                                @if ($sort === 'server')
                                    <i class="fa-solid {{ $dir === 'asc' ? 'fa-arrow-up-a-z' : 'fa-arrow-down-z-a' }} text-[10px]"></i>
                                @else
                                    <i class="fa-solid fa-sort text-[10px] text-[var(--color-ink-soft)] opacity-60"></i>
                                @endif
                            </a>
                        </div>
                        <div class="shrink-0 flex items-center justify-end">
                            <a href="{{ route('sites.index', array_filter(['provider' => $activeProvider === 'all' ? null : $activeProvider, 'q' => $q ?: null, 'sort' => 'status', 'dir' => $statusNextDir])) }}"
                               class="inline-flex items-center gap-1.5 hover:text-[var(--color-ink-strong)] transition-colors {{ $sort === 'status' ? 'text-[var(--color-brand)] font-bold' : '' }}"
                               title="Sort by Health & Uptime Status">
                                <span>Status</span>
                                @if ($sort === 'status')
                                    <i class="fa-solid {{ $dir === 'asc' ? 'fa-arrow-up-wide-short' : 'fa-arrow-down-wide-short' }} text-[10px]"></i>
                                @else
                                    <i class="fa-solid fa-sort text-[10px] text-[var(--color-ink-soft)] opacity-60"></i>
                                @endif
                            </a>
                        </div>
                    </div>
                </div>

                {{-- List Container with Central Vertical Divider on lg: --}}
                <div class="relative">
                    <div class="hidden lg:block absolute inset-y-0 left-1/2 -translate-x-1/2 w-px bg-[var(--color-border-light)] pointer-events-none z-10"></div>
                    <ul class="grid grid-cols-1 lg:grid-cols-2" id="sites-list">
                        @foreach ($sites as $site)
                            @php
                                $sslState = $site->sslState();
                                $sslMeta = match (true) {
                                    $site->cert_source === 'redirect_only' => ['class' => 'status-unknown', 'icon' => 'fa-arrow-up-right-from-square', 'tooltip' => 'Redirect Only'],
                                    $sslState === 'green' => ['class' => 'status-green', 'icon' => 'fa-lock', 'tooltip' => 'SSL Valid'],
                                    $sslState === 'yellow' => ['class' => 'status-yellow', 'icon' => 'fa-clock-rotate-left', 'tooltip' => 'SSL Expiring Soon'],
                                    $sslState === 'red' => ['class' => 'status-red', 'icon' => 'fa-lock-open', 'tooltip' => 'SSL Expired'],
                                    default => ['class' => 'status-unknown', 'icon' => 'fa-circle-question', 'tooltip' => 'No SSL'],
                                };
                                $uptimeMeta = match ($site->uptime_state) {
                                    'up' => ['class' => 'status-green', 'icon' => 'fa-circle-check', 'tooltip' => 'Uptime Online'],
                                    'down' => ['class' => 'status-red', 'icon' => 'fa-circle-exclamation', 'tooltip' => 'Site Down'],
                                    'maintenance' => ['class' => 'status-yellow', 'icon' => 'fa-wrench', 'tooltip' => 'In Maintenance'],
                                    default => ['class' => 'status-unknown', 'icon' => 'fa-circle-question', 'tooltip' => 'Uptime Unknown'],
                                };
                            @endphp
                            <li class="site-row relative px-3.5 sm:px-5 py-2.5 sm:py-3 flex items-center gap-2.5 sm:gap-3 hover:bg-[var(--color-surface-alt)] transition-colors border-b border-[var(--color-border-light)]"
                                data-search="{{ strtolower($site->domain . ' ' . implode(' ', $site->aliasDomains()) . ' ' . ($site->server->display_name ?? $site->server->name ?? '')) }}">
                                <a href="{{ route('sites.show', $site) }}" class="absolute inset-0 z-0" aria-label="Open {{ $site->domain }}"></a>

                                @if ($site->is_wordpress)
                                    <i class="fa-brands fa-wordpress text-[var(--color-ink-muted)] text-lg relative z-10 pointer-events-none shrink-0"></i>
                                @else
                                    <i class="fa-solid fa-globe text-[var(--color-ink-muted)] text-lg relative z-10 pointer-events-none shrink-0"></i>
                                @endif

                                <div class="min-w-0 flex-1 relative z-10 pointer-events-none">
                                    <div class="font-medium text-[var(--color-ink-strong)] truncate text-sm sm:text-base leading-snug">
                                        {{ $site->domain }}
                                    </div>
                                    <div class="text-[11px] text-[var(--color-ink-muted)] truncate flex items-center gap-1.5 mt-0.5">
                                        @if ($site->isPressable())
                                            <i class="fa-solid fa-cloud text-[10px] text-[var(--color-ink-soft)]"></i> <span>Pressable</span>
                                        @elseif ($site->isCustom())
                                            <i class="fa-solid fa-plug text-[10px] text-[var(--color-ink-soft)]"></i> <span>{{ $site->pluginOnlyHostLabel() }}</span>
                                        @elseif ($site->server)
                                            <i class="fa-solid fa-server text-[10px] text-[var(--color-ink-soft)]"></i> <span>{{ $site->server->display_name ?? $site->server->name }}</span>
                                        @endif
                                        @if ($site->is_inactive)
                                            <span class="text-[10px] px-1.5 py-0.2 rounded bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] font-medium">Inactive</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center gap-1.5 sm:gap-2 relative z-10 shrink-0">
                                    <span class="status-pill {{ $uptimeMeta['class'] }} cursor-default" data-tooltip="{{ $uptimeMeta['tooltip'] }}">
                                        <i class="fa-solid {{ $uptimeMeta['icon'] }}"></i>
                                    </span>

                                    <span class="status-pill {{ $sslMeta['class'] }} cursor-default" data-tooltip="{{ $sslMeta['tooltip'] }}">
                                        <i class="fa-solid {{ $sslMeta['icon'] }}"></i>
                                    </span>

                                    @if ($site->companion_installed)
                                        @php
                                            $companionClass = $site->companion_stuck_since ? 'status-yellow' : 'status-green';
                                            $companionTooltip = $site->companion_stuck_since
                                                ? ($site->isRenegade() ? 'Renegade Plugin Unreachable' : 'Companion Plugin Unreachable')
                                                : ($site->isRenegade() ? 'Renegade Plugin Active' : 'Companion Plugin Active');
                                        @endphp
                                        <span class="status-pill {{ $companionClass }} cursor-default" data-tooltip="{{ $companionTooltip }}">
                                            <i class="fa-solid fa-plug"></i>
                                        </span>
                                    @endif

                                    @if (\App\Models\Site::areCarePlansEnabled() && $site->care_plan_enabled)
                                        <span class="status-pill status-green cursor-default" data-tooltip="Care Plan Active">
                                            <i class="fa-solid fa-shield-heart"></i>
                                        </span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
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
                                        <span class="truncate text-[10px] font-data text-[var(--color-ink-soft)]">{{ $site->isPressable() ? 'Pressable' : ($site->server?->display_name ?? $site->server?->name ?? ($site->isCustom() ? $site->pluginOnlyHostLabel() : 'Custom')) }}</span>
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

        {{-- Add Site Modal --}}
        <div x-show="showAddModal"
             x-cloak
             @keydown.escape.window="showAddModal = false"
             class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 bg-black/50 backdrop-blur-xs overflow-y-auto"
             @click.self="showAddModal = false"
             role="dialog"
             aria-modal="true">
            <div class="bg-[var(--color-surface)] rounded-[var(--radius-card)] shadow-2xl max-w-xl w-full my-auto border border-[var(--color-border)]"
                 @click.stop>
                <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between gap-4 sticky top-0 bg-[var(--color-surface)] z-10">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-[var(--color-ink-muted)] flex items-center justify-center font-bold text-base">
                            <i class="fa-solid fa-plus"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-base text-[var(--color-ink-strong)]">
                                Connect WordPress Site
                            </h3>
                            <p class="text-xs text-[var(--color-ink-muted)]">Pair via Companion plugin (WP Engine, Kinsta, or standalone)</p>
                        </div>
                    </div>
                    <button type="button" @click="showAddModal = false" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] text-2xl leading-none p-1 cursor-pointer" aria-label="Close">×</button>
                </div>

                <div class="p-6 space-y-5">
                    {{-- Quick Download Box --}}
                    <div class="p-4 rounded-xl bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)]">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="font-bold text-[var(--color-ink-strong)] flex items-center gap-1.5">
                                <i class="fa-solid fa-circle-info text-[var(--color-ink-muted)]"></i>
                                <span>Need the Companion plugin?</span>
                            </span>
                            <a href="{{ route('downloads.index') }}" class="text-[var(--color-brand)] font-semibold hover:underline flex items-center gap-1" target="_blank">
                                <i class="fa-solid fa-download"></i>
                                <span>Download Hub</span>
                            </a>
                        </div>
                        <p class="leading-relaxed">
                            Upload <code>clockwork-companion.zip</code> in wp-admin (<strong>Plugins → Add New → Upload Plugin</strong>), activate it, and copy the <strong>Connection Key</strong> from <strong>Tools → Clockwork</strong>.
                        </p>
                    </div>

                    <form method="POST" action="{{ route('sites.store') }}" @submit="addSubmitting = true" class="space-y-4">
                        @csrf

                        {{-- Mode Selector --}}
                        <div class="flex items-center justify-between pb-1">
                            <span class="text-xs font-bold text-[var(--color-ink-strong)] uppercase tracking-wider">Pairing Method</span>
                            <div class="inline-flex items-center bg-[var(--color-surface-alt)] p-1 rounded-lg border border-[var(--color-border-light)] text-xs">
                                <button type="button" @click="addMode = 'key'"
                                        :class="addMode === 'key' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)]'"
                                        class="px-2.5 py-0.5 rounded-md transition-all cursor-pointer">
                                    Connection Key
                                </button>
                                <button type="button" @click="addMode = 'manual'"
                                        :class="addMode === 'manual' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)]'"
                                        class="px-2.5 py-0.5 rounded-md transition-all cursor-pointer">
                                    Manual
                                </button>
                            </div>
                        </div>

                        {{-- Connection Key Input --}}
                        <div x-show="addMode === 'key'">
                            <label for="modal_connection_key" class="block text-xs font-bold text-[var(--color-ink-strong)] uppercase tracking-wider mb-1.5">
                                Connection Key
                            </label>
                            <textarea
                                name="connection_key"
                                id="modal_connection_key"
                                rows="3"
                                x-model="addKey"
                                @input="parseAddKey()"
                                placeholder="Paste the Connection Key from wp-admin → Tools → Clockwork…"
                                class="w-full font-mono text-xs p-3 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-[var(--color-ink-strong)]"
                            ></textarea>
                        </div>

                        {{-- Domain field --}}
                        <div>
                            <label for="modal_domain" class="block text-xs font-bold text-[var(--color-ink-strong)] uppercase tracking-wider mb-1.5">
                                Site Domain
                            </label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-[var(--color-ink-soft)] font-mono">https://</span>
                                <input
                                    type="text"
                                    name="domain"
                                    id="modal_domain"
                                    x-model="addDomain"
                                    required
                                    placeholder="client-site.com"
                                    class="w-full pl-20 pr-4 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-sm font-mono text-[var(--color-ink-strong)]"
                                >
                            </div>
                        </div>

                        {{-- Companion Secret --}}
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label for="modal_secret" class="block text-xs font-bold text-[var(--color-ink-strong)] uppercase tracking-wider">
                                    Companion Secret
                                </label>
                                <button type="button" @click="generateSecret()" class="text-[11px] text-[var(--color-brand)] hover:underline cursor-pointer">
                                    Generate Secret
                                </button>
                            </div>
                            <input
                                type="text"
                                name="companion_secret"
                                id="modal_secret"
                                x-model="addSecret"
                                required
                                placeholder="32-byte hex secret"
                                class="w-full px-4 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-xs font-mono text-[var(--color-ink-strong)]"
                            >
                        </div>

                        <input type="hidden" name="hosting_provider" value="custom">
                        <p class="text-[11px] text-[var(--color-ink-soft)]">
                            Enrolled as Custom / Standalone — Companion REST, live TLS, and direct-to-S3 Glacier backups. No host API required.
                        </p>

                        {{-- Buttons --}}
                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-[var(--color-border-light)]">
                            <button type="button" @click="showAddModal = false" class="px-4 py-2 rounded-xl text-sm font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] transition-colors cursor-pointer">
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="addSubmitting"
                                class="inline-flex items-center gap-2 px-6 py-2 rounded-xl text-sm font-bold bg-[var(--color-brand)] text-white hover:bg-[var(--color-brand-hover)] shadow-xs transition-all cursor-pointer disabled:opacity-50"
                            >
                                <i class="fa-solid fa-link" x-show="!addSubmitting"></i>
                                <i class="fa-solid fa-spinner fa-spin" x-show="addSubmitting" style="display: none;"></i>
                                <span x-text="addSubmitting ? 'Verifying…' : 'Verify & Connect'">Verify & Connect</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>


@endsection
