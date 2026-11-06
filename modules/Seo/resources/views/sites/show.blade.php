@extends('layouts.app')

@section('title', 'Search numbers · '.$site->domain.' · Clockwork')

@php
    $rows = [
        ['Search Console', 'Clicks', $numbers['gsc']['clicks'], 'int'],
        ['Search Console', 'Impressions', $numbers['gsc']['impressions'], 'int'],
        ['Search Console', 'CTR', $numbers['gsc']['ctr'], 'pct'],
        ['Search Console', 'Average position', $numbers['gsc']['position'], 'dec'],
        ['Search Console', 'Non-branded clicks', $numbers['gsc']['nonbranded_clicks'], 'int'],
        ['Search Console', 'Non-branded impressions', $numbers['gsc']['nonbranded_impressions'], 'int'],
        ['Search Console', 'Organic keywords (queries with impressions)', $numbers['gsc']['organic_keywords'], 'int'],
        ['GA4', 'Organic sessions', $numbers['ga4']['organic_sessions'], 'int'],
        ['GA4', 'Organic engaged sessions', $numbers['ga4']['organic_engaged_sessions'], 'int'],
        ['GA4', 'Organic engagement rate', $numbers['ga4']['organic_engagement_rate'], 'pct'],
        ['GA4', 'AI assistant sessions', $numbers['ga4']['ai_assistant_sessions'], 'int'],
    ];
    foreach ($numbers['ga4']['key_events'] as $event => $metric) {
        $rows[] = ['GA4', 'Key events: '.$event, $metric, 'int'];
    }

    $fmt = function ($value, string $kind): string {
        if ($value === null) {
            return '—';
        }

        return match ($kind) {
            'pct' => number_format((float) $value, $value == (int) $value ? 0 : 1).'%',
            'dec' => number_format((float) $value, 1),
            default => number_format((float) $value),
        };
    };
    $change = function (array $metric, string $baseline): string {
        if (! $metric[$baseline.'_comparable']) {
            return 'not comparable';
        }
        if ($metric[$baseline.'_change_pct'] !== null) {
            return ($metric[$baseline.'_change_pct'] > 0 ? '+' : '').$metric[$baseline.'_change_pct'].'%';
        }
        if ($metric[$baseline.'_change'] !== null) {
            return ($metric[$baseline.'_change'] > 0 ? '+' : '').$metric[$baseline.'_change'];
        }

        return '—';
    };
    $stateLabel = ['final' => ['status-green', 'Final'], 'provisional' => ['status-yellow', 'Provisional'], 'not_synced' => ['status-unknown', 'Not synced']][$numbers['data_state']];
@endphp

@section('content')
<x-page-header :title="$site->domain" subtitle="Search numbers from the nightly Search Console and GA4 sync.">
    <x-slot:actions>
        <a href="{{ route('seo.sites.profile', $site) }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-sliders"></i> Profile</a>
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::_flash')

<section class="card">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">{{ $numbers['month'] }}</h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                <span class="status-pill {{ $stateLabel[0] }} text-[10px]">{{ $stateLabel[1] }}</span>
                @if ($numbers['data_state'] === 'provisional')
                    final after {{ $numbers['final_after'] }}
                @endif
            </p>
        </div>
        @if ($months !== [])
            <form method="GET" class="flex items-center gap-2 text-sm">
                <label for="month" class="text-[var(--color-ink-muted)]">Month</label>
                <select id="month" name="month" onchange="this.form.submit()" class="text-sm px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded font-data">
                    @foreach ($months as $m)
                        <option value="{{ $m }}" @selected($m === $numbers['month'])>{{ $m }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn-pill-nav text-xs">Show</button></noscript>
            </form>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-search-numbers>
            <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                <tr>
                    <th class="px-5 py-2 font-semibold">Metric</th>
                    <th class="px-5 py-2 font-semibold text-right">{{ $numbers['month'] }}</th>
                    <th class="px-5 py-2 font-semibold text-right">{{ $numbers['previous_month'] }}</th>
                    <th class="px-5 py-2 font-semibold text-right">Change</th>
                    <th class="px-5 py-2 font-semibold text-right">{{ $numbers['yoy_month'] }}</th>
                    <th class="px-5 py-2 font-semibold text-right">YoY</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @foreach ($rows as [$source, $label, $metric, $kind])
                    <tr data-metric="{{ $label }}">
                        <td class="px-5 py-2"><span class="text-xs text-[var(--color-ink-soft)] mr-1">{{ $source }}</span> {{ $label }}</td>
                        <td class="px-5 py-2 text-right font-data">{{ $fmt($metric['value'], $kind) }}</td>
                        <td class="px-5 py-2 text-right font-data text-[var(--color-ink-muted)]">{{ $fmt($metric['previous'], $kind) }}</td>
                        <td class="px-5 py-2 text-right font-data">{{ $change($metric, 'previous') }}</td>
                        <td class="px-5 py-2 text-right font-data text-[var(--color-ink-muted)]">{{ $fmt($metric['yoy'], $kind) }}</td>
                        <td class="px-5 py-2 text-right font-data">{{ $change($metric, 'yoy') }}</td>
                    </tr>
                @endforeach
                <tr data-metric="AI impressions">
                    <td class="px-5 py-2"><span class="text-xs text-[var(--color-ink-soft)] mr-1">Search Console</span> AI impressions (Generative AI report)</td>
                    <td class="px-5 py-2 text-right font-data">
                        @if ($numbers['ai']['status'] === 'entered')
                            {{ number_format((int) $numbers['ai']['ai_impressions']) }}
                        @elseif ($numbers['ai']['status'] === 'not_available')
                            not available
                        @else
                            not entered
                        @endif
                    </td>
                    <td colspan="4" class="px-5 py-2 text-xs text-[var(--color-ink-soft)]">
                        @if ($numbers['ai']['ai_share_pct'] !== null)
                            AI share {{ $numbers['ai']['ai_share_pct'] }}% of web impressions
                        @else
                            <a href="{{ route('seo.sites.profile', $site) }}" class="text-[var(--color-primary-600)] hover:underline">Enter on the profile page</a>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="px-5 py-4 border-t border-[var(--color-border-light)]">
        <h3 class="text-xs uppercase tracking-wider font-semibold text-[var(--color-ink-muted)] mb-2">Caveats</h3>
        <ul class="text-xs text-[var(--color-ink)] space-y-1 list-disc pl-5" data-caveats>
            @foreach ($numbers['caveats'] as $caveat)
                <li data-caveat="{{ $caveat['code'] }}">{{ $caveat['message'] }}</li>
            @endforeach
        </ul>
    </div>
</section>
@endsection
