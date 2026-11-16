@extends('layouts.app')

@section('title', 'Keywords · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="Keywords: look up volume, difficulty, CPC and intent for a list, and get new keyword ideas." />

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

@php
    $inputClass = 'mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full';
    $money = fn (float $v) => '$'.rtrim(rtrim(number_format($v, 3), '0'), '.');
    $band = fn (?int $d) => \Modules\Seo\Keywords\KeywordMetricsService::difficultyBand($d);
@endphp

@unless ($configured)
    <div class="mb-6 p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-sm" role="alert">
        No DataForSEO login is set, so nothing can be bought. Stored figures still show. An admin adds the login under SEO on Settings → Integrations.
    </div>
@endunless

@if ($lookup && $lookup['notice'])
    <div class="mb-4 p-4 rounded-xl text-sm {{ $lookup['notice_ok'] ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300' }}" role="status">{{ $lookup['notice'] }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <x-card title="Look up keywords" icon="fa-magnifying-glass">
        <form method="POST" action="{{ route('seo.sites.keywords.lookup', $site) }}">
            @csrf
            <label class="block text-sm mb-3">
                <span class="font-medium text-[var(--color-ink-strong)]">Keywords, one per line (up to 700)</span>
                <textarea name="keywords" rows="7" required class="{{ $inputClass }}" placeholder="emergency plumber&#10;water heater repair">{{ old('keywords', $lookup['text'] ?? '') }}</textarea>
            </label>
            <p class="text-xs text-[var(--color-ink-soft)] mb-3">
                Shows what Control already has and what the rest would cost. Looking up is free.
                Market: location {{ $market->locationCode }}, {{ $market->languageCode }}{{ $market->city ? ', city volume for '.$market->city : '' }} (from the profile).
            </p>
            <button type="submit" class="btn-primary text-sm px-4 py-2">Look up</button>
        </form>
    </x-card>

    <x-card title="Get keyword ideas" icon="fa-lightbulb">
        <form method="POST" action="{{ route('seo.sites.keywords.ideas', $site) }}"
              onsubmit="return confirm('This buys a list from DataForSEO at the price shown. Continue?')">
            @csrf
            <label class="block text-sm mb-3">
                <span class="font-medium text-[var(--color-ink-strong)]">Seed keywords, one per line</span>
                <textarea name="seeds" rows="3" required class="{{ $inputClass }}" placeholder="drain cleaning">{{ old('seeds') }}</textarea>
            </label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                <label class="block text-sm">
                    <span class="font-medium text-[var(--color-ink-strong)]">Kind of list</span>
                    <select name="mode" class="{{ $inputClass }}">
                        <option value="ideas" @selected(old('mode', 'ideas') === 'ideas')>Same topics (up to 20 seeds)</option>
                        <option value="suggestions" @selected(old('mode') === 'suggestions')>Contains the phrase (1 seed)</option>
                        <option value="related" @selected(old('mode') === 'related')>Related searches (1 seed)</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-[var(--color-ink-strong)]">How many</span>
                    <select name="limit" class="{{ $inputClass }}">
                        @foreach ($ideaPrices as $count => $price)
                            <option value="{{ $count }}" @selected((int) old('limit', 300) === $count)>Up to {{ number_format($count) }} (about {{ $money($price) }})</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <p class="text-xs text-[var(--color-ink-soft)] mb-3">Each idea comes with its figures, which are saved, so it is never paid for twice. Ideas are for the whole country.</p>
            <button type="submit" class="btn-primary text-sm px-4 py-2" @disabled(! $configured)>Buy ideas</button>
        </form>
    </x-card>
</div>

@if ($lookup)
    <section class="card mb-6" data-lookup-results>
        <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Results</h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                    {{ count($lookup['rows']) }} keywords · {{ count($lookup['rows']) - $lookup['to_buy'] }} with current figures · {{ $lookup['to_buy'] }} to buy.
                    @if ($lookup['over_limit']) {{ $lookup['over_limit'] }} past the limit of 700 were left out. @endif
                    @if ($lookup['rejected']) Not usable: {{ collect($lookup['rejected'])->take(6)->map(fn ($r) => $r['keyword'].' ('.$r['reason'].')')->implode('; ') }}. @endif
                </p>
            </div>
            @if ($lookup['to_buy'] > 0)
                @if ($lookup['refusal'])
                    <p class="text-xs text-[var(--color-status-red)] max-w-md">{{ $lookup['refusal'] }}</p>
                @else
                    <form method="POST" action="{{ route('seo.sites.keywords.buy', $site) }}"
                          onsubmit="return confirm('Buy figures for {{ $lookup['to_buy'] }} keywords for about {{ $money($lookup['estimate']) }}?')">
                        @csrf
                        <input type="hidden" name="keywords" value="{{ $lookup['text'] }}">
                        <button type="submit" class="btn-primary text-sm px-4 py-2" @disabled(! $configured)>Buy the {{ $lookup['to_buy'] }} missing (about {{ $money($lookup['estimate']) }})</button>
                    </form>
                @endif
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr><th class="px-5 py-2 font-semibold">Keyword</th><th class="px-5 py-2 font-semibold text-right">Volume</th>@if ($market->city)<th class="px-5 py-2 font-semibold text-right">In {{ Str::before($market->city, ',') }}</th>@endif<th class="px-5 py-2 font-semibold text-right">Difficulty</th><th class="px-5 py-2 font-semibold text-right">CPC</th><th class="px-5 py-2 font-semibold">Intent</th><th class="px-5 py-2 font-semibold">Figures</th></tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($lookup['rows'] as $row)
                        @php $m = $row['metric']; @endphp
                        <tr data-lookup-row="{{ $row['keyword'] }}">
                            <td class="px-5 py-2">{{ $row['keyword'] }}</td>
                            <td class="px-5 py-2 text-right font-data">{{ $m?->volume !== null ? number_format($m->volume) : '—' }}</td>
                            @if ($market->city)<td class="px-5 py-2 text-right font-data">{{ $row['city_volume'] !== null ? number_format($row['city_volume']) : '—' }}</td>@endif
                            <td class="px-5 py-2 text-right font-data whitespace-nowrap">@if ($m?->difficulty !== null){{ $m->difficulty }} <span class="text-xs text-[var(--color-ink-soft)]">{{ $band($m->difficulty) }}</span>@else — @endif</td>
                            <td class="px-5 py-2 text-right font-data">{{ $m?->cpc !== null ? '$'.number_format($m->cpc, 2) : '—' }}</td>
                            <td class="px-5 py-2 text-xs">{{ $m?->intent ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">
                                @if ($m === null) Not bought yet
                                @elseif ($m->no_data) DataForSEO has no data
                                @elseif ($row['stale']) Out of date ({{ $m->fetched_at?->toDateString() }})
                                @else {{ $m->fetched_at?->toDateString() }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="card">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]"><i class="fa-solid fa-lightbulb text-[var(--color-ink-soft)] mr-2"></i>Idea lists</h2>
        <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Lists already bought for this client. Opening one is free.</p>
    </div>
    @if ($pulls->isEmpty())
        <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">No idea lists yet.</p>
    @else
        <div class="px-5 py-3 flex flex-wrap gap-2 border-b border-[var(--color-border-light)]">
            @foreach ($pulls as $p)
                <a href="{{ route('seo.sites.keywords', [$site, 'list' => $p->id]) }}"
                   class="btn-pill-nav text-xs {{ $pull && $pull->id === $p->id ? 'font-semibold' : '' }}">
                    {{ Str::limit(implode(', ', $p->seeds), 40) }} · {{ number_format($p->keyword_count) }} · {{ $p->created_at?->toDateString() }}
                </a>
            @endforeach
        </div>
        @if ($pull)
            <div class="overflow-x-auto" data-idea-list="{{ $pull->id }}">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                        <tr><th class="px-5 py-2 font-semibold">Idea</th><th class="px-5 py-2 font-semibold text-right">Volume</th><th class="px-5 py-2 font-semibold text-right">Difficulty</th><th class="px-5 py-2 font-semibold text-right">CPC</th><th class="px-5 py-2 font-semibold">Intent</th></tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($ideaRows->take(500) as $row)
                            @php $m = $row['metric']; @endphp
                            <tr>
                                <td class="px-5 py-2">{{ $row['keyword'] }}</td>
                                <td class="px-5 py-2 text-right font-data">{{ $m?->volume !== null ? number_format($m->volume) : '—' }}</td>
                                <td class="px-5 py-2 text-right font-data whitespace-nowrap">@if ($m?->difficulty !== null){{ $m->difficulty }} <span class="text-xs text-[var(--color-ink-soft)]">{{ $band($m->difficulty) }}</span>@else — @endif</td>
                                <td class="px-5 py-2 text-right font-data">{{ $m?->cpc !== null ? '$'.number_format($m->cpc, 2) : '—' }}</td>
                                <td class="px-5 py-2 text-xs">{{ $m?->intent ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($ideaRows->count() > 500)
                <p class="px-5 py-3 text-xs text-[var(--color-ink-soft)]">Showing the 500 with the most volume of {{ number_format($ideaRows->count()) }}.</p>
            @endif
        @else
            <p class="px-5 py-6 text-sm text-[var(--color-ink-muted)] text-center">Choose a list above to see its ideas.</p>
        @endif
    @endif
</section>
@endsection
