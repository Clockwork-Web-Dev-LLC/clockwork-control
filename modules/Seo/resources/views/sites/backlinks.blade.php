@extends('layouts.app')

@section('title', 'Backlinks · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="Backlinks: who links to this site, what is new or lost, and who links to competitors but not here." />

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

@php
    $money = fn (float $v) => '$'.rtrim(rtrim(number_format($v, 3), '0'), '.');
    $buyForm = function (string $what, string $domain, string $label) use ($site, $configured, $money, $prices) {
        return ['what' => $what, 'domain' => $domain, 'label' => $label.' ('.$money($prices[$what]).')'];
    };
@endphp

<div class="mb-6 p-4 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)]">
    Link counts come from DataForSEO's own index and will not match another tool's. Rank and spam score are DataForSEO's 0 to 100 measures, not an authority or toxicity score from elsewhere, and a high spam score alone is not a reason to disavow a link.
    @unless ($configured) <strong class="text-[var(--color-status-red)]">No DataForSEO login is set, so nothing can be bought yet.</strong> @endunless
</div>

<section class="card mb-6">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]"><i class="fa-solid fa-link text-[var(--color-ink-soft)] mr-2"></i>Totals</h2>
        <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">This client and the competitors named on its Rankings page. Counted automatically on the 7th of each month.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                <tr>
                    <th class="px-5 py-2 font-semibold">Domain</th>
                    <th class="px-5 py-2 font-semibold text-right">Referring domains</th>
                    <th class="px-5 py-2 font-semibold text-right">vs month before</th>
                    <th class="px-5 py-2 font-semibold text-right">Backlinks</th>
                    <th class="px-5 py-2 font-semibold text-right">Rank</th>
                    <th class="px-5 py-2 font-semibold text-right">Spam score</th>
                    <th class="px-5 py-2 font-semibold">Counted</th>
                    <th class="px-5 py-2 font-semibold"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @foreach ($rows as $row)
                    @php
                        $s = $row['latest'];
                        $buttons = array_filter([
                            $row['counted_this_month'] ? null : $buyForm('backlink_summary', $row['domain'], 'Count'),
                            $row['is_client'] ? null : $buyForm('backlink_gap', $row['domain'], $row['gap_stored'] > 0 ? 'Refresh gap' : 'Find gap'),
                        ]);
                    @endphp
                    <tr data-backlink-row="{{ $row['domain'] }}">
                        <td class="px-5 py-2 font-data">{{ $row['domain'] }} @if ($row['is_client'])<span class="status-pill status-green text-[10px]">Client</span>@endif</td>
                        <td class="px-5 py-2 text-right font-data font-semibold" data-referring>{{ $s?->referring_domains !== null ? number_format($s->referring_domains) : '—' }}</td>
                        <td class="px-5 py-2 text-right font-data">
                            @if ($row['change'] === null) <span class="text-[var(--color-ink-soft)]">—</span>
                            @else <span class="{{ $row['change'] >= 0 ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]' }}">{{ $row['change'] > 0 ? '+' : '' }}{{ number_format($row['change']) }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-2 text-right font-data">{{ $s?->backlinks !== null ? number_format($s->backlinks) : '—' }}</td>
                        <td class="px-5 py-2 text-right font-data">{{ $s?->rank ?? '—' }}</td>
                        <td class="px-5 py-2 text-right font-data">{{ $s?->spam_score ?? '—' }}</td>
                        <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $s?->month->format('M Y') ?? 'Not yet' }}</td>
                        <td class="px-5 py-2 text-right whitespace-nowrap">
                            @foreach ($buttons as $b)
                                <form method="POST" action="{{ route('seo.sites.backlinks.buy', $site) }}" class="inline" onsubmit="return confirm('This spends money on DataForSEO. Continue?')">
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

<section class="card mb-6">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]"><i class="fa-solid fa-diagram-project text-[var(--color-ink-soft)] mr-2"></i>Domains linking to {{ $own }}</h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                @if ($pulled) List pulled {{ substr((string) $pulled, 0, 10) }}. New and lost are from the last 45 days, worked out between Control's own pulls.
                @else Not pulled yet.
                @endif
                @if ($counts['all'] >= 1000) Only the 1,000 strongest are stored, so lost domains cannot be told from ones past the cut. @endif
            </p>
        </div>
        <form method="POST" action="{{ route('seo.sites.backlinks.buy', $site) }}" onsubmit="return confirm('This spends money on DataForSEO. Continue?')">
            @csrf
            <input type="hidden" name="what" value="referring_domains">
            <button type="submit" class="btn-pill-nav text-sm" @disabled(! $configured)>{{ $pulled ? 'Pull again' : 'Pull the list' }} (up to {{ $money($prices['referring_domains']) }})</button>
        </form>
    </div>
    <div class="px-5 py-3 flex flex-wrap items-center gap-2 border-b border-[var(--color-border-light)]">
        @foreach (['all' => 'All live', 'new' => 'New', 'lost' => 'Lost', 'high_spam' => 'Spam score 60+'] as $value => $label)
            <a href="{{ route('seo.sites.backlinks', [$site, 'show' => $value]) }}" class="btn-pill-nav text-xs {{ $show === $value ? 'font-semibold' : '' }}" @if ($show === $value) aria-current="true" @endif>{{ $label }} ({{ number_format($counts[$value]) }})</a>
        @endforeach
        <form method="GET" action="{{ route('seo.sites.backlinks', $site) }}" class="ml-auto flex items-center gap-2">
            <input type="hidden" name="show" value="{{ $show }}">
            <input type="text" name="contains" value="{{ $contains }}" maxlength="100" placeholder="Domain contains" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded">
            <button type="submit" class="btn-pill-nav text-xs">Filter</button>
        </form>
    </div>
    @if ($referring->isEmpty())
        <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">{{ $pulled ? 'Nothing matches.' : 'Pull the list to see who links to this site.' }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr><th class="px-5 py-2 font-semibold">Linking domain</th><th class="px-5 py-2 font-semibold text-right">Rank</th><th class="px-5 py-2 font-semibold text-right">Links</th><th class="px-5 py-2 font-semibold text-right">Spam score</th><th class="px-5 py-2 font-semibold">First seen</th><th class="px-5 py-2 font-semibold">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($referring as $r)
                        <tr data-referring-domain="{{ $r->referring_domain }}">
                            <td class="px-5 py-2 font-data">{{ $r->referring_domain }}</td>
                            <td class="px-5 py-2 text-right font-data">{{ $r->rank ?? '—' }}</td>
                            <td class="px-5 py-2 text-right font-data">{{ $r->backlinks !== null ? number_format($r->backlinks) : '—' }}</td>
                            <td class="px-5 py-2 text-right font-data {{ ($r->spam_score ?? 0) >= 60 ? 'text-[var(--color-status-red)]' : '' }}">{{ $r->spam_score ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $r->first_seen?->toDateString() ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs">
                                @if ($r->lost_at) <span class="text-[var(--color-status-red)]">Lost {{ $r->lost_at->toDateString() }}</span>
                                @elseif ($r->found_at) <span class="text-[var(--color-status-green)]">New {{ $r->found_at->toDateString() }}</span>
                                @else Live
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($referring->hasPages())
            <div class="px-5 py-3 border-t border-[var(--color-border-light)]">{{ $referring->links() }}</div>
        @endif
    @endif
</section>

<x-card title="Backlink gap: who links to competitors but not here" icon="fa-code-compare">
    @if ($named === [])
        <p class="text-sm text-[var(--color-ink-muted)]">Name competitors on the <a href="{{ route('seo.sites.rankings', $site) }}" class="underline">Rankings page</a> first.</p>
    @elseif ($gap['compared'] === [])
        <p class="text-sm text-[var(--color-ink-muted)]">Not looked up yet. Use "Find gap" beside a competitor above (about {{ $money($prices['backlink_gap']) }} each).</p>
    @else
        <p class="text-xs text-[var(--color-ink-soft)] mb-3">
            Against {{ implode(', ', $gap['compared']) }}. Showing {{ count($gap['rows']) }} of {{ number_format($gap['total']) }}, domains linking to the most competitors first.
            These are leads to check: many are directories or sites that would never link to this business.
        </p>
        <table class="w-full text-sm" data-gap-table>
            <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                <tr><th class="py-1 font-semibold">Domain</th><th class="py-1 font-semibold text-right">Rank</th><th class="py-1 font-semibold text-right">Spam score</th><th class="py-1 font-semibold">Links to</th></tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @foreach ($gap['rows'] as $g)
                    <tr><td class="py-1 font-data">{{ $g['domain'] }}</td><td class="py-1 text-right font-data">{{ $g['rank'] ?? '—' }}</td><td class="py-1 text-right font-data {{ ($g['spam_score'] ?? 0) >= 60 ? 'text-[var(--color-status-red)]' : '' }}">{{ $g['spam_score'] ?? '—' }}</td><td class="py-1 text-xs text-[var(--color-ink-muted)]">{{ implode(', ', $g['competitors']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-card>
@endsection
