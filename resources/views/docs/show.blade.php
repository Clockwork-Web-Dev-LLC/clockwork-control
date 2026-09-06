@extends('layouts.app')

@section('title', $page->title.' · Clockwork docs')

@section('content')
    <div class="docs-shell">
        @include('docs._sidebar')

        <main class="docs-main">
            <nav class="text-xs text-[var(--color-ink-muted)] mb-2 flex items-center gap-1.5 flex-wrap">
                <a href="{{ route('docs.index') }}" class="hover:underline">Documentation</a>
                <span class="opacity-60">/</span>
                <span>{{ $page->section }}</span>
                @if ($page->category && $page->category !== $page->section && $page->category !== 'General')
                    <span class="opacity-60">/</span>
                    <span class="text-[var(--color-ink-soft)]">{{ $page->category }}</span>
                @endif
                <span class="opacity-60">/</span>
                <span class="text-[var(--color-ink-strong)] font-medium">{{ $page->title }}</span>
            </nav>

            <h1 class="display-heading text-3xl text-[var(--color-ink-strong)] mb-2">{{ $page->title }}</h1>

            <div class="text-xs text-[var(--color-ink-muted)] mb-6 flex items-center gap-3 flex-wrap">
                @if ($page->category && $page->category !== $page->section && $page->category !== 'General')
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] font-medium text-[11px] text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-folder-open text-[10px] text-[var(--color-primary-600)]"></i>
                        {{ $page->category }}
                    </span>
                @endif
                @if ($page->author)
                    <span><i class="fa-regular fa-user"></i> by <strong>{{ $page->author }}</strong></span>
                @endif
                @if ($page->updated)
                    <span><i class="fa-regular fa-clock"></i> updated <span class="font-data">{{ $page->updated->format('M j, Y') }}</span></span>
                @endif
                @if (count($page->tags) > 0)
                    <span class="flex items-center gap-1">
                        <i class="fa-solid fa-tag"></i>
                        @foreach ($page->tags as $t)
                            <span class="px-1.5 py-0.5 rounded bg-[var(--color-surface-alt)] font-data text-[10px]">{{ $t }}</span>
                        @endforeach
                    </span>
                @endif
            </div>

            @if ($staleness['stale'])
                <div class="card p-4 mb-6 border border-[var(--color-status-yellow)] bg-[color-mix(in_srgb,var(--color-status-yellow)_10%,white)] text-sm">
                    <div class="flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)] mt-0.5"></i>
                        <div class="flex-1">
                            <strong class="text-[var(--color-ink-strong)]">This page may be stale.</strong>
                            @if ($staleness['days_behind'] !== null && $staleness['days_behind'] > 0)
                                Code in the tracked area changed
                                <strong>{{ $staleness['days_behind'] }} {{ $staleness['days_behind'] === 1 ? 'day' : 'days' }}</strong>
                                after this doc's last update
                            @else
                                The doc has no <code class="font-data text-xs">updated:</code> date but tracks code that has commits
                            @endif
                            @if ($staleness['latest_code_commit'])
                                (latest commit <span class="font-data">{{ $staleness['latest_code_commit']->format('M j, Y') }}</span>).
                            @else
                                .
                            @endif
                            <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                                Tracks:
                                @foreach ($staleness['tracked_paths'] as $t)
                                    <code class="font-data">{{ $t }}</code>@if (! $loop->last), @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <article class="docs-prose">
                {!! $page->htmlBody !!}
            </article>

            @if ($related->isNotEmpty())
                <hr class="my-8 border-[var(--color-border-light)]">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">Related</h2>
                    <ul class="space-y-2">
                        @foreach ($related as $r)
                            <li>
                                <a href="{{ $r->url() }}" class="text-sm text-[var(--color-primary-600)] hover:underline">
                                    {{ $r->title }}
                                </a>
                                <span class="text-xs text-[var(--color-ink-muted)]">· {{ $r->section }}@if($r->category && $r->category !== $r->section) &rsaquo; {{ $r->category }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </main>
    </div>

    @include('docs._styles')
@endsection
