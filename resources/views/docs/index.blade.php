@extends('layouts.app')

@section('title', 'Documentation · Clockwork')

@section('content')
@php
    $canonicalOrder = $sectionOrder ?? \App\Http\Controllers\DocsController::canonicalSectionOrder();
    $orderedSections = collect($canonicalOrder)
        ->merge($tree->keys()->diff($canonicalOrder))
        ->unique()
        ->filter(fn ($s) => $tree->has($s));
@endphp

<div class="docs-shell">
    @include('docs._sidebar')

    <main class="docs-main">
        @php $totalStale = collect($staleBySection)->sum(); @endphp
        <x-page-header title="Documentation"
            subtitle="The team handbook for Clockwork. Casual reference for 'how does this work?', with runbooks for the messy moments. Edit markdown in resources/docs/ — pages update on save.">
            <x-slot:actions>
                @if ($totalStale > 0)
                    <span class="status-pill status-yellow text-xs" title="Tracked code changed after the doc's updated date">
                        <span class="status-dot"></span>
                        <span>{{ $totalStale }} stale {{ $totalStale === 1 ? 'page' : 'pages' }}</span>
                    </span>
                @endif
                @if (count($undocumented) > 0)
                    <span class="status-pill status-red text-xs" title="Undocumented files exist">
                        <span class="status-dot"></span>
                        <span>{{ count($undocumented) }} undocumented</span>
                    </span>
                @endif
            </x-slot:actions>
        </x-page-header>

        <!-- Contributing & Tester Callout Banner -->
        <div class="card p-5 mb-6 border border-emerald-500/30 bg-gradient-to-r from-emerald-50/50 to-indigo-50/30 dark:from-emerald-950/20 dark:to-indigo-950/20">
            <div class="flex items-start justify-between gap-4 flex-wrap sm:flex-nowrap">
                <div class="flex items-start gap-3.5">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fa-solid fa-handshake-angle text-lg"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <h2 class="font-display text-base font-bold text-[var(--color-ink-strong)]">
                                Looking for Integration Testers &amp; Contributors
                            </h2>
                            <span class="status-pill status-yellow text-[10px] px-2 py-0.5">
                                <i class="fa-solid fa-flask"></i> Testing needed
                            </span>
                        </div>
                        <p class="text-xs sm:text-sm text-[var(--color-ink-muted)] leading-relaxed max-w-3xl">
                            We've written full integration modules for <strong>WP Engine</strong>, <strong>Kinsta</strong>, <strong>Cloudways</strong>, <strong>Hetzner</strong>, <strong>Azure</strong>, <strong>Linode</strong>, <strong>Vultr</strong>, <strong>GitHub OAuth</strong>, and <strong>Microsoft OAuth</strong>. We need agencies using these platforms to test with real credentials! Just test live, vibe code any fixes with Claude, and open a PR back.
                        </p>
                    </div>
                </div>
                <a href="{{ url('/docs/getting-started/contributing') }}" class="btn-pill-nav text-xs font-semibold whitespace-nowrap self-start sm:self-center flex items-center gap-1.5 px-3.5 py-2 bg-[var(--color-primary-600)] text-white hover:bg-[var(--color-primary-700)] rounded-lg">
                    <span>How to contribute</span>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>
        </div>

        @if (count($undocumented) > 0)
            <div class="card p-5 mb-5 border border-[var(--color-status-red)]">
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1">
                    <i class="fa-solid fa-circle-question text-[var(--color-status-red)] mr-1"></i>
                    Undocumented
                </h2>
                <p class="text-sm text-[var(--color-ink-muted)] mb-3">
                    Commands and controllers with no doc page's <code class="font-data text-xs">tracks:</code> list referencing them at all —
                    different from the staleness check above, which only catches docs that <em>exist but fell behind</em>. This is the blind spot:
                    a brand-new feature with zero documentation looks identical to one that was never built, until something like this checks for it.
                </p>
                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1">
                    @foreach ($undocumented as $file)
                        <li class="text-sm font-data text-[var(--color-ink-muted)]">{{ $file }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            @foreach ($orderedSections as $section)
                @php
                    $pages = $tree->get($section, collect());
                    $sectionStale = $staleBySection[$section] ?? 0;
                    $hasSubcategories = isset($groupedTree) && $groupedTree->has($section) && $groupedTree->get($section)->count() > 1;
                    $isWide = in_array($section, ['Integrations', 'Features'], true);
                @endphp
                <div class="card p-5 {{ $isWide ? 'md:col-span-2' : '' }}">
                    <div class="flex items-center justify-between gap-2 mb-1 flex-wrap">
                        <h2 class="font-display text-lg font-bold text-[var(--color-ink-strong)] flex items-center gap-2">
                            <span>{{ $section }}</span>
                            <span class="text-xs text-[var(--color-ink-muted)] font-normal">&bull; {{ $pages->count() }} {{ \Illuminate\Support\Str::plural('page', $pages->count()) }}</span>
                        </h2>
                        @if ($sectionStale > 0)
                            <span class="inline-flex items-center justify-center h-5 px-2 rounded-full text-[10px] font-semibold bg-[var(--color-status-yellow)] text-white" title="{{ $sectionStale }} stale pages">
                                {{ $sectionStale }} stale
                            </span>
                        @endif
                    </div>
                    <p class="text-xs sm:text-sm text-[var(--color-ink-muted)] mb-4">
                        {{ $sectionDescriptions[$section] ?? '' }}
                    </p>

                    @if ($hasSubcategories)
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 pt-1">
                            @foreach ($groupedTree->get($section) as $categoryName => $catPages)
                                <div class="p-3.5 rounded-lg bg-[var(--color-surface-alt)]/60 border border-[var(--color-border-light)]">
                                    <div class="text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-strong)] mb-2 flex items-center justify-between border-b border-[var(--color-border-light)] pb-1.5">
                                        <span class="truncate">{{ $categoryName }}</span>
                                        <span class="font-mono text-[10px] text-[var(--color-ink-soft)] px-1.5 py-0.2 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)]">{{ $catPages->count() }}</span>
                                    </div>
                                    <ul class="space-y-1">
                                        @foreach ($catPages as $p)
                                            <li>
                                                <a href="{{ $p->url() }}" class="text-xs text-[var(--color-primary-600)] hover:underline flex items-center gap-1.5 py-0.5 group">
                                                    <i class="fa-solid fa-angle-right text-[9px] text-[var(--color-ink-soft)] group-hover:text-[var(--color-primary-600)] transition-colors"></i>
                                                    <span class="truncate">{{ $p->title }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <ul class="space-y-1.5">
                            @forelse ($pages as $p)
                                <li>
                                    <a href="{{ $p->url() }}" class="text-sm text-[var(--color-primary-600)] hover:underline font-medium">
                                        {{ $p->title }}
                                    </a>
                                    @if ($p->excerpt)
                                        <span class="block text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $p->excerpt }}</span>
                                    @endif
                                </li>
                            @empty
                                <li class="text-xs text-[var(--color-ink-muted)]"><em>No pages yet.</em></li>
                            @endforelse
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
    </main>
</div>

    @include('docs._styles')
@endsection
