@extends('layouts.app')

@section('title', $row['name'].' · Client Reports · Clockwork')

@php
    $carePlansOn = \App\Models\Site::areCarePlansEnabled();
    $stateClass = \Modules\ClientReports\Support\ReportState::class;
    $modeDraft = \Modules\ClientReports\Models\ClientReportSchedule::MODE_DRAFT;
    $listUrl = route('client-reports.index', $filters);
    $reportContext = array_filter(['period' => 'all', 'client' => $row['key'], 'from' => 'client', 'superseded' => $showSuperseded ? '1' : null]);
    // The switcher's labels must be unique to map back to a client.
    $switchOptions = [];
    foreach ($switcher as $option) {
        $label = $option['name'];
        $n = 2;
        while (isset($switchOptions[$label])) {
            $label = $option['name'].' ('.$n++.')';
        }
        $switchOptions[$label] = route('client-reports.clients.show', [$option['key'], ...$filters]);
    }
    $currentLabel = array_search(route('client-reports.clients.show', [$row['key'], ...$filters]), $switchOptions, true) ?: $row['name'];
@endphp

@section('content')
@include('client-reports::_nav')
@include('client-reports::partials.flash')

{{-- Client navigation: back to the (filtered) list, previous/next, switcher --}}
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <a href="{{ $listUrl }}" class="cr-btn"><i class="fa-solid fa-arrow-left"></i>All clients</a>
    <div class="flex flex-wrap items-center gap-2">
        @if ($previousKey !== null)
            <a href="{{ route('client-reports.clients.show', [$previousKey, ...$filters]) }}" class="cr-btn" rel="prev" title="Previous client (←)"><i class="fa-solid fa-chevron-left"></i>Previous</a>
        @else
            <span class="cr-btn is-disabled"><i class="fa-solid fa-chevron-left"></i>Previous</span>
        @endif
        @if ($position !== null)
            <span class="text-[11px] text-[var(--color-ink-muted)] whitespace-nowrap">{{ $position }} of {{ $listCount }}</span>
        @endif
        @if ($nextKey !== null)
            <a href="{{ route('client-reports.clients.show', [$nextKey, ...$filters]) }}" class="cr-btn" rel="next" title="Next client (→)">Next<i class="fa-solid fa-chevron-right"></i></a>
        @else
            <span class="cr-btn is-disabled">Next<i class="fa-solid fa-chevron-right"></i></span>
        @endif
        <form class="relative" onsubmit="return false;" role="search">
            <label for="cr-client-switch" class="sr-only">Go to client</label>
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[10px] text-[var(--color-ink-soft)]"></i>
            <input id="cr-client-switch" list="cr-client-switch-options" placeholder="Go to client…" autocomplete="off"
                   class="cr-input pl-7 w-52" data-urls='@json($switchOptions)'>
            <datalist id="cr-client-switch-options">
                @foreach ($switchOptions as $label => $url)
                    <option value="{{ $label }}"></option>
                @endforeach
            </datalist>
        </form>
    </div>
</div>

{{-- Client header --}}
<div class="card p-5 mb-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="display-heading text-2xl sm:text-3xl text-[var(--color-ink-strong)] break-words">{{ $row['name'] }}</h1>
            @if ($row['contact'])
                <div class="text-sm text-[var(--color-ink-muted)] mt-0.5">{{ $row['contact'] }}</div>
            @endif
            @if ($row['client'] === null)
                <p class="text-xs text-[var(--color-ink-muted)] mt-1 max-w-xl">Sites with no client. Reports still go out for those on a care plan with a schedule; assign a client to keep contacts and history together.</p>
            @endif
            @if ($row['contacts'] !== [])
                <div class="mt-2 flex flex-wrap gap-1.5 text-xs">
                    @foreach ($row['contacts'] as $contact)
                        <span class="cr-chip"><i class="fa-solid fa-envelope"></i>{{ $contact }}</span>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="flex flex-wrap gap-2 items-center">
            <span class="cr-pill {{ $row['counts']['care'] > 0 ? 'status-green' : 'status-unknown' }}">
                <i class="fa-solid fa-shield-heart"></i>
                @if (! $carePlansOn)
                    Care plans off: every site gets reports
                @else
                    {{ $row['counts']['care'] }} of {{ $row['counts']['sites'] }} {{ \Illuminate\Support\Str::plural('site', $row['counts']['sites']) }} on a care plan
                @endif
            </span>
            @if ($row['counts']['missing'] > 0)
                <span class="cr-pill status-red"><i class="fa-solid fa-triangle-exclamation"></i>{{ $row['counts']['missing'] }} without a schedule</span>
            @endif
            @if ($row['counts']['waiting'] > 0)
                <span class="cr-pill status-orange"><i class="fa-solid fa-hand"></i>{{ $row['counts']['waiting'] }} waiting</span>
            @endif
            @if ($row['client'] && \Illuminate\Support\Facades\Route::has('clients.show'))
                <a href="{{ route('clients.show', $row['client']) }}" class="cr-btn"><i class="fa-solid fa-address-card"></i>Client record</a>
            @endif
        </div>
    </div>
</div>

@forelse ($row['sites'] as $siteRow)
    @php
        $site = $siteRow['site'];
        $schedule = $siteRow['schedule'];
        $recipients = array_values(array_filter((array) ($schedule?->recipients ?? [])));
        $reports = $siteRow['reports']->filter(fn ($r) => $showSuperseded || $r->status !== \Modules\ClientReports\Models\ClientReport::STATUS_SUPERSEDED)->values();
        $hiddenCopies = $siteRow['reports']->count() - $siteRow['reports']->filter(fn ($r) => $r->status !== \Modules\ClientReports\Models\ClientReport::STATUS_SUPERSEDED)->count();
    @endphp
    <section class="card overflow-hidden mb-6 {{ $site->is_inactive ? 'opacity-75' : '' }}" aria-labelledby="site-{{ $site->id }}">
        <div class="p-4 sm:p-5 border-b border-[var(--color-border-light)] grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] gap-4">
            <div class="min-w-0">
                <h2 id="site-{{ $site->id }}" class="font-mono text-sm font-semibold text-[var(--color-ink-strong)] break-all">
                    <a href="{{ route('sites.show', $site) }}" class="hover:text-[var(--color-brand)]">{{ $site->domain }}</a>
                </h2>
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    @if ($site->is_inactive)
                        <span class="cr-chip">Inactive</span>
                    @endif
                    @if ($carePlansOn)
                        @if ($siteRow['eligible'])
                            <span class="cr-pill status-green"><i class="fa-solid fa-shield-heart"></i>Care plan</span>
                        @else
                            <span class="cr-pill status-unknown"><i class="fa-solid fa-ban"></i>No care plan — reports won't send</span>
                        @endif
                    @endif
                    <a href="{{ route('sites.show', $site) }}" class="cr-btn py-0.5"><i class="fa-solid fa-gauge"></i>Site dashboard</a>
                </div>
                <div class="mt-3">
                    @include('client-reports::partials.history-strip', ['months' => $siteRow['months'], 'context' => $reportContext])
                </div>
            </div>

            {{-- Schedule --}}
            <div class="rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface)] p-3 text-xs min-w-0">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <span class="font-semibold text-[var(--color-ink-strong)] flex items-center gap-1.5"><i class="fa-solid fa-clock text-[var(--color-ink-soft)]"></i>Report schedule</span>
                    @if ($schedule)
                        <div class="flex flex-wrap gap-1.5">
                            <a href="{{ route('client-reports.schedules.edit', $schedule) }}" class="cr-btn"><i class="fa-solid fa-pen"></i>Edit</a>
                            <form method="POST" action="{{ route('client-reports.schedules.toggle', $schedule) }}" class="inline"
                                  data-confirm="{{ $schedule->is_enabled ? 'Disable' : 'Enable' }} the report schedule for {{ $site->domain }}?" data-confirm-variant="{{ $schedule->is_enabled ? 'warning' : 'primary' }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="return" value="back">
                                <button type="submit" class="cr-btn"><i class="fa-solid {{ $schedule->is_enabled ? 'fa-pause' : 'fa-play' }}"></i>{{ $schedule->is_enabled ? 'Disable' : 'Enable' }}</button>
                            </form>
                        </div>
                    @elseif ($siteRow['missing'])
                        <form method="POST" action="{{ route('client-reports.schedules.quick', $site) }}" class="inline">
                            @csrf
                            <button type="submit" class="cr-btn cr-btn-primary"><i class="fa-solid fa-plus"></i>Create schedule</button>
                        </form>
                    @endif
                </div>
                @if ($schedule)
                    <dl class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-2 gap-y-1">
                        <dt class="text-[var(--color-ink-muted)]">Status</dt>
                        <dd>
                            @if ($schedule->is_enabled)
                                <span class="cr-pill status-green">Enabled</span>
                            @else
                                <span class="cr-pill status-red">Disabled</span>
                            @endif
                            <span class="text-[var(--color-ink-muted)]">· {{ ucfirst($schedule->frequency) }}, {{ $schedule->delivery_mode === $modeDraft ? 'drafts for review' : 'sends automatically' }}</span>
                        </dd>
                        <dt class="text-[var(--color-ink-muted)]">Recipients</dt>
                        <dd class="break-all text-[var(--color-ink-strong)]">{{ $recipients !== [] ? implode(', ', $recipients) : 'None (drafts only)' }}</dd>
                        <dt class="text-[var(--color-ink-muted)]">Next run</dt>
                        <dd class="text-[var(--color-ink-strong)]">{{ $schedule->next_run_at ? $schedule->next_run_at->format('M j, Y') : '—' }}</dd>
                        <dt class="text-[var(--color-ink-muted)]">Last sent</dt>
                        <dd class="text-[var(--color-ink-strong)]">
                            @if ($schedule->last_sent_at)
                                {{ $schedule->last_sent_at->format('M j, Y') }}
                            @else
                                Never <span class="text-[var(--color-ink-muted)]">— its first report waits for someone to send it</span>
                            @endif
                        </dd>
                    </dl>
                @elseif ($siteRow['missing'])
                    <p class="text-[var(--color-ink-muted)]">On a care plan but no schedule, so <strong class="text-[var(--color-status-red)]">no report is sent</strong>. Create one with the default template, monthly{{ $row['contacts'] !== [] ? ', to the client\'s contacts' : ', as drafts for review (no contacts on file)' }}.</p>
                @else
                    <p class="text-[var(--color-ink-muted)]">No schedule{{ $carePlansOn && ! $siteRow['eligible'] ? ': reports are only sent for care-plan sites' : '' }}.</p>
                @endif
            </div>
        </div>

        {{-- Full report history --}}
        @if ($reports->isEmpty())
            <div class="px-5 py-6 text-xs text-[var(--color-ink-soft)] italic">No reports yet.</div>
        @else
            <div class="overflow-x-auto cw-sticky-actions">
                <table class="w-full text-xs text-left">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-2.5">Period</th>
                            <th class="px-4 py-2.5">Status</th>
                            <th class="px-4 py-2.5">Sent to</th>
                            <th class="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($reports as $report)
                            @php($state = $stateClass::of($report))
                            <tr class="{{ $state === $stateClass::SUPERSEDED ? 'opacity-60' : '' }}">
                                <td class="px-4 py-2.5 whitespace-nowrap align-top">
                                    <div class="font-semibold text-[var(--color-ink-strong)]">{{ $report->period_start->format('F Y') }}</div>
                                    <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $report->period_start->format('M j') }} – {{ $report->period_end->format('M j') }}</div>
                                </td>
                                <td class="px-4 py-2.5 align-top min-w-[11rem]">
                                    @include('client-reports::partials.state-pill', ['state' => $state])
                                    @include('client-reports::partials.reason-chips', ['reasons' => $stateClass::reasons($report)])
                                </td>
                                <td class="px-4 py-2.5 align-top text-[var(--color-ink-soft)] min-w-[14rem]">
                                    @if (! empty($report->sent_to))
                                        <div class="break-all">{{ implode(', ', $report->sent_to) }}</div>
                                        @if ($report->sent_at)
                                            <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $report->sent_at->format('M j, Y') }}</div>
                                        @endif
                                    @elseif ($report->recipients() !== [])
                                        <div class="break-all text-[var(--color-ink-muted)]">Not sent · for {{ implode(', ', $report->recipients()) }}</div>
                                    @else
                                        <span class="text-[var(--color-ink-muted)]">Not sent · no recipient</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 align-top">
                                    @include('client-reports::partials.report-actions', ['report' => $report, 'context' => $reportContext])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($hiddenCopies > 0 || $showSuperseded)
            <div class="px-5 py-2 border-t border-[var(--color-border-light)] text-[11px]">
                <a href="{{ route('client-reports.clients.show', [$row['key'], ...$filters, ...($showSuperseded ? [] : ['superseded' => 1])]) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                    {{ $showSuperseded ? 'Hide replaced copies' : 'Show '.$hiddenCopies.' replaced '.\Illuminate\Support\Str::plural('copy', $hiddenCopies) }}
                </a>
            </div>
        @endif
    </section>
@empty
    <div class="card p-8 text-center text-sm text-[var(--color-ink-soft)]">
        No sites are assigned to this client yet.
        @if ($row['client'] && \Illuminate\Support\Facades\Route::has('clients.show'))
            <a href="{{ route('clients.show', $row['client']) }}" class="text-[var(--color-brand)] hover:underline">Assign sites on the client record.</a>
        @endif
    </div>
@endforelse

<p class="text-[11px] text-[var(--color-ink-soft)] mt-2">Keyboard: <kbd class="cr-chip">←</kbd> previous client · <kbd class="cr-chip">→</kbd> next client</p>

<script>
    (function () {
        var input = document.getElementById('cr-client-switch');
        if (input) {
            var urls = JSON.parse(input.dataset.urls || '{}');
            var go = function () { if (urls[input.value]) { window.location.href = urls[input.value]; } };
            input.addEventListener('change', go);
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
        }
        document.addEventListener('keydown', function (e) {
            if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey) { return; }
            var tag = (e.target && e.target.tagName) || '';
            if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (e.target && e.target.isContentEditable)) { return; }
            var link = e.key === 'ArrowLeft' ? document.querySelector('a[rel=prev]') : (e.key === 'ArrowRight' ? document.querySelector('a[rel=next]') : null);
            if (link) { window.location.href = link.href; }
        });
    })();
</script>
@endsection
