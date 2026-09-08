@extends('layouts.app')

@section('title', 'Module directory · Clockwork')

@section('content')
    <div class="mb-10" x-data="{
        activeCategory: 'all',
        searchQuery: '',
        matches(category, name, desc, tags, author) {
            const catMatch = this.activeCategory === 'all' || category === this.activeCategory;
            if (!catMatch) return false;
            if (!this.searchQuery.trim()) return true;
            const q = this.searchQuery.toLowerCase();
            const text = (name + ' ' + desc + ' ' + (tags || []).join(' ') + ' ' + author).toLowerCase();
            return text.includes(q);
        }
    }">
        @include('settings._tabs')

        <!-- Page Header -->
        <x-page-header title="Module directory"
            subtitle="Browse official bundled integrations and community extensions indexed in our directory feed. Official modules are tested and bundled; community packages can be added from GitHub.">
            <x-slot:actions>
                <!-- Search Input in Header -->
                <div class="relative w-64 sm:w-72">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[var(--color-ink-muted)]"></i>
                    <input type="text"
                           x-model="searchQuery"
                           placeholder="Search modules, tags, author..."
                           class="w-full pl-8 pr-8 py-1.5 text-xs rounded-full border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-muted)] focus:outline-hidden focus:ring-2 focus:ring-[var(--color-brand)]/20 focus:border-[var(--color-brand)] transition-all">
                    <button type="button"
                            x-show="searchQuery.length > 0"
                            @click="searchQuery = ''"
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]"
                            aria-label="Clear search">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('settings.modules.refresh') }}" class="inline">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-sm">
                        <i class="fa-solid fa-arrows-rotate"></i>
                        <span>Check for updates</span>
                    </button>
                </form>
                <a href="{{ route('settings.integrations.index') }}" class="btn-pill-nav text-sm">
                    <i class="fa-solid fa-key text-[var(--color-ink-muted)]"></i>
                    <span>API credentials</span>
                </a>
                <a href="{{ route('setup.modules.edit') }}" class="btn-pill-nav text-sm">
                    <i class="fa-solid fa-sliders text-[var(--color-ink-muted)]"></i>
                    <span>Toggle enabled</span>
                </a>
            </x-slot:actions>
        </x-page-header>

        @if (session('status'))
            <div class="card p-4 mb-6 status-green flex items-center gap-2">
                <i class="fa-solid fa-circle-check"></i>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        <!-- Feed Info / Status Banner -->
        <div class="card p-4 mb-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-[var(--color-ink-muted)]">
            <div class="flex items-center gap-2.5">
                <span class="status-pill {{ $feedSource === 'network' ? 'status-green' : 'status-yellow' }} text-[11px] font-mono">
                    <span class="status-dot"></span>
                    <span>Feed: {{ $feedSource === 'network' ? 'Live' : 'Cached' }}</span>
                </span>
                <span>Directory feed provided by <a href="https://clockworkcontrol.com/api/modules.json" target="_blank" class="font-mono text-[var(--color-primary-600)] hover:underline">clockworkcontrol.com/api/modules.json</a></span>
            </div>
            <div class="flex items-center gap-3">
                @if ($generatedAt)
                    <span class="font-data text-[11px]">Updated: {{ \Carbon\Carbon::parse($generatedAt)->diffForHumans() }}</span>
                @endif
                <a href="https://clockworkcontrol.com/contributing" target="_blank" class="text-[var(--color-primary-600)] hover:underline font-semibold flex items-center gap-1">
                    <span>Submit a Module</span>
                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                </a>
            </div>
        </div>

        <!-- Category Tabs (Pill Nav) -->
        <div class="flex items-center gap-1.5 overflow-x-auto pb-2 mb-6 scrollbar-none">
            @foreach ($categories as $catKey => $catLabel)
                <button type="button"
                        @click="activeCategory = '{{ $catKey }}'"
                        class="btn-pill-nav text-xs whitespace-nowrap cursor-pointer flex-shrink-0"
                        :class="activeCategory === '{{ $catKey }}' ? 'is-active' : ''">
                    {{ $catLabel }}
                </button>
            @endforeach
        </div>

        <!-- Filter query active indicator -->
        <div x-cloak x-show="searchQuery.trim().length > 0" class="text-xs text-[var(--color-ink-muted)] mb-4 -mt-2 flex items-center justify-between">
            <span>Filtering modules by <strong class="text-[var(--color-ink-strong)] font-semibold" x-text="'&ldquo;' + searchQuery + '&rdquo;'"></strong></span>
            <button type="button" @click="searchQuery = ''; activeCategory = 'all'" class="text-[var(--color-brand)] hover:underline font-medium">Reset filters</button>
        </div>

        <!-- Module Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ($modules as $mod)
                @php
                    // Community entries come from an external network feed
                    // (clockworkcontrol.com/api/modules.json) with no guaranteed
                    // schema — default every free-text field so a submission
                    // missing one doesn't break this card's rendering.
                    $mod['name'] ??= $mod['id'] ?? 'Unknown module';
                    $mod['author'] ??= 'Unknown';
                    $mod['description'] ??= '';
                    $isCommunity = ($mod['status'] ?? '') === 'community';
                    $isTesting = ($mod['status'] ?? '') === 'looking_for_testers';
                    $isOfficial = in_array($mod['status'] ?? '', ['official', 'verified'], true);
                @endphp
                <div class="card p-5 rounded-[var(--radius-card)] border border-[var(--color-border)] bg-[var(--color-surface)] flex flex-col justify-between space-y-4 hover:border-[var(--color-border-strong)] transition-all shadow-xs"
                     x-show="matches('{{ $mod['category'] ?? 'misc' }}', '{{ addslashes($mod['name']) }}', '{{ addslashes($mod['description']) }}', {{ json_encode($mod['tags'] ?? []) }}, '{{ addslashes($mod['author']) }}')">

                    <div>
                        <!-- Card Header: Logo, Title, Badges -->
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-10 h-10 flex items-center justify-center flex-shrink-0">
                                    <x-service-logo :service="$mod['icon'] ?? $mod['id']" class="w-8 h-8" />
                                </div>
                                <div class="min-w-0">
                                    <h3 class="font-display font-bold text-base text-[var(--color-ink-strong)] leading-snug truncate">
                                        {{ $mod['name'] }}
                                    </h3>
                                    <div class="text-[11px] text-[var(--color-ink-muted)] flex items-center gap-1.5 mt-0.5">
                                        <span>by</span>
                                        @if (!empty($mod['author_url']))
                                            <a href="{{ $mod['author_url'] }}" target="_blank" class="hover:underline text-[var(--color-ink-strong)] font-medium">{{ $mod['author'] }}</a>
                                        @else
                                            <span class="font-medium">{{ $mod['author'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <!-- Trust Tier Badge -->
                            <div class="flex-shrink-0">
                                @if ($isOfficial)
                                    <span class="status-pill status-green text-[10px]">
                                        <span class="status-dot"></span>
                                        <span>{{ ucfirst($mod['status']) }}</span>
                                    </span>
                                @elseif ($isTesting)
                                    <span class="status-pill status-yellow text-[10px]" title="{{ $mod['status_note'] ?? 'Testing' }}">
                                        <span class="status-dot"></span>
                                        <span>Testing</span>
                                    </span>
                                @else
                                    <span class="status-pill status-unknown text-[10px]" title="Community Contributed">
                                        <span class="status-dot"></span>
                                        <span>Community</span>
                                    </span>
                                @endif
                            </div>
                        </div>

                        <!-- Description -->
                        <p class="text-xs text-[var(--color-ink-muted)] leading-relaxed line-clamp-3">
                            {{ $mod['description'] }}
                        </p>

                    </div>

                    <!-- Footer: Status & Actions -->
                    <div class="pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between gap-2">
                        <div>
                            @if (!empty($mod['is_bundled']))
                                @if (!empty($mod['is_enabled']))
                                    <span class="status-pill status-green text-[10px]">
                                        <span class="status-dot"></span>
                                        <span>Active in fleet</span>
                                    </span>
                                @else
                                    <span class="status-pill status-unknown text-[10px]">
                                        <span class="status-dot"></span>
                                        <span>Disabled</span>
                                    </span>
                                @endif
                            @else
                                <span class="status-pill status-yellow text-[10px]">
                                    <i class="fa-solid fa-triangle-exclamation text-[10px]"></i>
                                    <span>Unofficial</span>
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            @if (!empty($mod['is_bundled']))
                                <a href="{{ route('settings.integrations.index') }}#integration-{{ $mod['id'] }}" class="btn-pill-nav text-xs py-1 px-2.5">
                                    Configure
                                </a>
                            @endif

                            @if (!empty($mod['repository']))
                                <a href="{{ $mod['repository'] }}" target="_blank" rel="noopener noreferrer" class="btn-pill-nav text-xs py-1 px-2.5 flex items-center gap-1" title="View Source on GitHub">
                                    <i class="fa-brands fa-github"></i>
                                    <span>Repo</span>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
