@extends('layouts.app')

@section('title', 'Maintenance history · Clockwork')

@section('content')
    @include('operations._tabs')

    <x-page-header title="Maintenance history"
        subtitle="Cross-site action log for the selected month — track billable work, automated updates, and covered care-plan events." />

    {{-- Stats strip — covered vs billable vs server-only. --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Total actions</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ number_format($totalCount) }}</div>
            <div class="text-xs text-[var(--color-ink-soft)] mt-1 font-data">{{ $month->format('F Y') }}</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">
                <i class="fa-solid fa-shield-heart text-[var(--color-status-green)]"></i> Covered
            </div>
            <div class="text-2xl font-display text-[var(--color-status-green)] font-data">{{ number_format($coveredCount) }}</div>
            <div class="text-xs text-[var(--color-ink-soft)] mt-1">actions on care-plan sites</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">
                <i class="fa-regular fa-circle"></i> Billable
            </div>
            <div class="text-2xl font-display {{ $billableCount > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-ink-strong)]' }} font-data">{{ number_format($billableCount) }}</div>
            <div class="text-xs text-[var(--color-ink-soft)] mt-1">actions on non-care-plan sites</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Failed</div>
            <div class="text-2xl font-display {{ $failedCount > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }} font-data">{{ number_format($failedCount) }}</div>
            <div class="text-xs text-[var(--color-ink-soft)] mt-1">across all rows</div>
        </div>
    </div>

    {{-- Month picker as horizontal pill bar. --}}
    <div class="card px-4 py-3 mb-4 flex items-center gap-2 flex-wrap">
        <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mr-2">Month</span>
        @foreach ($months as $m)
            <a href="{{ route('maintenance-history.index', array_merge(request()->query(), ['month' => $m['value']])) }}"
               class="btn-pill-nav text-xs {{ $m['is_current'] ? 'is-active' : '' }} {{ $m['is_future'] ? 'opacity-40' : '' }}">
                {{ $m['label'] }}
            </a>
        @endforeach
    </div>

    {{-- Filters. --}}
    <form method="GET" action="{{ route('maintenance-history.index') }}" class="card px-4 py-3 mb-4 flex items-end gap-3 flex-wrap">
        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
        <label class="block">
            <span class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Site</span>
            <select name="site_id" class="mt-1 text-sm border border-[var(--color-border)] rounded-md px-2 py-1 min-w-[14rem]">
                <option value="">All sites</option>
                @foreach ($allSites as $s)
                    <option value="{{ $s->id }}" @selected((string) $siteFilter === (string) $s->id)>
                        {{ $s->domain }}{{ $s->care_plan_enabled ? ' ★' : '' }}
                    </option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Action</span>
            <select name="action_type" class="mt-1 text-sm border border-[var(--color-border)] rounded-md px-2 py-1">
                <option value="">All</option>
                @foreach ($typeLabels as $value => $label)
                    <option value="{{ $value }}" @selected($typeFilter === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Care plan</span>
            <select name="care_plan" class="mt-1 text-sm border border-[var(--color-border)] rounded-md px-2 py-1">
                <option value="">All</option>
                <option value="1" @selected($carePlanFilter === '1')>Covered</option>
                <option value="0" @selected($carePlanFilter === '0')>Billable</option>
            </select>
        </label>
        <label class="block">
            <span class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Outcome</span>
            <select name="outcome" class="mt-1 text-sm border border-[var(--color-border)] rounded-md px-2 py-1">
                <option value="">All</option>
                <option value="ok" @selected($outcomeFilter === 'ok')>Succeeded</option>
                <option value="fail" @selected($outcomeFilter === 'fail')>Failed</option>
            </select>
        </label>
        <label class="block">
            <span class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Actor</span>
            <select name="actor" class="mt-1 text-sm border border-[var(--color-border)] rounded-md px-2 py-1">
                <option value="">All</option>
                @foreach ($actorOptions as $a)
                    <option value="{{ $a }}" @selected($actorFilter === $a)>{{ $a }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn-primary text-sm">Apply</button>
        @if ($siteFilter || $typeFilter || $carePlanFilter !== '' || $outcomeFilter !== '' || $actorFilter !== '')
            <a href="{{ route('maintenance-history.index', ['month' => $month->format('Y-m')]) }}" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)]">
                Clear filters
            </a>
        @endif
    </form>

    {{-- Per-site rollup. --}}
    <div class="card overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Per-site rollup</h2>
        </div>
        @if ($bySite->isEmpty())
            <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">
                No site-scoped activity in this window.
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)]">
                        <th class="text-left py-2 px-5">Site</th>
                        <th class="text-left py-2">Care plan</th>
                        <th class="text-right py-2 pr-5">Actions</th>
                        <th class="text-left py-2 pr-5">Breakdown</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($bySite as $row)
                        <tr>
                            <td class="py-2 px-5">
                                <a href="{{ route('sites.show', ['site' => $row['site_id']]) }}" class="text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] font-medium">
                                    {{ $row['domain'] }}
                                </a>
                            </td>
                            <td class="py-2">
                                @if ($row['care_plan_enabled'])
                                    <span class="status-pill status-green text-[10px]">
                                        <i class="fa-solid fa-shield-heart"></i> Covered
                                    </span>
                                @else
                                    <span class="status-pill status-yellow text-[10px]">
                                        <i class="fa-regular fa-circle"></i> Billable
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 pr-5 text-right font-data tabular-nums text-[var(--color-ink-strong)]">
                                {{ number_format($row['total']) }}
                                @if ($row['failed'] > 0)
                                    <span class="text-[10px] text-[var(--color-status-red)] ml-1">({{ $row['failed'] }} failed)</span>
                                @endif
                            </td>
                            <td class="py-2 pr-5 text-xs text-[var(--color-ink-muted)]">
                                @foreach ($row['by_type'] as $type => $count)
                                    <span title="{{ $typeLabels[$type] ?? $type }}">{{ $typeLabels[$type] ?? $type }}: {{ $count }}</span>@if (! $loop->last) · @endif
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Server-only / unscoped actions. --}}
    @if ($serverOnly->isNotEmpty())
        <details class="card overflow-hidden mb-6">
            <summary class="px-5 py-4 border-b border-[var(--color-border-light)] cursor-pointer">
                <span class="font-display text-base font-semibold text-[var(--color-ink-strong)]">
                    Server-level actions ({{ $serverOnly->count() }})
                </span>
                <span class="text-xs text-[var(--color-ink-soft)] ml-2">— not attached to a site</span>
            </summary>
            <ul class="divide-y divide-[var(--color-border-light)]">
                @foreach ($serverOnly as $row)
                    <li class="px-5 py-2 text-sm">
                        <span class="text-[var(--color-ink-strong)]">{{ $row->summary }}</span>
                        @unless ($row->ok)
                            <span class="status-pill status-red text-[10px] ml-1">failed</span>
                        @endunless
                        <span class="text-xs text-[var(--color-ink-soft)] ml-2">
                            {{ $row->ran_at?->diffForHumans() }} · {{ $typeLabels[$row->action_type] ?? $row->action_type }} · {{ $row->actor }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    {{-- Full chronological list. --}}
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">All activity</h2>
            <span class="text-xs text-[var(--color-ink-soft)]">{{ number_format($totalCount) }} {{ Str::plural('row', $totalCount) }} (max 500)</span>
        </div>
        @if ($entries->isEmpty())
            <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">
                No activity logged in this window with these filters.
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)]">
                        <th class="text-left py-2 px-5">When</th>
                        <th class="text-left py-2">Site</th>
                        <th class="text-left py-2">Action</th>
                        <th class="text-left py-2 pr-5">Summary</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($entries as $row)
                        <tr>
                            <td class="py-2 px-5 text-xs text-[var(--color-ink-soft)] whitespace-nowrap">
                                {{ $row->ran_at?->format('M j H:i') }}
                            </td>
                            <td class="py-2 text-xs">
                                @if ($row->site)
                                    <a href="{{ route('sites.show', ['site' => $row->site]) }}" class="text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] font-data">
                                        {{ $row->site->domain }}
                                    </a>
                                    @if ($row->site->care_plan_enabled)
                                        <i class="fa-solid fa-shield-heart text-[var(--color-status-green)] text-[10px] ml-1" title="On care plan"></i>
                                    @endif
                                @elseif ($row->server)
                                    <span class="text-[var(--color-ink-muted)] font-data" title="{{ $row->server->name }}">{{ $row->server->display_name }}</span>
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>
                            <td class="py-2 text-xs text-[var(--color-ink-muted)] whitespace-nowrap">
                                {{ $typeLabels[$row->action_type] ?? $row->action_type }}
                            </td>
                            <td class="py-2 pr-5">
                                <span class="text-[var(--color-ink-strong)]">{{ $row->summary }}</span>
                                @unless ($row->ok)
                                    <span class="status-pill status-red text-[10px] ml-1">failed</span>
                                @endunless
                                @if ($row->actor !== 'manual')
                                    <span class="text-[10px] text-[var(--color-ink-soft)] ml-1">[{{ $row->actor }}]</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
