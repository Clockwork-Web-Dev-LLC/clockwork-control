@extends('layouts.app')

@section('title', 'Clients · Client Reports · Clockwork')

@section('content')
<x-page-header title="Client Reports"
    subtitle="Every client's sites, care plans, report schedules and past reports in one place.">
    <x-slot:actions>
        <a href="{{ route('client-reports.reports', ['status' => \Modules\ClientReports\Support\ReportListFilters::WAITING]) }}" class="btn-primary text-xs py-2 px-4">
            <i class="fa-solid fa-list-check text-[11px]"></i> Review waiting reports
        </a>
    </x-slot:actions>
</x-page-header>

@include('client-reports::_nav')
@include('client-reports::partials.flash')

@php
    $directoryClass = \Modules\ClientReports\Services\ClientDirectory::class;
    $carePlansOn = \App\Models\Site::areCarePlansEnabled();
    $care = $filters['care'];
    $sort = $filters['sort'];
    // Links that change one setting and keep the rest (paging restarts).
    $with = fn (array $changes) => route('client-reports.index', $directoryClass::queryFor(array_merge($filters, $changes)));
    $selectionLabel = ['yes' => 'care-plan clients', 'no' => 'clients without a care plan', 'all' => 'clients'][$care];
    $narrowed = isset($filters['q']) || isset($filters['waiting']) || isset($filters['missing']);
    $sectionTitles = [
        'care' => ['Care plan clients — reported monthly', 'fa-shield-heart', 'text-[var(--color-status-green)]'],
        'other' => ['No care plan — reports aren\'t sent', 'fa-ban', 'text-[var(--color-ink-soft)]'],
    ];
@endphp

{{-- Care plan / No care plan / All, then the tiles for that selection --}}
<div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <div class="inline-flex flex-wrap gap-1 p-1 rounded-full bg-[var(--color-surface-alt)]" role="group" aria-label="Care plan">
        @foreach (['yes' => 'Care plan', 'no' => 'No care plan', 'all' => 'All'] as $value => $label)
            <a href="{{ $with(['care' => $value]) }}" class="btn-pill-nav text-xs py-1 px-3 {{ $care === $value ? 'is-active' : '' }}" @if ($care === $value) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </div>
    <div class="inline-flex items-center gap-1 text-xs text-[var(--color-ink-muted)]" role="group" aria-label="Order">
        <span class="mr-1">Order:</span>
        <a href="{{ $with(['sort' => 'attention']) }}" class="btn-pill-nav text-xs py-1 px-3 {{ $sort === 'attention' ? 'is-active' : '' }}" title="Clients with a report waiting or a care-plan site without a schedule first">Needs attention</a>
        <a href="{{ $with(['sort' => 'az']) }}" class="btn-pill-nav text-xs py-1 px-3 {{ $sort === 'az' ? 'is-active' : '' }}">A–Z</a>
    </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <a href="{{ $with(['q' => null, 'waiting' => null, 'missing' => null]) }}" class="cr-filter-link {{ ! $narrowed ? 'is-active' : '' }}">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Clients</div>
        <div class="font-display text-2xl font-bold text-[var(--color-ink-strong)]">{{ number_format($totals['clients']) }}</div>
        <div class="text-[11px] text-[var(--color-ink-soft)]">{{ ucfirst($selectionLabel) }}{{ $totals['unassigned'] > 0 ? ', plus '.$totals['unassigned'].' site(s) with no client' : '' }}</div>
    </a>
    <div class="cr-filter-link">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Care-plan sites</div>
        <div class="font-display text-2xl font-bold text-[var(--color-ink-strong)]">{{ number_format($totals['care']) }}</div>
        <div class="text-[11px] text-[var(--color-ink-soft)]">{{ $carePlansOn ? 'Only these get reports' : 'Care plans are off: every site gets reports' }}</div>
    </div>
    <a href="{{ $with(['waiting' => '1', 'missing' => null, 'q' => null]) }}" class="cr-filter-link {{ isset($filters['waiting']) && ! isset($filters['missing']) && ! isset($filters['q']) ? 'is-active' : '' }}">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Reports waiting</div>
        <div class="font-display text-2xl font-bold {{ $totals['waiting'] > 0 ? 'text-[var(--color-status-orange)]' : 'text-[var(--color-ink-strong)]' }}">{{ number_format($totals['waiting']) }}</div>
        <div class="text-[11px] text-[var(--color-ink-soft)]">Latest report needs review or sending</div>
    </a>
    <a href="{{ $with(['missing' => '1', 'waiting' => null, 'q' => null]) }}" class="cr-filter-link {{ isset($filters['missing']) && ! isset($filters['waiting']) && ! isset($filters['q']) ? 'is-active' : '' }}">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">No schedule</div>
        <div class="font-display text-2xl font-bold {{ $totals['missing'] > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }}">{{ number_format($totals['missing']) }}</div>
        <div class="text-[11px] text-[var(--color-ink-soft)]">Care-plan sites that get no report</div>
    </a>
</div>

<div class="card overflow-hidden">
    {{-- Search and filters; the care-plan selection and order ride along --}}
    <form method="GET" action="{{ route('client-reports.index') }}" class="p-4 sm:p-5 border-b border-[var(--color-border-light)] flex flex-wrap items-end gap-3">
        @if ($care !== $directoryClass::CARE_DEFAULT)<input type="hidden" name="care" value="{{ $care }}">@endif
        @if ($sort !== $directoryClass::SORT_DEFAULT)<input type="hidden" name="sort" value="{{ $sort }}">@endif
        <div class="relative grow sm:grow-0">
            <label for="cr-client-q" class="sr-only">Search</label>
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[10px] text-[var(--color-ink-soft)]"></i>
            <input id="cr-client-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Client, contact, site or recipient…"
                   class="cr-input pl-7 w-full sm:w-72">
        </div>
        <label class="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-strong)] cursor-pointer">
            <input type="checkbox" name="waiting" value="1" @checked(! empty($filters['waiting']))> Reports waiting
        </label>
        <label class="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-strong)] cursor-pointer">
            <input type="checkbox" name="missing" value="1" @checked(! empty($filters['missing']))> Missing a schedule
        </label>
        <div class="flex items-center gap-2">
            <button type="submit" class="cr-btn cr-btn-primary"><i class="fa-solid fa-filter"></i>Filter</button>
            @if ($narrowed)
                <a href="{{ $with(['q' => null, 'waiting' => null, 'missing' => null]) }}" class="cr-btn">Clear</a>
            @endif
        </div>
        <div class="w-full flex flex-wrap items-center justify-between gap-2 pt-1">
            <span class="text-xs text-[var(--color-ink-muted)]">{{ $rows->total() }} {{ $selectionLabel }}{{ $narrowed ? ' match' : '' }}</span>
            @include('client-reports::partials.strip-legend')
        </div>
    </form>

    @if ($rows->isEmpty())
        <div class="text-center py-14 px-6 text-sm text-[var(--color-ink-soft)]">
            <i class="fa-solid fa-users text-3xl mb-3 block opacity-30"></i>
            No {{ $selectionLabel }} match these filters.
        </div>
    @else
        @foreach ($rows->groupBy('section') as $section => $sectionRows)
            <section aria-labelledby="cr-section-{{ $section }}">
                <h2 id="cr-section-{{ $section }}" class="px-4 sm:px-5 py-2.5 bg-[var(--color-surface-alt)] border-y border-[var(--color-border-light)] text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] flex items-center gap-2">
                    <i class="fa-solid {{ $sectionTitles[$section][1] }} {{ $sectionTitles[$section][2] }}"></i>{{ $sectionTitles[$section][0] }}
                </h2>
                <ul class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($sectionRows as $row)
                        @include('client-reports::clients._row', ['row' => $row, 'query' => $query])
                    @endforeach
                </ul>
            </section>
        @endforeach
        @if ($rows->hasPages())
            <div class="p-4 border-t border-[var(--color-border-light)]">{{ $rows->links() }}</div>
        @endif
    @endif

    {{-- With "Care plan" selected, everyone else is folded away here. --}}
    @if ($folded->isNotEmpty())
        <details class="border-t border-[var(--color-border)]" id="cr-no-care-plan">
            <summary class="px-4 sm:px-5 py-3 cursor-pointer bg-[var(--color-surface-alt)] text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] flex items-center gap-2">
                <i class="fa-solid fa-ban text-[var(--color-ink-soft)]"></i>No care plan — reports aren't sent
                <span class="cr-chip">{{ $folded->count() }}</span>
                <span class="normal-case font-normal tracking-normal text-[var(--color-ink-soft)]">Show</span>
            </summary>
            <ul class="divide-y divide-[var(--color-border-light)]">
                @foreach ($folded as $row)
                    @include('client-reports::clients._row', ['row' => $row, 'query' => $query])
                @endforeach
            </ul>
        </details>
    @endif
</div>
@endsection
