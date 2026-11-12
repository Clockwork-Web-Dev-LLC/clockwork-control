@extends('layouts.app')

@section('title', 'Research · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="Research: how many keywords this site and its competitors rank for, which ones, and where the gaps are.">
</x-page-header>

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

@php
    $buy = function (string $what, string $domain, string $label) use ($site, $prices, $configured) {
        return ['what' => $what, 'domain' => $domain, 'label' => $label.' ($'.rtrim(rtrim(number_format($prices[$what], 3), '0'), '.').')'];
    };
@endphp

<div class="mb-6 p-4 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)]">
    These figures come from DataForSEO's own keyword database for one country (location {{ $market->locationCode }}, {{ $market->languageCode }}).
    They are not comparable with counts from another tool, each keyword's position can be up to 90 days old, and nothing here is city-level.
    For this client's real clicks use Numbers; for exact positions today use Rankings.
    @unless ($configured) <strong class="text-[var(--color-status-red)]">No DataForSEO login is set, so nothing can be bought yet.</strong> @endunless
</div>

<section class="card mb-6">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]"><i class="fa-solid fa-hashtag text-[var(--color-ink-soft)] mr-2"></i>Ranking keywords</h2>
        <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">This client and the competitors named on its Rankings page. Counted automatically on the 6th of each month.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                <tr>
                    <th class="px-5 py-2 font-semibold">Domain</th>
                    <th class="px-5 py-2 font-semibold text-right">Keywords</th>
                    <th class="px-5 py-2 font-semibold text-right">vs month before</th>
                    <th class="px-5 py-2 font-semibold text-right">Top 3</th>
                    <th class="px-5 py-2 font-semibold text-right">Top 10</th>
                    <th class="px-5 py-2 font-semibold text-right">Est. traffic</th>
                    <th class="px-5 py-2 font-semibold">Counted</th>
                    <th class="px-5 py-2 font-semibold">Keyword list</th>
                    <th class="px-5 py-2 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @foreach ($rows as $row)
                    @php $s = $row['latest']; @endphp
                    <tr data-domain-row="{{ $row['domain'] }}">
                        <td class="px-5 py-2 font-data">{{ $row['domain'] }} @if ($row['is_client'])<span class="status-pill status-green text-[10px]">Client</span>@endif</td>
                        <td class="px-5 py-2 text-right font-data font-semibold" data-keywords>{{ $s?->keywords !== null ? number_format($s->keywords) : '—' }}</td>
                        <td class="px-5 py-2 text-right font-data">
                            @if ($row['change'] === null) <span class="text-[var(--color-ink-soft)]">—</span>
                            @else <span class="{{ $row['change'] >= 0 ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]' }}">{{ $row['change'] > 0 ? '+' : '' }}{{ number_format($row['change']) }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-2 text-right font-data">{{ $s?->top3 !== null ? number_format($s->top3) : '—' }}</td>
                        <td class="px-5 py-2 text-right font-data">{{ $s?->top10 !== null ? number_format($s->top10) : '—' }}</td>
                        <td class="px-5 py-2 text-right font-data">{{ $s?->estimated_traffic !== null ? number_format($s->estimated_traffic) : '—' }}</td>
                        <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $s?->month->format('M Y') ?? 'Not yet' }}</td>
                        <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $row['list_stored'] > 0 ? number_format($row['list_stored']).' stored '.$row['list_fetched'] : 'Not bought' }}</td>
                        <td class="px-5 py-2 text-right whitespace-nowrap">
                            @foreach (array_filter([$row['counted_this_month'] ? null : $buy('count', $row['domain'], 'Count'), $buy('ranked_keywords', $row['domain'], $row['list_stored'] > 0 ? 'Refresh list' : 'Buy list')]) as $b)
                                <form method="POST" action="{{ route('seo.sites.research.buy', $site) }}" class="inline"
                                      onsubmit="return confirm('This spends money on DataForSEO. Continue?')">
                                    @csrf
                                    <input type="hidden" name="what" value="{{ $b['what'] }}">
                                    <input type="hidden" name="domain" value="{{ $b['domain'] }}">
                                    <button type="submit" class="btn-pill-nav text-xs" @disabled(! $configured)>{{ $b['label'] }}</button>
                                </form>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <x-card title="Keyword gap" icon="fa-code-compare">
        @if ($gap === null)
            <p class="text-sm text-[var(--color-ink-muted)]">Name competitors on the <a href="{{ route('seo.sites.rankings', $site) }}" class="underline">Rankings page</a> to compare against them.</p>
        @elseif ($gap['competitors'] === [])
            <p class="text-sm text-[var(--color-ink-muted)]">No competitor has a keyword list yet. Buy a list for at least one competitor above.</p>
        @else
            <p class="text-xs text-[var(--color-ink-soft)] mb-3">
                Against {{ implode(', ', $gap['competitors']) }}.
                @if ($gap['without_data']) Left out, no list bought: {{ implode(', ', $gap['without_data']) }}. @endif
                @unless ($gap['client_has_data']) <span class="text-[var(--color-status-red)]">This client's own list is not bought, so everything looks like a gap.</span> @endunless
            </p>
            <div class="grid grid-cols-3 gap-2 mb-4 text-center" data-gap-counts>
                @foreach ($gap['counts'] as $segment => $count)
                    <div class="p-2 rounded border border-[var(--color-border-light)]" title="{{ $gapSegments[$segment] }}">
                        <div class="font-data text-lg font-semibold text-[var(--color-ink-strong)]">{{ number_format($count) }}</div>
                        <div class="text-xs text-[var(--color-ink-muted)]">{{ ucfirst($segment) }}</div>
                    </div>
                @endforeach
            </div>
            @if ($gap['rows'] !== [])
                <p class="text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Missing: every competitor ranks, this client doesn't</p>
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($gap['rows'] as $r)
                            <tr><td class="py-1">{{ $r['keyword'] }}</td><td class="py-1 text-right font-data">{{ $r['volume'] !== null ? number_format($r['volume']) : '—' }}</td><td class="py-1 text-right font-data text-[var(--color-ink-soft)]">best #{{ $r['best_competitor_position'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <p class="text-xs text-[var(--color-ink-soft)] mt-3">A gap is a lead to check: lists hold each domain's top 1,000 keywords, not all of them. Ask Claude for the other groups or the full list.</p>
        @endif
    </x-card>

    <x-card title="Who else ranks for the same keywords" icon="fa-users-viewfinder">
        @if ($discovered->isEmpty())
            <p class="text-sm text-[var(--color-ink-muted)] mb-3">Not looked up yet.</p>
        @else
            <table class="w-full text-xs mb-3">
                <thead class="text-left uppercase tracking-wider text-[var(--color-ink-muted)]"><tr><th class="py-1 font-semibold">Domain</th><th class="py-1 font-semibold text-right">Shared</th><th class="py-1 font-semibold text-right">Their keywords</th></tr></thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($discovered as $c)
                        <tr><td class="py-1 font-data">{{ $c->competitor_domain }} @if (in_array($c->competitor_domain, $named, true))<span class="status-pill status-unknown text-[10px]">Named</span>@endif</td>
                            <td class="py-1 text-right font-data">{{ $c->shared_keywords !== null ? number_format($c->shared_keywords) : '—' }}</td>
                            <td class="py-1 text-right font-data">{{ $c->competitor_keywords !== null ? number_format($c->competitor_keywords) : '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-xs text-[var(--color-ink-soft)] mb-3">Directories and publishers show up beside real rivals. Add the real ones on the Rankings page.</p>
        @endif
        <form method="POST" action="{{ route('seo.sites.research.buy', $site) }}" onsubmit="return confirm('This spends money on DataForSEO. Continue?')">
            @csrf
            <input type="hidden" name="what" value="competitors">
            <button type="submit" class="btn-pill-nav text-sm" @disabled(! $configured)>{{ $discovered->isEmpty() ? 'Find competitors' : 'Look again' }} (${{ rtrim(rtrim(number_format($prices['competitors'], 3), '0'), '.') }})</button>
        </form>
    </x-card>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <x-card title="This client's top keywords" icon="fa-list-ol">
        @if ($topKeywords->isEmpty())
            <p class="text-sm text-[var(--color-ink-muted)]">Buy this client's keyword list above to see what it ranks for.</p>
        @else
            <table class="w-full text-xs">
                <thead class="text-left uppercase tracking-wider text-[var(--color-ink-muted)]"><tr><th class="py-1 font-semibold">Keyword</th><th class="py-1 font-semibold text-right">Position</th><th class="py-1 font-semibold text-right">Volume</th></tr></thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($topKeywords as $k)
                        <tr><td class="py-1">{{ $k->keyword }}</td><td class="py-1 text-right font-data">{{ $k->position }}</td><td class="py-1 text-right font-data">{{ $k->volume !== null ? number_format($k->volume) : '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-xs text-[var(--color-ink-soft)] mt-3">The 25 with the most estimated traffic. Ask Claude to filter the full list.</p>
        @endif
    </x-card>

    <x-card title="Keyword ideas" icon="fa-lightbulb">
        @if ($ideaPulls->isEmpty())
            <p class="text-sm text-[var(--color-ink-muted)]">No idea lists yet. Ask Claude for keyword ideas for this client; it quotes the price before buying.</p>
        @else
            <table class="w-full text-xs">
                <thead class="text-left uppercase tracking-wider text-[var(--color-ink-muted)]"><tr><th class="py-1 font-semibold">Seeds</th><th class="py-1 font-semibold">Type</th><th class="py-1 font-semibold text-right">Ideas</th><th class="py-1 font-semibold">Bought</th></tr></thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($ideaPulls as $p)
                        <tr><td class="py-1">{{ Str::limit(implode(', ', $p->seeds), 60) }}</td><td class="py-1">{{ $p->mode }}</td><td class="py-1 text-right font-data">{{ number_format($p->keyword_count) }}</td><td class="py-1 text-[var(--color-ink-muted)]">{{ $p->created_at?->toDateString() }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-xs text-[var(--color-ink-soft)] mt-3">Stored lists can be read again through Claude at no cost.</p>
        @endif
    </x-card>
</div>
@endsection
