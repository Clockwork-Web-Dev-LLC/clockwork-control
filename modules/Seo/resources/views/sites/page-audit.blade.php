@extends('layouts.app')

@section('title', 'Page audit · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="Page audit: what a page on this site says about itself right now. Free." />

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

<x-card title="Audit pages" icon="fa-file-circle-check" class="mb-6">
    <form method="POST" action="{{ route('seo.sites.page-audit.run', $site) }}">
        @csrf
        <label class="block text-sm mb-3">
            <span class="font-medium text-[var(--color-ink-strong)]">Pages, one per line (up to {{ $max }})</span>
            <textarea name="urls" rows="4" required class="mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full font-data" placeholder="/services&#10;https://{{ $site->domain }}/contact">{{ old('urls') }}</textarea>
            <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Only pages on {{ $site->domain }}. A path such as /services is enough. Pages are read without running JavaScript, so text a page adds in the browser is not counted.</span>
        </label>
        <button type="submit" class="btn-primary text-sm px-4 py-2">Audit</button>
    </form>
</x-card>

<section class="card">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Latest audits</h2>
        <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">One row per page. Auditing a page again replaces its row.</p>
    </div>
    @if ($audits->isEmpty())
        <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">No pages audited yet.</p>
    @else
        <div class="divide-y divide-[var(--color-border-light)]">
            @foreach ($audits as $a)
                @php
                    $ok = $a->fetch_status === 'ok';
                    $titleLength = $a->title !== null ? mb_strlen($a->title) : null;
                    $metaLength = $a->meta_description !== null ? mb_strlen($a->meta_description) : null;
                    $h1Count = count($a->h1s ?? []);
                @endphp
                <div class="px-5 py-4 text-sm" data-audit="{{ $a->id }}">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span class="font-data text-[var(--color-ink-strong)] break-all">{{ $a->url }}</span>
                        <span class="status-pill {{ $ok ? 'status-green' : ($a->fetch_status === 'blocked' ? 'status-yellow' : 'status-red') }} text-[10px]">{{ ['ok' => 'Read', 'blocked' => 'Blocked', 'not_html' => 'Not a web page', 'error' => 'Could not read'][$a->fetch_status] ?? $a->fetch_status }}</span>
                        <span class="text-xs text-[var(--color-ink-soft)]">{{ $a->audited_at?->diffForHumans() }}</span>
                    </div>
                    @if (! $ok)
                        <p class="text-xs text-[var(--color-ink-muted)]">{{ $a->error }} Its title and other details are unknown, not empty.</p>
                    @else
                        <dl class="grid grid-cols-1 md:grid-cols-[10rem_1fr] gap-x-4 gap-y-1 text-xs">
                            <dt class="text-[var(--color-ink-muted)]">Title</dt>
                            <dd>{{ $a->title ?? 'None' }} @if ($titleLength)<span class="font-data {{ $titleLength > 60 || $titleLength < 30 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-soft)]' }}">{{ $titleLength }}</span>@endif</dd>
                            <dt class="text-[var(--color-ink-muted)]">Meta description</dt>
                            <dd>{{ $a->meta_description ?? 'None' }} @if ($metaLength)<span class="font-data {{ $metaLength > 160 || $metaLength < 70 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-soft)]' }}">{{ $metaLength }}</span>@endif</dd>
                            <dt class="text-[var(--color-ink-muted)]">H1</dt>
                            <dd class="{{ $h1Count === 1 ? '' : 'text-[var(--color-status-red)]' }}">{{ $h1Count === 0 ? 'None' : implode(' · ', $a->h1s) }}</dd>
                            @if ($a->h2s)
                                <dt class="text-[var(--color-ink-muted)]">H2s ({{ count($a->h2s) }})</dt>
                                <dd class="text-[var(--color-ink-muted)]">{{ Str::limit(implode(' · ', $a->h2s), 400) }}</dd>
                            @endif
                            <dt class="text-[var(--color-ink-muted)]">Words</dt>
                            <dd class="font-data">{{ number_format((int) $a->word_count) }}</dd>
                            <dt class="text-[var(--color-ink-muted)]">Schema</dt>
                            <dd>{{ $a->schema_types ? implode(', ', $a->schema_types) : 'None found' }}</dd>
                            <dt class="text-[var(--color-ink-muted)]">Canonical</dt>
                            <dd class="font-data break-all">{{ $a->canonical ?? 'None' }}</dd>
                            @if ($a->meta_robots)
                                <dt class="text-[var(--color-ink-muted)]">Robots</dt>
                                <dd class="{{ str_contains(strtolower($a->meta_robots), 'noindex') ? 'text-[var(--color-status-red)] font-semibold' : '' }}">{{ $a->meta_robots }}</dd>
                            @endif
                        </dl>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
@endsection
