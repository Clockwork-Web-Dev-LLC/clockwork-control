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

{{-- Whether reports wait for a person, for every schedule. --}}
@php
    $approval = $runnerClass::approvalRequired();
@endphp
<div class="card p-4 mb-6 flex flex-wrap items-center justify-between gap-3">
    <div class="text-sm">
        @if ($approval)
            <span class="status-pill cr-pill-blue text-xs mr-1.5"><i class="fa-solid fa-user-check"></i> Approval on</span>
            Every report waits for your approval. The monthly run compiles and checks them, then sends <strong>nothing</strong>: send them from the
            <a href="{{ route('client-reports.reports') }}" class="underline">Reports</a> tab, one by one or with <strong>Approve &amp; send all ready</strong>.
        @else
            <span class="status-pill status-orange text-xs mr-1.5"><i class="fa-solid fa-paper-plane"></i> Approval off</span>
            Schedules set to Automatic email their clean reports by themselves; only flagged ones (and a schedule's first report) wait for you.
        @endif
    </div>
    <form method="POST" action="{{ route('client-reports.schedules.approval') }}"
          data-confirm="{{ $approval ? 'Let clean reports send themselves?' : 'Make every report wait for approval?' }}"
          data-confirm-details="{{ $approval ? 'From the next run, schedules set to Automatic email clean reports to clients with nobody checking them first. Flagged reports are still held.' : 'From the next run, nothing is emailed until someone sends it from the Reports tab.' }}"
          data-confirm-btn="{{ $approval ? 'Turn approval off' : 'Turn approval on' }}" data-confirm-variant="{{ $approval ? 'warning' : 'primary' }}">
        @csrf
        <input type="hidden" name="require_approval" value="{{ $approval ? '0' : '1' }}">
        <button type="submit" class="cr-btn">{{ $approval ? 'Let clean reports send themselves' : 'Require approval' }}</button>
    </form>
</div>

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

    @php
        // Care-plan schedules first; the ones that won't send (no care plan) folded below.
        [$careSchedules, $noCareSchedules] = $schedules->partition(fn ($s) => $runnerClass::siteIsEligible($s->site));
        $tableHead = '<thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]"><tr>'
            .'<th class="px-4 py-3">Site</th><th class="px-4 py-3">Delivery</th><th class="px-4 py-3">Recipients</th><th class="px-4 py-3">Next run</th>'
            .'<th class="px-4 py-3">Latest report</th><th class="px-4 py-3">State</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>';
    @endphp

    @if ($careSchedules->isNotEmpty() || $noCareSchedules->isEmpty())
        <h3 class="px-4 sm:px-5 py-2.5 bg-[var(--color-surface-alt)] border-b border-[var(--color-border-light)] text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] flex items-center gap-2">
            <i class="fa-solid fa-shield-heart text-[var(--color-status-green)]"></i>Care plan — reported monthly
            <span class="cr-chip">{{ $careSchedules->count() }}</span>
        </h3>
        <div class="overflow-x-auto cw-sticky-actions">
            <table class="w-full text-xs text-left">
                {!! $tableHead !!}
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @forelse ($careSchedules as $s)
                        @include('client-reports::schedules._row', ['s' => $s])
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
    @endif

    @if ($noCareSchedules->isNotEmpty())
        <details class="border-t border-[var(--color-border)]" id="cr-no-care-schedules" @if ($show === 'wont_send' || $careSchedules->isEmpty()) open @endif>
            <summary class="px-4 sm:px-5 py-3 cursor-pointer bg-[var(--color-surface-alt)] text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] flex items-center gap-2">
                <i class="fa-solid fa-ban text-[var(--color-ink-soft)]"></i>No care plan — won't send
                <span class="cr-chip">{{ $noCareSchedules->count() }}</span>
                <span class="normal-case font-normal tracking-normal text-[var(--color-ink-soft)]">Each month without a care plan is skipped.</span>
            </summary>
            <div class="overflow-x-auto cw-sticky-actions">
                <table class="w-full text-xs text-left">
                    {!! $tableHead !!}
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($noCareSchedules as $s)
                            @include('client-reports::schedules._row', ['s' => $s])
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
@endsection
