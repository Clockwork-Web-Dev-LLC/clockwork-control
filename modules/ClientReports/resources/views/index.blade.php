@extends('layouts.app')

@section('title', 'Reports · Client Reports · Clockwork')

@php
    $stateClass = \Modules\ClientReports\Support\ReportState::class;
    $categoryClass = \Modules\ClientReports\Support\ReviewReasonCategories::class;
    $filtersClass = \Modules\ClientReports\Support\ReportListFilters::class;
    $context = $filters->toQuery();
    $periodLabel = $filters->period === $filtersClass::ALL || $filters->period === null
        ? 'all periods'
        : \Illuminate\Support\Carbon::createFromFormat('!Y-m', $filters->period)->format('F Y');
    // Summary tiles: each one a status filter for the selected period.
    $tiles = [
        [$filtersClass::WAITING, 'Waiting', array_sum(array_map(fn ($s) => $summary[$s] ?? 0, $stateClass::WAITING)), 'Anything not sent yet'],
        [$stateClass::NEEDS_REVIEW, $stateClass::label($stateClass::NEEDS_REVIEW), $summary[$stateClass::NEEDS_REVIEW] ?? 0, 'Held: something to check or fix'],
        [$stateClass::OUTDATED, $stateClass::label($stateClass::OUTDATED), $summary[$stateClass::OUTDATED] ?? 0, 'Recompile before sending'],
        [$stateClass::READY, $stateClass::label($stateClass::READY), $summary[$stateClass::READY] ?? 0, 'Nothing to fix, waiting to be sent'],
        [$stateClass::SENT, $stateClass::label($stateClass::SENT), ($summary[$stateClass::SENT] ?? 0) + ($summary[$stateClass::PARTLY_SENT] ?? 0), ($summary[$stateClass::PARTLY_SENT] ?? 0) > 0 ? ($summary[$stateClass::PARTLY_SENT]).' only partly' : 'With every recipient'],
        [$filtersClass::ALL, 'All', $summary['total'] ?? 0, 'Every report this period'],
    ];
    $activeStatus = $filters->status ?? $filtersClass::ALL;
@endphp

@section('content')
<x-page-header title="Client Reports"
    subtitle="Check what's held, fix and recompile, then send. Filters stay in the address, so they survive opening a report and coming back.">
    <x-slot:actions>
        <a href="#generate-panel" onclick="var d = document.getElementById('generate-panel'); d.open = true; d.scrollIntoView({behavior: 'smooth'}); return false;" class="btn-pill-nav text-xs py-1.5 px-3">
            <i class="fa-solid fa-plus text-[10px]"></i> One-off report
        </a>
    </x-slot:actions>
</x-page-header>

@include('client-reports::_nav')
@include('client-reports::partials.flash')

{{-- Summary of the selected period: counts that are also filter links. --}}
<div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 mb-4">
    @foreach ($tiles as [$tileStatus, $tileLabel, $tileCount, $tileHint])
        <a href="{{ route('client-reports.reports', array_filter(['period' => $filters->period, 'status' => $tileStatus === $filtersClass::ALL ? null : $tileStatus, 'client' => $filters->client, 'from' => $filters->from])) }}"
           class="cr-filter-link {{ $activeStatus === $tileStatus ? 'is-active' : '' }}">
            <div class="flex items-center justify-between gap-2">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">{{ $tileLabel }}</span>
                @if ($stateClass::isKnown($tileStatus))
                    <i class="fa-solid {{ $stateClass::icon($tileStatus) }} text-[10px] text-[var(--color-ink-soft)]"></i>
                @endif
            </div>
            <div class="font-display text-2xl font-bold text-[var(--color-ink-strong)]">{{ number_format($tileCount) }}</div>
            <div class="text-[11px] text-[var(--color-ink-soft)] truncate">{{ $tileHint }}</div>
        </a>
    @endforeach
</div>

<div class="card overflow-hidden">
    {{-- Filters (GET, so they live in the query string) --}}
    <form method="GET" action="{{ route('client-reports.reports') }}" class="p-4 sm:p-5 border-b border-[var(--color-border-light)] flex flex-wrap items-end gap-2.5">
        @if ($filters->client)
            <input type="hidden" name="client" value="{{ $filters->client }}">
            @if ($filters->from)<input type="hidden" name="from" value="{{ $filters->from }}">@endif
        @endif
        <div class="relative grow sm:grow-0">
            <label for="cr-reports-q" class="sr-only">Search</label>
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[10px] text-[var(--color-ink-soft)]"></i>
            <input id="cr-reports-q" type="search" name="q" value="{{ $filters->q }}" placeholder="Site, client or recipient…" class="cr-input pl-7 w-full sm:w-60">
        </div>
        <div>
            <label for="cr-reports-period" class="sr-only">Period</label>
            <select id="cr-reports-period" name="period" class="cr-input">
                @foreach ($periods as $ym => $count)
                    <option value="{{ $ym }}" @selected($filters->period === $ym)>{{ \Illuminate\Support\Carbon::createFromFormat('!Y-m', $ym)->format('F Y') }} ({{ $count }})</option>
                @endforeach
                <option value="{{ $filtersClass::ALL }}" @selected($filters->period === $filtersClass::ALL)>All periods</option>
            </select>
        </div>
        <div>
            <label for="cr-reports-status" class="sr-only">Status</label>
            <select id="cr-reports-status" name="status" class="cr-input">
                <option value="">Any status</option>
                <option value="{{ $filtersClass::WAITING }}" @selected($filters->status === $filtersClass::WAITING)>Waiting (anything unsent)</option>
                @foreach ($stateClass::options() as $value => $label)
                    <option value="{{ $value }}" @selected($filters->status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="cr-reports-category" class="sr-only">Hold reason</label>
            <select id="cr-reports-category" name="category" class="cr-input">
                <option value="">Any hold reason</option>
                @foreach ($categoryClass::filterOptions() as $value => $label)
                    <option value="{{ $value }}" @selected($filters->category === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <label class="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-strong)] cursor-pointer pb-1.5">
            <input type="checkbox" name="superseded" value="1" @checked($filters->showSuperseded)> Show replaced copies
        </label>
        <div class="flex items-center gap-2">
            <button type="submit" class="cr-btn cr-btn-primary"><i class="fa-solid fa-filter"></i>Filter</button>
            @if ($filters->isFiltered() || $filters->showSuperseded)
                <a href="{{ route('client-reports.reports', ['period' => $filters->period]) }}" class="cr-btn">Clear</a>
            @endif
        </div>
    </form>

    {{-- Bulk actions for the period: recompile (never sends), or approve and send. --}}
    <form id="cr-bulk" method="POST" action="{{ route('client-reports.recompile-many') }}"
          class="px-4 sm:px-5 py-3 border-b border-[var(--color-border-light)] flex flex-wrap items-center justify-between gap-3 bg-[var(--color-surface-alt)]/40">
        @csrf
        @foreach ($context as $contextKey => $contextValue)
            <input type="hidden" name="{{ $contextKey }}" value="{{ $contextValue }}">
        @endforeach
        <div class="text-xs text-[var(--color-ink-muted)]">
            <strong class="text-[var(--color-ink-strong)]">{{ $reports->total() }}</strong> {{ \Illuminate\Support\Str::plural('report', $reports->total()) }}
            · {{ $periodLabel }}
            @if ($filters->client)
                · <a href="{{ route('client-reports.clients.show', $filters->client) }}" class="underline">one client</a>
                <a href="{{ route('client-reports.reports', $filters->with(['client' => null, 'from' => null])) }}" class="cr-chip ml-1" title="Show every client">× client</a>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" name="scope" value="selected" class="cr-btn"
                    data-confirm="Recompile the ticked reports from current data?" data-confirm-details="Each unsent scheduled report is compiled again for its own period and checked for review; its old copy is replaced. Nothing is sent." data-confirm-btn="Recompile" data-confirm-variant="primary">
                <i class="fa-solid fa-rotate"></i>Recompile selected
            </button>
            @if ($outdatedCount > 0)
                <button type="submit" name="scope" value="outdated" class="cr-btn cr-btn-primary"
                        data-confirm="Recompile all {{ $outdatedCount }} outdated reports for {{ $periodLabel }}?" data-confirm-details="Each is compiled again from current data under the latest report rules and checked for review. Nothing is sent: check and send them afterwards." data-confirm-btn="Recompile {{ $outdatedCount }}" data-confirm-variant="primary">
                    <i class="fa-solid fa-rotate"></i>Recompile all outdated ({{ $outdatedCount }})
                </button>
            @endif
            <span class="hidden sm:inline-block w-px h-5 bg-[var(--color-border-light)] mx-1" aria-hidden="true"></span>
            <button type="submit" name="scope" value="selected" formaction="{{ route('client-reports.send-many') }}" class="cr-btn"
                    data-confirm="Send the ticked reports to their clients now?" data-confirm-details="Each goes to everyone on it who doesn't have it yet. A ticked report that is held for review is sent too, so check those first. Outdated, replaced and already-sent reports are skipped. Up to {{ \Modules\ClientReports\Http\Controllers\ClientReportsController::SEND_BATCH }} per press." data-confirm-btn="Send" data-confirm-variant="warning">
                <i class="fa-solid fa-paper-plane"></i>Send selected
            </button>
            @if ($readyCount > 0)
                <button type="submit" name="scope" value="ready" formaction="{{ route('client-reports.send-many') }}" class="cr-btn cr-btn-primary"
                        data-confirm="Approve and send all {{ $readyCount }} ready {{ \Illuminate\Support\Str::plural('report', $readyCount) }} for {{ $periodLabel }}?" data-confirm-details="These have nothing to fix. Each one is emailed to its recipients now. Reports that need review, are outdated or are already sent are left alone. Up to {{ \Modules\ClientReports\Http\Controllers\ClientReportsController::SEND_BATCH }} per press." data-confirm-btn="Send {{ $readyCount }}" data-confirm-variant="warning">
                    <i class="fa-solid fa-circle-check"></i>Approve &amp; send all ready ({{ $readyCount }})
                </button>
            @endif
        </div>
    </form>

    @if ($reports->isEmpty())
        <div class="text-center py-14 px-6 text-sm text-[var(--color-ink-soft)]">
            <i class="fa-solid fa-file-circle-check text-4xl mb-3 block opacity-30"></i>
            @if ($filters->isFiltered())
                No reports match these filters. <a href="{{ route('client-reports.reports', ['period' => $filters->period]) }}" class="text-[var(--color-brand)] hover:underline">Clear filters</a>
            @else
                No reports for {{ $periodLabel }} yet.
            @endif
        </div>
    @else
        <div class="overflow-x-auto cw-sticky-actions">
            <table class="w-full text-xs text-left">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">
                    <tr>
                        <th class="pl-4 pr-1 py-3 w-6">
                            <input type="checkbox" aria-label="Select every unsent report on this page"
                                   onclick="document.querySelectorAll('input[form=cr-bulk][name=&quot;ids[]&quot;]').forEach(c => { if (! c.disabled) c.checked = this.checked; })">
                        </th>
                        <th class="px-3 py-3">Site &amp; client</th>
                        <th class="px-3 py-3">Period</th>
                        <th class="px-3 py-3">Status &amp; why</th>
                        <th class="px-3 py-3">Recipients</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($reports as $r)
                        @php
                            $state = $stateClass::of($r);
                        @endphp
                        <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors {{ $state === $stateClass::SUPERSEDED ? 'opacity-60' : '' }}">
                            <td class="pl-4 pr-1 py-3 align-top">
                                @php
                                    $selectable = $r->canRecompile() || in_array($state, [$stateClass::READY, $stateClass::NEEDS_REVIEW, $stateClass::PARTLY_SENT], true);
                                @endphp
                                <input type="checkbox" form="cr-bulk" name="ids[]" value="{{ $r->id }}" @disabled(! $selectable)
                                       aria-label="Select {{ $r->site?->domain }} {{ $r->period_start->format('M Y') }}"
                                       title="{{ $selectable ? 'Select to recompile or send' : 'Already sent or replaced' }}">
                            </td>
                            <td class="px-3 py-3 align-top min-w-[12rem]">
                                <a href="{{ route('client-reports.show', [$r, ...$context]) }}" class="font-mono text-xs font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] break-all">{{ $r->site?->domain ?? $r->client_name ?? $r->title }}</a>
                                <div class="text-[11px] text-[var(--color-ink-muted)]">
                                    @if ($r->site?->client)
                                        <a href="{{ route('client-reports.clients.show', $r->site->client_id) }}" class="hover:underline">{{ $r->site->client->company_name ?: $r->site->client->name }}</a>
                                    @elseif ($r->site)
                                        <a href="{{ route('client-reports.clients.show', $filtersClass::UNASSIGNED) }}" class="hover:underline">No client</a>
                                    @endif
                                    @if ($r->schedule_id === null)
                                        <span class="cr-chip ml-1" title="Generated by hand, not from a schedule">One-off</span>
                                    @endif
                                </div>
                                <div class="sr-only">{{ $r->title }}</div>
                            </td>
                            <td class="px-3 py-3 align-top whitespace-nowrap text-[var(--color-ink-soft)]">
                                <div class="font-semibold text-[var(--color-ink-strong)]">{{ $r->period_start->format('M Y') }}</div>
                                <div class="text-[10px]">{{ $r->period_start->format('M j') }} – {{ $r->period_end->format('M j') }}</div>
                            </td>
                            <td class="px-3 py-3 align-top min-w-[12rem]">
                                @include('client-reports::partials.state-pill', ['state' => $state])
                                @include('client-reports::partials.reason-chips', ['reasons' => $stateClass::reasons($r)])
                            </td>
                            <td class="px-3 py-3 align-top text-[var(--color-ink-soft)] min-w-[10rem] max-w-[13rem]">
                                @if ($r->recipients() !== [])
                                    <div class="break-words text-[11px]" title="{{ $r->client_email }}">{{ implode(', ', $r->recipients()) }}</div>
                                @else
                                    <span class="text-[var(--color-ink-muted)] italic">No recipient</span>
                                @endif
                                @if (! empty($r->sent_to) && $state !== $stateClass::SENT)
                                    <div class="text-[10px] text-[var(--color-ink-muted)]">Has it: {{ implode(', ', $r->sent_to) }}</div>
                                @elseif ($r->sent_at)
                                    <div class="text-[10px] text-[var(--color-ink-muted)]">Sent {{ $r->sent_at->format('M j, Y') }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top">
                                @include('client-reports::partials.report-actions', ['report' => $r, 'context' => $context])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($reports->hasPages())
            <div class="p-4 border-t border-[var(--color-border-light)]">{{ $reports->links() }}</div>
        @endif
    @endif
</div>

{{-- One-off report, outside any schedule --}}
<details class="card mt-6 scroll-mt-24" id="generate-panel">
    <summary class="p-5 cursor-pointer font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
        <i class="fa-solid fa-wand-magic-sparkles text-[var(--color-brand)]"></i>
        Generate a one-off report
        <span class="text-xs font-normal text-[var(--color-ink-muted)]">— for any site and dates, outside its schedule</span>
    </summary>
    <form method="POST" action="{{ route('client-reports.generate') }}" class="px-5 pb-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        @csrf
        <div>
            <label for="cr-gen-site" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Site</label>
            <select id="cr-gen-site" name="site_id" required class="cr-input w-full">
                <option value="" disabled selected>Select a website…</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}">{{ $s->domain }}</option>
                @endforeach
            </select>
        </div>

        @if (!empty($templates) && $templates->isNotEmpty())
            <div>
                <label for="cr-gen-template" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Template</label>
                <select id="cr-gen-template" name="template_id" class="cr-input w-full">
                    @foreach ($templates as $t)
                        <option value="{{ $t->id }}" {{ $t->is_default ? 'selected' : '' }}>
                            {{ $t->name }} ({{ count((array) $t->sections) }} sections){{ $t->is_default ? ' — Default' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 min-w-0">
            <div class="min-w-0">
                <label for="cr-gen-from" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">From</label>
                <input id="cr-gen-from" type="date" name="period_start" value="{{ now()->subMonthNoOverflow()->startOfMonth()->toDateString() }}" required class="cr-input w-full">
            </div>
            <div class="min-w-0">
                <label for="cr-gen-to" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">To</label>
                <input id="cr-gen-to" type="date" name="period_end" value="{{ now()->subMonthNoOverflow()->endOfMonth()->toDateString() }}" required class="cr-input w-full">
            </div>
        </div>

        <div>
            <label for="cr-gen-name" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Client name (optional)</label>
            <input id="cr-gen-name" type="text" name="client_name" placeholder="Example Co" class="cr-input w-full">
        </div>

        <div>
            <label for="cr-gen-email" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Client email (optional)</label>
            <input id="cr-gen-email" type="email" name="client_email" placeholder="client@example.com" class="cr-input w-full">
        </div>

        <div class="md:col-span-2 xl:col-span-2">
            <label for="cr-gen-notes" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Custom notes</label>
            <textarea id="cr-gen-notes" name="custom_notes" rows="2" placeholder="Everything is running smoothly! Next month we will…" class="cr-input w-full"></textarea>
        </div>

        <div class="flex items-end">
            <button type="submit" class="btn-primary text-xs py-2 px-4 w-full justify-center">
                <i class="fa-solid fa-file-export"></i> Generate &amp; preview
            </button>
        </div>
    </form>
</details>
@endsection
