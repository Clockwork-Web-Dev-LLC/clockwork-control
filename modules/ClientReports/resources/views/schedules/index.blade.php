@extends('layouts.app')

@section('title', 'Scheduled Reports · Clockwork')

@section('content')
<x-page-header title="Client Reports"
    subtitle="Who gets a monthly report, where it goes, and which care-plan sites still have none.">
    <x-slot:actions>
        <a href="{{ route('client-reports.schedules.create') }}" class="btn-primary text-xs py-2 px-4">
            <i class="fa-solid fa-plus text-[11px]"></i> New schedule
        </a>
    </x-slot:actions>
</x-page-header>

@include('client-reports::_nav')
@include('client-reports::partials.flash')

@php
    $stateClass = \Modules\ClientReports\Support\ReportState::class;
    $runnerClass = \Modules\ClientReports\Services\ScheduledReportRunner::class;
    $modeDraft = \Modules\ClientReports\Models\ClientReportSchedule::MODE_DRAFT;
    $filterLabels = \Modules\ClientReports\Http\Controllers\SchedulesController::FILTERS;
@endphp

{{-- The coverage gap: care-plan sites that get no report at all. --}}
@if ($missingSites->isNotEmpty())
    <div class="card p-5 mb-6 card-status-red">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
            <div>
                <h2 class="font-display font-semibold text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)]"></i>
                    Care-plan sites without a report schedule ({{ $missingSites->count() }})
                </h2>
                <p class="text-xs text-[var(--color-ink-muted)] mt-0.5 max-w-2xl">
                    These sites are on a care plan but get no monthly report. "Create schedule" sets one up with the default template, monthly,
                    to the client's contacts (or as drafts for review when there are none). Nothing is sent: each first report waits for you to check and send it.
                </p>
            </div>
            <form method="POST" action="{{ route('client-reports.schedules.quick-all') }}"
                  data-confirm="Create a monthly report schedule for all {{ $missingSites->count() }} sites?"
                  data-confirm-details="Each gets the default template, monthly, sent to the client's contacts (drafts for review where there are none). Nothing is sent now: every first report waits for review."
                  data-confirm-btn="Create {{ $missingSites->count() }} schedules" data-confirm-variant="primary">
                @csrf
                <button type="submit" class="cr-btn cr-btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i>Create all {{ $missingSites->count() }}</button>
            </form>
        </div>
        <div x-data="{ all: false }">
        <ul class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
            @foreach ($missingSites as $missing)
                <li class="rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface)] p-3 flex items-center justify-between gap-3"
                    @if ($loop->index >= 9) x-show="all" x-cloak @endif>
                    <div class="min-w-0">
                        <a href="{{ route('sites.show', $missing['site']) }}" class="font-mono text-xs font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] break-all">{{ $missing['site']->domain }}</a>
                        <div class="text-[11px] text-[var(--color-ink-muted)] truncate">
                            @if ($missing['site']->client)
                                <a href="{{ route('client-reports.clients.show', $missing['site']->client_id) }}" class="hover:underline">{{ $missing['site']->client->company_name ?: $missing['site']->client->name }}</a> ·
                            @endif
                            {{ $missing['recipients'] !== [] ? 'to '.implode(', ', $missing['recipients']) : 'no contacts: drafts for review' }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('client-reports.schedules.quick', $missing['site']) }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="cr-btn"><i class="fa-solid fa-plus"></i>Create schedule</button>
                    </form>
                </li>
            @endforeach
        </ul>
        @if ($missingSites->count() > 9)
            <button type="button" class="cr-btn mt-3" @click="all = ! all">
                <i class="fa-solid" :class="all ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                <span x-text="all ? 'Show fewer' : 'Show all {{ $missingSites->count() }}'">Show all {{ $missingSites->count() }}</span>
            </button>
        @endif
        </div>
    </div>
@endif

<div class="card overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-[var(--color-border-light)] space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-display font-semibold text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-clock text-[var(--color-ink-soft)]"></i>
                Report schedules
            </h2>
            <form method="GET" action="{{ route('client-reports.schedules.index') }}" class="flex items-center gap-2">
                <input type="hidden" name="show" value="{{ $show }}">
                <div class="relative">
                    <label for="cr-schedule-q" class="sr-only">Search</label>
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[10px] text-[var(--color-ink-soft)]"></i>
                    <input id="cr-schedule-q" type="search" name="q" value="{{ $q }}" placeholder="Site, client or recipient…" class="cr-input pl-7 w-56">
                </div>
                <button type="submit" class="cr-btn cr-btn-primary">Search</button>
            </form>
        </div>
        <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="Filter schedules">
            @foreach ($filterLabels as $key => $label)
                <a href="{{ route('client-reports.schedules.index', array_filter(['show' => $key === 'all' ? null : $key, 'q' => $q])) }}"
                   class="btn-pill-nav text-xs py-1 px-3 {{ $show === $key ? 'is-active' : '' }}" @if ($show === $key) aria-current="true" @endif>
                    {{ $label }} <span class="opacity-70">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </div>
    </div>

    <div class="overflow-x-auto cw-sticky-actions">
        <table class="w-full text-xs text-left">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">
                <tr>
                    <th class="px-4 py-3">Site</th>
                    <th class="px-4 py-3">Delivery</th>
                    <th class="px-4 py-3">Recipients</th>
                    <th class="px-4 py-3">Next run</th>
                    <th class="px-4 py-3">Latest report</th>
                    <th class="px-4 py-3">State</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @forelse ($schedules as $s)
                    @php
                        $recips = array_values(array_filter((array) $s->recipients));
                        $eligible = $runnerClass::siteIsEligible($s->site);
                        $latest = $latestReports->get($s->id);
                    @endphp
                    <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors {{ ! $s->is_enabled ? 'opacity-70' : '' }}">
                        <td class="px-4 py-3 align-top">
                            <a href="{{ route('sites.show', $s->site) }}" class="font-mono text-xs font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] break-all">{{ $s->site->domain }}</a>
                            <div class="mt-1 flex flex-wrap gap-1 items-center">
                                @if (! $eligible)
                                    <span class="cr-pill status-unknown" title="Reports are only sent for care-plan sites. Each month without one is skipped; reports resume the month after it's turned back on.">
                                        <i class="fa-solid fa-ban"></i>No care plan — won't send
                                    </span>
                                @elseif (\App\Models\Site::areCarePlansEnabled())
                                    <span class="cr-pill status-green"><i class="fa-solid fa-shield-heart"></i>Care plan</span>
                                @endif
                                @if ($s->site->client)
                                    <a href="{{ route('client-reports.clients.show', $s->site->client_id) }}" class="text-[11px] text-[var(--color-ink-muted)] hover:underline">{{ $s->site->client->company_name ?: $s->site->client->name }}</a>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap align-top">
                            @if ($s->delivery_mode === $modeDraft)
                                <span class="cr-pill status-yellow"><i class="fa-solid fa-file-pen"></i>Drafts for review</span>
                            @else
                                <span class="cr-pill status-green"><i class="fa-solid fa-paper-plane"></i>Auto-Send</span>
                            @endif
                            <div class="text-[10px] text-[var(--color-ink-muted)] mt-1">
                                {{ ucfirst($s->frequency) }} · {{ $s->template?->name ?? 'Default (all sections)' }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-[var(--color-ink-soft)] align-top">
                            @if ($recips !== [])
                                <div class="max-w-[16rem] break-all text-[11px]">{{ implode(', ', $recips) }}</div>
                            @else
                                <span class="cr-pill status-yellow"><i class="fa-solid fa-at"></i>No recipients</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap align-top">
                            @if ($s->next_run_at)
                                <div class="font-medium text-[var(--color-ink-strong)]">{{ $s->next_run_at->format('M j, Y') }}</div>
                                <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $s->last_sent_at ? 'Last sent '.$s->last_sent_at->format('M j') : 'Never sent yet' }}</div>
                            @else
                                <span class="text-[var(--color-ink-muted)]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top">
                            @if ($latest)
                                @php($latestState = $stateClass::of($latest))
                                <a href="{{ route('client-reports.show', $latest) }}" class="block hover:opacity-80">
                                    <div class="text-[11px] font-semibold text-[var(--color-ink-strong)] mb-0.5">{{ $latest->period_start->format('M Y') }}</div>
                                    @include('client-reports::partials.state-pill', ['state' => $latestState])
                                </a>
                            @else
                                <span class="text-[var(--color-ink-muted)]">None yet</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap align-top">
                            <form method="POST" action="{{ route('client-reports.schedules.toggle', $s) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="cursor-pointer" title="{{ $s->is_enabled ? 'Pause this schedule' : 'Resume this schedule' }}">
                                    @if ($s->is_enabled)
                                        <span class="status-pill status-green text-xs hover:opacity-80"><span class="status-dot"></span> Active</span>
                                    @else
                                        <span class="status-pill status-red text-xs hover:opacity-80"><span class="status-dot"></span> Paused</span>
                                    @endif
                                </button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap align-top">
                            <div class="flex items-center justify-end gap-1.5">
                                <form method="POST" action="{{ route('client-reports.schedules.send-now', $s) }}" class="inline"
                                      data-confirm="Run the schedule for {{ $s->site->domain }} now?"
                                      data-confirm-details="{{ $s->delivery_mode === $modeDraft ? 'It compiles last month\'s report as a draft for review. Nothing is sent.' : ($s->last_sent_at === null ? 'It compiles last month\'s report. As this schedule\'s first report it is held for review, not sent.' : 'It compiles last month\'s report and, if nothing needs review, emails it to '.(implode(', ', $recips) ?: 'its recipients').' straight away.') }}"
                                      data-confirm-btn="Run now" data-confirm-variant="warning">
                                    @csrf
                                    <button type="submit" class="cr-btn" title="Compile last month's report now">
                                        <i class="fa-solid fa-play"></i>Run
                                    </button>
                                </form>
                                <a href="{{ route('client-reports.schedules.edit', $s) }}" class="cr-btn" title="Edit schedule">
                                    <i class="fa-solid fa-pen-to-square"></i>Edit
                                </a>
                                <form method="POST" action="{{ route('client-reports.schedules.destroy', $s) }}" class="inline"
                                      data-confirm="Delete the report schedule for {{ $s->site->domain }}?" data-confirm-details="Its past reports are kept. No more reports are compiled for this site." data-confirm-variant="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="cr-btn text-[var(--color-status-red)]" title="Delete schedule" aria-label="Delete schedule">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-10 text-[var(--color-ink-muted)]">
                            <i class="fa-solid fa-clock text-3xl mb-2 block opacity-30"></i>
                            {{ $show === 'all' && $q === '' ? 'No report schedules yet.' : 'No schedules match.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
