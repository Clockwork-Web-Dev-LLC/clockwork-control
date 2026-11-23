@extends('layouts.app')

@section('title', 'Site audit · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="Site audit: technical problems found by crawling the whole site, and what changed since the last crawl.">
    <x-slot:actions>
        <form method="POST" action="{{ route('seo.sites.site-audit.run', $site) }}"
              onsubmit="return confirm('Start a crawl of up to {{ number_format($maxPages) }} pages? It costs at most ${{ number_format($crawlCost, 2) }}.')">
            @csrf
            <button type="submit" class="btn-primary text-sm" @disabled(! $configured || $running)>
                <i class="fa-solid fa-spider"></i> {{ $running ? 'Crawl in progress' : 'Crawl now (up to $'.number_format($crawlCost, 2).')' }}
            </button>
        </form>
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

@php
    $pill = ['error' => 'status-red', 'warning' => 'status-yellow', 'notice' => 'status-unknown'];
@endphp

@unless ($configured)
    <div class="mb-6 p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-sm" role="alert">
        Crawls need a DataForSEO API login. An admin adds it under SEO on Settings → Integrations.
    </div>
@endunless

@if ($running)
    <div class="mb-6 p-4 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border-light)] text-sm" data-crawl-running>
        <i class="fa-solid fa-spinner fa-spin mr-2"></i>
        A crawl started {{ $running->started_at?->diffForHumans() }} is running{{ $running->pages_crawled ? ' ('.number_format($running->pages_crawled).' pages so far)' : '' }}.
        Control checks on it every hour and shows the result here when it finishes.
    </div>
@endif

@if ($audit === null)
    <section class="card">
        <p class="px-5 py-10 text-sm text-[var(--color-ink-muted)] text-center">
            This site has not been crawled yet.
            @if ($frequency === 'off') Scheduled crawls are off; start one with the button above.
            @else Crawls run {{ $frequency }}; the first starts within the hour, or start one now with the button above.
            @endif
        </p>
    </section>
@else
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-2">
        <x-stat-box label="Score (0-100)" :value="$audit->score !== null ? number_format($audit->score, 0) : '—'" icon="fa-gauge" />
        <x-stat-box label="Errors" :value="$audit->errors ?? '—'" icon="fa-circle-xmark" />
        <x-stat-box label="Warnings" :value="$audit->warnings ?? '—'" icon="fa-triangle-exclamation" />
        <x-stat-box label="Notices" :value="$audit->notices ?? '—'" icon="fa-circle-info" />
        <x-stat-box label="Pages crawled" :value="$audit->pages_crawled !== null ? number_format($audit->pages_crawled) : '—'" icon="fa-file-lines" />
    </div>
    <p class="text-xs text-[var(--color-ink-soft)] mb-6">
        Crawled {{ $audit->finished_at?->toDateString() }}, up to {{ number_format($audit->max_pages) }} pages{{ $audit->javascript ? ', with JavaScript' : ', without running JavaScript' }}.
        Errors, warnings and notices count kinds of problem, not pages. The score is DataForSEO's own formula and is not comparable with another tool's site health.
        @if ($audit->pages_crawled !== null && $audit->pages_crawled >= $audit->max_pages)
            <strong class="text-[var(--color-status-yellow)]">The crawl stopped at the page cap, so part of the site was not checked.</strong>
        @endif
    </p>

    @if ($comparison)
        <div class="mb-6 p-4 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border-light)] text-sm" data-audit-comparison>
            <span class="font-semibold text-[var(--color-ink-strong)]">Since the crawl on {{ $previous->finished_at?->toDateString() }}:</span>
            <span class="text-[var(--color-status-green)]">{{ number_format($comparison['totals']['fixed']) }} fixed</span> ·
            <span class="text-[var(--color-status-red)]">{{ number_format($comparison['totals']['new']) }} new</span> ·
            {{ number_format($comparison['totals']['still_open']) }} still open
            <span class="text-xs text-[var(--color-ink-soft)]">(page-and-problem pairs)</span>.
            @if ($comparison['note'])<span class="block text-xs text-[var(--color-status-yellow)] mt-1">{{ $comparison['note'] }}</span>@endif
            @if ($resolved->isNotEmpty())
                <span class="block text-xs text-[var(--color-ink-muted)] mt-1">Cleared completely: {{ $resolved->pluck('title')->implode('; ') }}.</span>
            @endif
        </div>
    @endif

    <section class="card mb-6">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]"><i class="fa-solid fa-list-check text-[var(--color-ink-soft)] mr-2"></i>Issues</h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Worst first. Choose an issue to see the pages and what to do.</p>
        </div>
        @if ($issues === [])
            <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">No issues found in this crawl.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                        <tr>
                            <th class="px-5 py-2 font-semibold">Issue</th>
                            <th class="px-5 py-2 font-semibold">Severity</th>
                            <th class="px-5 py-2 font-semibold">Area</th>
                            <th class="px-5 py-2 font-semibold text-right">Pages</th>
                            @if ($comparison)
                                <th class="px-5 py-2 font-semibold text-right">New</th>
                                <th class="px-5 py-2 font-semibold text-right">Fixed</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($issues as $issue)
                            @php $change = $changes->get($issue['key']); @endphp
                            <tr data-issue-row="{{ $issue['key'] }}" class="{{ $selected && $selected['key'] === $issue['key'] ? 'bg-[var(--color-surface)]' : '' }}">
                                <td class="px-5 py-2">
                                    <a href="{{ route('seo.sites.site-audit', [$site, 'crawl' => $audit->id, 'issue' => $issue['key']]) }}#issue" class="text-[var(--color-primary-600)] hover:underline">{{ $issue['title'] }}</a>
                                </td>
                                <td class="px-5 py-2"><span class="status-pill {{ $pill[$issue['severity']] }} text-[10px]">{{ $severities[$issue['severity']] }}</span></td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $categories[$issue['category']] ?? $issue['category'] }}</td>
                                <td class="px-5 py-2 text-right font-data">{{ $issue['scope'] === 'site' && $issue['pages'] === 0 ? 'Whole site' : number_format($issue['pages']) }}</td>
                                @if ($comparison)
                                    <td class="px-5 py-2 text-right font-data {{ ($change['new'] ?? 0) > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-soft)]' }}">{{ ($change['new'] ?? 0) > 0 ? '+'.number_format($change['new']) : '—' }}</td>
                                    <td class="px-5 py-2 text-right font-data {{ ($change['fixed'] ?? 0) > 0 ? 'text-[var(--color-status-green)]' : 'text-[var(--color-ink-soft)]' }}">{{ ($change['fixed'] ?? 0) > 0 ? number_format($change['fixed']) : '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($selected)
        <x-card :title="$selected['title']" icon="fa-wrench" class="mb-6" id="issue">
            <p class="text-sm mb-3"><span class="status-pill {{ $pill[$selected['severity']] }} text-[10px]">{{ $severities[$selected['severity']] }}</span>
                <span class="ml-2 text-[var(--color-ink-strong)]">{{ $selected['fix'] }}</span></p>
            @if ($selected['scope'] === 'site')
                <p class="text-xs text-[var(--color-ink-muted)]">This applies to the site as a whole{{ $selected['pages'] > 0 ? ' ('.number_format($selected['pages']).' URLs counted by the crawler; it does not list them)' : '' }}.</p>
            @else
                <p class="text-xs text-[var(--color-ink-soft)] mb-2">{{ number_format(count($selectedPages)) }} {{ Str::plural('page', count($selectedPages)) }}{{ count($selectedPages) >= 500 ? ' (first 500)' : '' }}:</p>
                <ul class="text-xs font-data space-y-1 max-h-96 overflow-y-auto" data-issue-pages>
                    @foreach ($selectedPages as $page)
                        <li class="break-all">{{ $page['url'] }}@if ($page['status_code'] && $page['status_code'] !== 200) <span class="text-[var(--color-status-red)]">{{ $page['status_code'] }}</span>@endif</li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endif
@endif

@if ($history->isNotEmpty())
    <section class="card">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]"><i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-soft)] mr-2"></i>Crawls</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr><th class="px-5 py-2 font-semibold">Started</th><th class="px-5 py-2 font-semibold">Result</th><th class="px-5 py-2 font-semibold text-right">Pages</th><th class="px-5 py-2 font-semibold text-right">Score</th><th class="px-5 py-2 font-semibold text-right">Errors</th><th class="px-5 py-2 font-semibold text-right">Warnings</th><th class="px-5 py-2 font-semibold text-right">Cost</th></tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($history as $h)
                        <tr data-crawl-row="{{ $h->id }}">
                            <td class="px-5 py-2 text-xs">
                                @if ($h->status === 'finished')
                                    <a href="{{ route('seo.sites.site-audit', [$site, 'crawl' => $h->id]) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $h->started_at?->toDateString() }}</a>
                                @else
                                    {{ $h->started_at?->toDateString() }}
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($h->status === 'finished') Finished
                                @elseif ($h->status === 'crawling') Crawling
                                @else <span class="text-[var(--color-status-red)]" title="{{ $h->error }}">Failed: {{ Str::limit((string) $h->error, 80) }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-right font-data">{{ $h->pages_crawled !== null ? number_format($h->pages_crawled) : '—' }}</td>
                            <td class="px-5 py-2 text-right font-data">{{ $h->score !== null ? number_format($h->score, 0) : '—' }}</td>
                            <td class="px-5 py-2 text-right font-data">{{ $h->errors ?? '—' }}</td>
                            <td class="px-5 py-2 text-right font-data">{{ $h->warnings ?? '—' }}</td>
                            <td class="px-5 py-2 text-right font-data">${{ number_format($h->cost_usd, 4) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection
