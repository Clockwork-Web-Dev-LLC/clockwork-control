@extends('layouts.app')

@section('title', $page->title.' · Clockwork Control Docs')

@section('content')
    <div class="docs-shell">
        @include('docs._sidebar')

        <main class="docs-main">
            {{-- Breadcrumb Navigation --}}
            <nav class="text-xs text-[var(--color-ink-muted)] mb-3 flex items-center gap-1.5 flex-wrap">
                <a href="{{ route('docs.index') }}" class="hover:text-[var(--color-brand)] transition-colors flex items-center gap-1">
                    <i class="fa-solid fa-house text-[10px] opacity-70"></i>
                    <span>Documentation</span>
                </a>
                <i class="fa-solid fa-chevron-right text-[8px] opacity-40"></i>
                <span class="text-[var(--color-ink-soft)]">{{ $page->section }}</span>
                @if ($page->category && $page->category !== $page->section && $page->category !== 'General')
                    <i class="fa-solid fa-chevron-right text-[8px] opacity-40"></i>
                    <span class="text-[var(--color-ink-soft)]">{{ $page->category }}</span>
                @endif
                <i class="fa-solid fa-chevron-right text-[8px] opacity-40"></i>
                <span class="text-[var(--color-ink-strong)] font-semibold truncate">{{ $page->title }}</span>
            </nav>

            {{-- Document Heading --}}
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-[var(--color-ink-strong)] mb-2.5">
                {{ $page->title }}
            </h1>

            {{-- Document Metadata Ribbon --}}
            <div class="text-xs text-[var(--color-ink-muted)] mb-6 pb-4 border-b border-[var(--color-border-light)] flex items-center gap-3 flex-wrap">
                @if ($page->category && $page->category !== $page->section && $page->category !== 'General')
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] font-medium text-[11px] text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-folder-open text-[10px] text-[var(--color-brand)]"></i>
                        <span>{{ $page->category }}</span>
                    </span>
                @endif
                @if ($page->author)
                    <span class="inline-flex items-center gap-1.5">
                        <i class="fa-regular fa-user text-[11px] opacity-70"></i>
                        <span>by <strong class="text-[var(--color-ink-strong)]">{{ $page->author }}</strong></span>
                    </span>
                @endif
                @if ($page->updated)
                    <span class="inline-flex items-center gap-1.5">
                        <i class="fa-regular fa-clock text-[11px] opacity-70"></i>
                        <span>updated <span class="font-data font-medium text-[var(--color-ink-strong)]">{{ $page->updated->format('M j, Y') }}</span></span>
                    </span>
                @endif
                @if (count($page->tags) > 0)
                    <span class="inline-flex items-center gap-1.5 flex-wrap">
                        <i class="fa-solid fa-tag text-[10px] opacity-60"></i>
                        @foreach ($page->tags as $t)
                            <span class="px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] font-data text-[10px] text-[var(--color-ink-soft)] font-medium">{{ $t }}</span>
                        @endforeach
                    </span>
                @endif
            </div>

            {{-- Staleness Warning Banner --}}
            @if ($staleness['stale'])
                <div class="card p-4 mb-6 border border-amber-500/40 bg-amber-50/50 dark:bg-amber-950/20 text-xs sm:text-sm shadow-xs">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-lg bg-amber-500/15 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                            <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-bold text-[var(--color-ink-strong)] text-sm mb-0.5">This documentation may be out of date.</div>
                            <p class="text-xs sm:text-sm text-[var(--color-ink-muted)] leading-relaxed">
                                @if ($staleness['days_behind'] !== null && $staleness['days_behind'] > 0)
                                    Code in the tracked area changed
                                    <strong class="text-[var(--color-ink-strong)]">{{ $staleness['days_behind'] }} {{ $staleness['days_behind'] === 1 ? 'day' : 'days' }}</strong>
                                    after this doc's last update
                                @else
                                    The doc has no <code class="font-data text-xs px-1 py-0.2 rounded bg-[var(--color-surface)] border border-[var(--color-border)]">updated:</code> date but tracks code that has commits
                                @endif
                                @if ($staleness['latest_code_commit'])
                                    (latest commit <span class="font-data font-medium">{{ $staleness['latest_code_commit']->format('M j, Y') }}</span>).
                                @else
                                    .
                                @endif
                            </p>
                            <div class="text-[11px] text-[var(--color-ink-muted)] mt-2 flex items-center gap-1.5 flex-wrap">
                                <span class="font-semibold text-[var(--color-ink-soft)] uppercase tracking-wider text-[9px]">Tracks:</span>
                                @foreach ($staleness['tracked_paths'] as $t)
                                    <code class="font-data text-[11px] px-1.5 py-0.5 rounded bg-[var(--color-surface)] border border-[var(--color-border)] text-[var(--color-ink-strong)]">{{ $t }}</code>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Main Prose Body --}}
            <article class="docs-prose">
                {!! $page->htmlBody !!}
            </article>

            {{-- Related Docs Footer --}}
            @if ($related->isNotEmpty())
                <div class="mt-12 pt-6 border-t border-[var(--color-border-light)]">
                    <div class="flex items-center gap-2 mb-4">
                        <i class="fa-solid fa-compass text-sm text-[var(--color-brand)]"></i>
                        <h2 class="font-display text-base sm:text-lg font-bold text-[var(--color-ink-strong)]">
                            Related Reading
                        </h2>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($related as $r)
                            <a href="{{ $r->url() }}" class="card p-3.5 hover:border-[var(--color-border)] hover:shadow-card-lift transition-all duration-150 flex items-start justify-between gap-3 group">
                                <div class="min-w-0">
                                    <div class="text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                                        <span>{{ $r->section }}</span>
                                        @if($r->category && $r->category !== $r->section)
                                            <span>&rsaquo; {{ $r->category }}</span>
                                        @endif
                                    </div>
                                    <div class="text-sm font-bold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors truncate">
                                        {{ $r->title }}
                                    </div>
                                </div>
                                <div class="w-7 h-7 rounded-lg bg-[var(--color-surface-alt)] flex items-center justify-center text-[var(--color-ink-soft)] group-hover:text-[var(--color-brand)] group-hover:bg-[var(--color-primary-50)] shrink-0 transition-colors">
                                    <i class="fa-solid fa-arrow-right text-xs group-hover:translate-x-0.5 transition-transform"></i>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Quick Page Footer Actions --}}
            <div class="mt-8 pt-4 border-t border-[var(--color-border-light)] flex items-center justify-between text-xs text-[var(--color-ink-muted)]">
                <a href="{{ route('docs.index') }}" class="hover:text-[var(--color-brand)] flex items-center gap-1.5 transition-colors">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i>
                    <span>Back to Documentation Home</span>
                </a>
                <a href="#" onclick="window.scrollTo({top: 0, behavior: 'smooth'}); return false;" class="hover:text-[var(--color-brand)] flex items-center gap-1.5 transition-colors">
                    <span>Back to top</span>
                    <i class="fa-solid fa-arrow-up text-[10px]"></i>
                </a>
            </div>
        </main>
    </div>

    @include('docs._styles')
@endsection
