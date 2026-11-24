@extends('layouts.app')

@section('title', 'Keyword gap · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="Keyword gap: how this client's ranking keywords compare with its competitors', group by group.">
    <x-slot:actions>
        <a href="{{ route('seo.sites.research', $site) }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-arrow-left"></i> Back to Research</a>
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

@php
    $inputClass = 'mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full';
    $kept = array_filter(['contains' => $filters['contains'] ?? null, 'min_volume' => $filters['min_volume'] ?? null], fn ($v) => $v !== null && $v !== '');
@endphp

@if ($gap === null)
    <x-card title="Keyword gap" icon="fa-code-compare">
        <p class="text-sm text-[var(--color-ink-muted)]">Name competitors on the <a href="{{ route('seo.sites.rankings', $site) }}" class="underline">Rankings page</a> to compare against them.</p>
    </x-card>
@elseif ($gap['competitors'] === [])
    <x-card title="Keyword gap" icon="fa-code-compare">
        <p class="text-sm text-[var(--color-ink-muted)]">No competitor has a keyword list yet. Buy a list for at least one competitor on the <a href="{{ route('seo.sites.research', $site) }}" class="underline">Research page</a>.</p>
    </x-card>
@else
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2 mb-6" data-gap-groups>
        @foreach ($gap['counts'] as $name => $count)
            @php $active = $name === $group; @endphp
            <a href="{{ route('seo.sites.research.gap', [$site, 'group' => $name] + $kept) }}" title="{{ $groups[$name] }}" @if ($active) aria-current="page" @endif
               class="block p-3 rounded border text-center transition-colors
                      {{ $active
                            ? 'border-[var(--color-ink-strong)] bg-[var(--color-ink-strong)] text-[var(--color-surface)]'
                            : 'border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:border-[var(--color-ink-muted)]' }}">
                <div class="font-data text-lg font-semibold">{{ number_format($count) }}</div>
                <div class="text-xs {{ $active ? '' : 'text-[var(--color-ink-muted)]' }}">{{ ucfirst($name) }}</div>
            </a>
        @endforeach
    </div>

    <x-card title="Filter" icon="fa-filter" class="mb-6">
        <form method="GET" action="{{ route('seo.sites.research.gap', $site) }}">
            <input type="hidden" name="group" value="{{ $group }}">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                <label class="block text-sm col-span-2">
                    <span class="font-medium text-[var(--color-ink-strong)]">Keyword contains</span>
                    <input type="text" name="contains" maxlength="100" value="{{ $filters['contains'] ?? '' }}" class="{{ $inputClass }}">
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-[var(--color-ink-strong)]">Volume at least</span>
                    <input type="number" name="min_volume" min="0" value="{{ $filters['min_volume'] ?? '' }}" class="{{ $inputClass }}">
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-primary text-sm px-4 py-2">Apply</button>
                <a href="{{ route('seo.sites.research.gap', [$site, 'group' => $group]) }}" class="text-sm text-[var(--color-ink-muted)] hover:underline">Clear filters</a>
            </div>
        </form>
    </x-card>

    <section class="card">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">{{ ucfirst($group) }}: {{ lcfirst($groups[$group]) }}</h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                Against {{ implode(', ', $gap['competitors']) }}.
                @if ($gap['without_data']) Left out, no list bought: {{ implode(', ', $gap['without_data']) }}. @endif
                @if (count($gap['rows']) < $gap['rows_matching'])
                    Showing the {{ number_format(count($gap['rows'])) }} with the most searches of {{ number_format($gap['rows_matching']) }}; filter to reach the rest.
                @else
                    {{ number_format($gap['rows_matching']) }} {{ $gap['rows_matching'] === 1 ? 'keyword' : 'keywords' }}.
                @endif
                A gap is a lead to check: lists hold each domain's top 1,000 keywords, not all of them.
            </p>
            @unless ($gap['client_has_data'])
                <p class="text-xs text-[var(--color-status-red)] mt-1">This client's own list is not bought, so everything looks like a gap.</p>
            @endunless
        </div>

        @if ($gap['rows'] === [])
            <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">No keyword in this group{{ $kept !== [] ? ' matches these filters' : '' }}.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                        <tr>
                            <th class="px-5 py-2 font-semibold">Keyword</th>
                            <th class="px-5 py-2 font-semibold text-right">Volume</th>
                            <th class="px-5 py-2 font-semibold text-right">Difficulty</th>
                            <th class="px-5 py-2 font-semibold">Intent</th>
                            <th class="px-5 py-2 font-semibold text-right">This client</th>
                            @foreach ($gap['competitors'] as $competitor)
                                <th class="px-5 py-2 font-semibold text-right normal-case font-data">{{ $competitor }}</th>
                            @endforeach
                            <th class="px-5 py-2 font-semibold">Client's page</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($gap['rows'] as $r)
                            <tr data-gap-keyword="{{ $r['keyword'] }}">
                                <td class="px-5 py-2">{{ $r['keyword'] }}</td>
                                <td class="px-5 py-2 text-right font-data">{{ $r['volume'] !== null ? number_format($r['volume']) : '—' }}</td>
                                <td class="px-5 py-2 text-right font-data">{{ $r['difficulty'] ?? '—' }}</td>
                                <td class="px-5 py-2 text-xs">{{ $r['intent'] ?? '—' }}</td>
                                <td class="px-5 py-2 text-right font-data font-semibold">{{ $r['client_position'] ?? '—' }}</td>
                                @foreach ($r['competitor_positions'] as $position)
                                    <td class="px-5 py-2 text-right font-data">{{ $position ?? '—' }}</td>
                                @endforeach
                                <td class="px-5 py-2 text-xs font-data max-w-xs truncate" title="{{ $r['client_url'] }}">{{ $r['client_url'] ? (Str::limit(preg_replace('#^https?://[^/]+#', '', $r['client_url']) ?: '/', 60)) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif
@endsection
