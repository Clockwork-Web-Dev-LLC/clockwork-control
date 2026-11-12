@extends('layouts.app')

@section('title', 'Rankings · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="Rankings: where this site appears on Google for its tracked keywords.">
    <x-slot:actions>
        <a href="{{ route('seo.sites.show', $site) }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-table"></i> Numbers</a>
        <a href="{{ route('seo.sites.profile', $site) }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-sliders"></i> Profile</a>
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::_flash')

@php
    $inputClass = 'mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full';
@endphp

@if (! $configured)
    <div class="mb-6 p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-sm" role="alert" data-rank-not-configured>
        Rank checks need a DataForSEO API login. You can add keywords now; nothing is checked until an admin adds the login under SEO on Settings → Integrations.
    </div>
@endif

<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <x-stat-box label="Tracked" :value="$summary['active']" icon="fa-key" />
    <x-stat-box label="Top 3" :value="$summary['checked'] ? $summary['top3'] : '—'" icon="fa-trophy" />
    <x-stat-box label="Top 10" :value="$summary['checked'] ? $summary['top10'] : '—'" icon="fa-ranking-star" />
    <x-stat-box label="Moved up" :value="$summary['checked'] ? $summary['improved'] : '—'" icon="fa-arrow-trend-up" />
    <x-stat-box label="Moved down" :value="$summary['checked'] ? $summary['declined'] : '—'" icon="fa-arrow-trend-down" />
</div>

<section class="card mb-6">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
            <i class="fa-solid fa-ranking-star text-[var(--color-ink-soft)] mr-2"></i>
            Tracked keywords
        </h2>
        <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
            Checked {{ $frequency }}, top {{ $depth }} results.
            @if ($summary['last_checked']) Last check {{ $summary['last_checked'] }}. @endif
            @if ($pending > 0) {{ $pending }} {{ Str::plural('check', $pending) }} waiting on DataForSEO across all clients. @endif
            About ${{ number_format($monthlyEstimate, 2) }} a month for this client at these settings.
        </p>
    </div>

    @if ($rows === [])
        <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">No keywords tracked yet. Add some below.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr>
                        <th class="px-5 py-2 font-semibold">Keyword</th>
                        <th class="px-5 py-2 font-semibold text-right">Position</th>
                        <th class="px-5 py-2 font-semibold text-right">Change</th>
                        <th class="px-5 py-2 font-semibold">Page</th>
                        @foreach ($competitors as $competitor)
                            <th class="px-5 py-2 font-semibold text-right normal-case font-data">{{ $competitor }}</th>
                        @endforeach
                        <th class="px-5 py-2 font-semibold">AI Overview</th>
                        <th class="px-5 py-2 font-semibold">Checked</th>
                        <th class="px-5 py-2 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($rows as $row)
                        <tr data-keyword-row="{{ $row['id'] }}" class="{{ $row['is_active'] ? '' : 'opacity-60' }}">
                            <td class="px-5 py-2">
                                <span class="text-[var(--color-ink-strong)]">{{ $row['keyword'] }}</span>
                                <span class="block text-xs text-[var(--color-ink-soft)]">
                                    {{ ucfirst($row['device']) }}{{ $row['place'] ? ' · '.$row['place'] : '' }}{{ $row['is_active'] ? '' : ' · paused' }}
                                </span>
                            </td>
                            <td class="px-5 py-2 text-right font-data" data-position>
                                @if ($row['checked_on'] === null)
                                    <span class="text-[var(--color-ink-soft)]" title="Not checked yet">—</span>
                                @elseif ($row['position'] === null)
                                    <span class="text-[var(--color-ink-muted)]" title="Not within the results looked at">Not in top {{ $row['depth'] ?: $depth }}</span>
                                @else
                                    <span class="font-semibold text-[var(--color-ink-strong)]">{{ $row['position'] }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-right font-data">
                                @if ($row['movement'] === 'entered')
                                    <span class="text-[var(--color-status-green)]">New</span>
                                @elseif ($row['movement'] === 'dropped_out')
                                    <span class="text-[var(--color-status-red)]">Dropped out</span>
                                @elseif ($row['change'] === null || $row['change'] === 0)
                                    <span class="text-[var(--color-ink-soft)]">{{ $row['change'] === 0 ? '0' : '—' }}</span>
                                @else
                                    <span class="{{ $row['change'] > 0 ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]' }}">{{ $row['change'] > 0 ? '▲' : '▼' }} {{ abs($row['change']) }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs font-data max-w-xs truncate">
                                @if ($row['url'])
                                    <span title="{{ $row['url'] }}">{{ Str::limit(preg_replace('#^https?://[^/]+#', '', $row['url']) ?: '/', 50) }}</span>
                                    @if (($row['pages_ranking'] ?? 0) > 1)
                                        <span class="text-[var(--color-status-yellow)]" title="{{ $row['pages_ranking'] }} of this site's pages rank for this keyword">· {{ $row['pages_ranking'] }} pages</span>
                                    @endif
                                @endif
                            </td>
                            @foreach ($competitors as $competitor)
                                <td class="px-5 py-2 text-right font-data text-[var(--color-ink-muted)]">{{ $row['competitors'][$competitor] ?? '—' }}</td>
                            @endforeach
                            <td class="px-5 py-2 text-xs">
                                @if ($row['cited_in_ai_overview'])
                                    <span class="status-pill status-green text-[10px]">Cited</span>
                                @elseif ($row['has_ai_overview'])
                                    <span class="status-pill status-unknown text-[10px]">Shown, not cited</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] whitespace-nowrap">{{ $row['checked_on'] ?? 'Not yet' }}</td>
                            <td class="px-5 py-2 text-right whitespace-nowrap">
                                <form method="POST" action="{{ route('seo.sites.rankings.toggle', [$site, $row['id']]) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn-pill-nav text-xs">{{ $row['is_active'] ? 'Pause' : 'Resume' }}</button>
                                </form>
                                <form method="POST" action="{{ route('seo.sites.rankings.destroy', [$site, $row['id']]) }}" class="inline"
                                      onsubmit="return confirm('Delete this keyword and all of its rank history? This cannot be undone. Pause it instead to keep the history.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn-pill-nav text-xs" aria-label="Delete keyword"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <x-card title="Add keywords" icon="fa-plus">
        <form method="POST" action="{{ route('seo.sites.rankings.store', $site) }}">
            @csrf
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Keywords, one per line</span>
                <textarea name="keywords" rows="6" required class="{{ $inputClass }}" placeholder="emergency plumber&#10;water heater repair">{{ old('keywords') }}</textarea>
            </label>
            <div class="flex flex-wrap items-center gap-4 text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Devices</span>
                <label class="flex items-center gap-2"><input type="checkbox" name="devices[]" value="desktop" @checked(in_array('desktop', old('devices', ['desktop'])))> Desktop</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="devices[]" value="mobile" @checked(in_array('mobile', old('devices', [])))> Mobile</label>
            </div>
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">City <span class="font-normal text-[var(--color-ink-soft)]">(optional)</span></span>
                <input type="text" name="city" maxlength="191" value="{{ old('city', $market->city) }}" placeholder="Austin,Texas,United States" class="{{ $inputClass }}">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Leave blank to check the whole country (location code {{ $market->locationCode }}, language {{ $market->languageCode }}, from the profile). For a local client, write the city as Google Ads names it: City,Region,Country.</span>
            </label>
            <p class="text-xs text-[var(--color-ink-muted)] mb-4">Each keyword on each device costs about ${{ number_format($perKeywordMonthly, 4) }} a month at the current settings ({{ $frequency }}, top {{ $depth }}).</p>
            <button type="submit" class="btn-primary text-sm px-4 py-2">Track these keywords</button>
        </form>
    </x-card>

    <x-card title="Competitors" icon="fa-flag-checkered">
        <form method="POST" action="{{ route('seo.sites.competitors', $site) }}">
            @csrf @method('PUT')
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Competitor domains, one per line (up to 10)</span>
                <textarea name="competitors" rows="6" class="{{ $inputClass }} font-data" placeholder="competitor.com">{{ old('competitors', implode("\n", $competitors)) }}</textarea>
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Their positions are read from the same results page as the client's, so competitors cost nothing extra. History starts from the next check after a competitor is added.</span>
            </label>
            <button type="submit" class="btn-primary text-sm px-4 py-2">Save competitors</button>
        </form>
    </x-card>
</div>
@endsection
