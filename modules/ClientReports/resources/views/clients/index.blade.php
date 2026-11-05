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
    $carePlansOn = \App\Models\Site::areCarePlansEnabled();
    $clientQuery = $filters;
@endphp

{{-- At a glance: each tile is also a filter. --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <a href="{{ route('client-reports.index') }}" class="cr-filter-link {{ $filters === [] ? 'is-active' : '' }}">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Clients</div>
        <div class="font-display text-2xl font-bold text-[var(--color-ink-strong)]">{{ number_format($totals['clients']) }}</div>
        <div class="text-[11px] text-[var(--color-ink-soft)]">{{ $totals['unassigned'] > 0 ? $totals['unassigned'].' site(s) with no client' : 'Every site has a client' }}</div>
    </a>
    <a href="{{ route('client-reports.index', ['care' => 'yes']) }}" class="cr-filter-link {{ ($filters['care'] ?? null) === 'yes' && count($filters) === 1 ? 'is-active' : '' }}">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Care-plan sites</div>
        <div class="font-display text-2xl font-bold text-[var(--color-ink-strong)]">{{ number_format($totals['care']) }}</div>
        <div class="text-[11px] text-[var(--color-ink-soft)]">{{ $carePlansOn ? 'Only these get reports' : 'Care plans are off: every site gets reports' }}</div>
    </a>
    <a href="{{ route('client-reports.index', ['waiting' => 1]) }}" class="cr-filter-link {{ ! empty($filters['waiting']) && count($filters) === 1 ? 'is-active' : '' }}">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Reports waiting</div>
        <div class="font-display text-2xl font-bold {{ $totals['waiting'] > 0 ? 'text-[var(--color-status-orange)]' : 'text-[var(--color-ink-strong)]' }}">{{ number_format($totals['waiting']) }}</div>
        <div class="text-[11px] text-[var(--color-ink-soft)]">Latest report needs review or sending</div>
    </a>
    <a href="{{ route('client-reports.index', ['missing' => 1]) }}" class="cr-filter-link {{ ! empty($filters['missing']) && count($filters) === 1 ? 'is-active' : '' }}">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">No schedule</div>
        <div class="font-display text-2xl font-bold {{ $totals['missing'] > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }}">{{ number_format($totals['missing']) }}</div>
        <div class="text-[11px] text-[var(--color-ink-soft)]">Care-plan sites that get no report</div>
    </a>
</div>

<div class="card overflow-hidden">
    {{-- Filters --}}
    <form method="GET" action="{{ route('client-reports.index') }}" class="p-4 sm:p-5 border-b border-[var(--color-border-light)] flex flex-wrap items-end gap-3">
        <div class="relative grow sm:grow-0">
            <label for="cr-client-q" class="sr-only">Search</label>
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[10px] text-[var(--color-ink-soft)]"></i>
            <input id="cr-client-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Client, contact, site or recipient…"
                   class="cr-input pl-7 w-full sm:w-72">
        </div>
        <div>
            <label for="cr-client-care" class="sr-only">Care plan</label>
            <select id="cr-client-care" name="care" class="cr-input">
                <option value="">Care plan: any</option>
                <option value="yes" @selected(($filters['care'] ?? null) === 'yes')>Has a care plan</option>
                <option value="no" @selected(($filters['care'] ?? null) === 'no')>No care plan</option>
            </select>
        </div>
        <label class="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-strong)] cursor-pointer">
            <input type="checkbox" name="waiting" value="1" @checked(! empty($filters['waiting']))> Reports waiting
        </label>
        <label class="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-strong)] cursor-pointer">
            <input type="checkbox" name="missing" value="1" @checked(! empty($filters['missing']))> Missing a schedule
        </label>
        <div class="flex items-center gap-2">
            <button type="submit" class="cr-btn cr-btn-primary"><i class="fa-solid fa-filter"></i>Filter</button>
            @if ($filters !== [])
                <a href="{{ route('client-reports.index') }}" class="cr-btn">Clear</a>
            @endif
        </div>
        <div class="w-full flex flex-wrap items-center justify-between gap-2 pt-1">
            <span class="text-xs text-[var(--color-ink-muted)]">{{ $rows->total() }} {{ \Illuminate\Support\Str::plural('client', $rows->total()) }}{{ $filters !== [] ? ' match' : '' }}</span>
            @include('client-reports::partials.strip-legend')
        </div>
    </form>

    @if ($rows->isEmpty())
        <div class="text-center py-14 px-6 text-sm text-[var(--color-ink-soft)]">
            <i class="fa-solid fa-users text-3xl mb-3 block opacity-30"></i>
            No clients match these filters.
        </div>
    @else
        <ul class="divide-y divide-[var(--color-border-light)]">
            @foreach ($rows as $row)
                @php($clientUrl = route('client-reports.clients.show', [$row['key'], ...$clientQuery]))
                <li class="p-4 sm:px-5 grid grid-cols-1 lg:grid-cols-[15rem_minmax(0,1fr)] gap-3 lg:gap-6">
                    {{-- Client --}}
                    <div class="min-w-0">
                        <a href="{{ $clientUrl }}" class="font-display font-semibold text-sm text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] break-words">
                            @if ($row['client'] === null)<i class="fa-solid fa-circle-question text-[var(--color-ink-soft)] mr-1"></i>@endif{{ $row['name'] }}
                        </a>
                        @if ($row['contact'])
                            <div class="text-[11px] text-[var(--color-ink-muted)] truncate">{{ $row['contact'] }}</div>
                        @endif
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <span class="cr-chip"><i class="fa-solid fa-globe"></i>{{ $row['counts']['sites'] }} {{ \Illuminate\Support\Str::plural('site', $row['counts']['sites']) }}</span>
                            @if ($row['counts']['waiting'] > 0)
                                <span class="cr-chip status-orange" title="Sites whose latest report is waiting for review or sending">{{ $row['counts']['waiting'] }} waiting</span>
                            @endif
                            @if ($row['counts']['missing'] > 0)
                                <span class="cr-chip status-red" title="Care-plan sites with no report schedule">{{ $row['counts']['missing'] }} no schedule</span>
                            @endif
                        </div>
                        <a href="{{ $clientUrl }}" class="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-[var(--color-brand)] hover:underline">
                            Reports &amp; schedules <i class="fa-solid fa-arrow-right text-[9px]"></i>
                        </a>
                    </div>

                    {{-- Sites --}}
                    <div class="min-w-0 space-y-2">
                        @forelse ($row['sites'] as $siteRow)
                            @include('client-reports::clients._site-line', ['siteRow' => $siteRow, 'context' => []])
                        @empty
                            <div class="text-xs text-[var(--color-ink-soft)] italic py-1">No sites assigned to this client.</div>
                        @endforelse
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($rows->hasPages())
            <div class="p-4 border-t border-[var(--color-border-light)]">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
@endsection
