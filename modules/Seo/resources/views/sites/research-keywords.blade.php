@extends('layouts.app')

@section('title', 'Ranked keywords · '.$domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" :subtitle="'Ranked keywords: what '.$domain.' ranks for on Google'.($isClient ? '' : ' (a competitor)').'.'">
    <x-slot:actions>
        <a href="{{ route('seo.sites.research', $site) }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-arrow-left"></i> Back to Research</a>
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

@php
    $inputClass = 'mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full';
@endphp

<x-card title="Filter" icon="fa-filter" class="mb-6">
    <form method="GET" action="{{ route('seo.sites.research.keywords', $site) }}">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
            <label class="block text-sm col-span-2">
                <span class="font-medium text-[var(--color-ink-strong)]">Domain</span>
                <select name="domain" class="{{ $inputClass }} font-data">
                    @foreach ($domains as $d)
                        <option value="{{ $d }}" @selected($d === $domain)>{{ $d }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Keyword contains</span>
                <input type="text" name="contains" maxlength="100" value="{{ $filters['contains'] ?? '' }}" class="{{ $inputClass }}">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Page contains</span>
                <input type="text" name="url_contains" maxlength="200" value="{{ $filters['url_contains'] ?? '' }}" placeholder="/blog/" class="{{ $inputClass }}">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Position from</span>
                <input type="number" name="min_position" min="1" max="100" value="{{ $filters['min_position'] ?? '' }}" placeholder="1" class="{{ $inputClass }}">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Position to</span>
                <input type="number" name="max_position" min="1" max="100" value="{{ $filters['max_position'] ?? '' }}" placeholder="100" class="{{ $inputClass }}">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Volume at least</span>
                <input type="number" name="min_volume" min="0" value="{{ $filters['min_volume'] ?? '' }}" class="{{ $inputClass }}">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Sort by</span>
                <select name="sort" class="{{ $inputClass }}">
                    @foreach (['traffic' => 'Estimated traffic', 'position' => 'Best position', 'volume' => 'Volume', 'keyword' => 'A to Z'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['sort'] ?? 'traffic') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-primary text-sm px-4 py-2">Apply</button>
            <a href="{{ route('seo.sites.research.keywords', [$site, 'domain' => $domain]) }}" class="text-sm text-[var(--color-ink-muted)] hover:underline">Clear filters</a>
            <span class="text-xs text-[var(--color-ink-soft)]">Quick:</span>
            <a href="{{ route('seo.sites.research.keywords', [$site, 'domain' => $domain, 'max_position' => 3]) }}" class="btn-pill-nav text-xs">Top 3</a>
            <a href="{{ route('seo.sites.research.keywords', [$site, 'domain' => $domain, 'max_position' => 10]) }}" class="btn-pill-nav text-xs">Page one</a>
            <a href="{{ route('seo.sites.research.keywords', [$site, 'domain' => $domain, 'min_position' => 11, 'max_position' => 20, 'sort' => 'volume']) }}" class="btn-pill-nav text-xs">Page two, biggest first</a>
        </div>
    </form>
</x-card>

<section class="card">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] font-data">{{ $domain }}</h2>
        <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
            @if ($stored === 0)
                No keyword list is stored for this domain.
            @else
                {{ number_format($keywords->total()) }} of {{ number_format($stored) }} stored keywords match · list bought {{ substr((string) $fetched, 0, 10) }}.
                This is the domain's top keywords by estimated traffic (up to 1,000), not all of them. Positions are country-level (location {{ $market->locationCode }}) and can be up to 90 days old.
            @endif
        </p>
    </div>

    @if ($stored === 0)
        <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">
            Buy this domain's list on the <a href="{{ route('seo.sites.research', $site) }}" class="underline">Research page</a> (about ${{ rtrim(rtrim(number_format($listPrice, 3), '0'), '.') }}).
            Only this client and the competitors named on its Rankings page can be bought there.
        </p>
    @elseif ($keywords->isEmpty())
        <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">No stored keyword matches these filters.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr>
                        <th class="px-5 py-2 font-semibold">Keyword</th>
                        <th class="px-5 py-2 font-semibold text-right">Position</th>
                        <th class="px-5 py-2 font-semibold text-right">Was</th>
                        <th class="px-5 py-2 font-semibold text-right">Volume</th>
                        <th class="px-5 py-2 font-semibold text-right">Difficulty</th>
                        <th class="px-5 py-2 font-semibold">Intent</th>
                        <th class="px-5 py-2 font-semibold text-right">Est. traffic</th>
                        <th class="px-5 py-2 font-semibold">Page</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($keywords as $k)
                        <tr data-ranked-keyword="{{ $k->keyword }}">
                            <td class="px-5 py-2">{{ $k->keyword }}</td>
                            <td class="px-5 py-2 text-right font-data font-semibold">{{ $k->position }}</td>
                            <td class="px-5 py-2 text-right font-data text-[var(--color-ink-soft)]">{{ $k->previous_position ?? '—' }}</td>
                            <td class="px-5 py-2 text-right font-data">{{ $k->volume !== null ? number_format($k->volume) : '—' }}</td>
                            <td class="px-5 py-2 text-right font-data">{{ $k->difficulty ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs">{{ $k->intent ?? '—' }}</td>
                            <td class="px-5 py-2 text-right font-data">{{ $k->estimated_traffic !== null ? number_format($k->estimated_traffic, 1) : '—' }}</td>
                            <td class="px-5 py-2 text-xs font-data max-w-xs truncate" title="{{ $k->url }}">{{ $k->url ? (Str::limit(preg_replace('#^https?://[^/]+#', '', $k->url) ?: '/', 60)) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($keywords->hasPages())
            <div class="px-5 py-3 border-t border-[var(--color-border-light)]">{{ $keywords->links() }}</div>
        @endif
    @endif
</section>
@endsection
