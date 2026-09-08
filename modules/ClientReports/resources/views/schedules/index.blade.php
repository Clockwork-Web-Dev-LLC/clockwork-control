@extends('layouts.app')

@section('title', 'Scheduled Reports · Clockwork')

@section('content')
<div class="mb-6 flex items-center justify-between flex-wrap gap-4">
    <div>
        <h1 class="display-heading text-3xl text-[var(--color-ink-strong)] flex items-center gap-2">
            <i class="fa-solid fa-file-lines text-[var(--color-brand)]"></i>
            Client Reports
        </h1>
        <p class="text-xs text-[var(--color-ink-soft)] mt-1">
            Automated, executive-ready white-labeled maintenance and performance reports for your agency clients.
        </p>
    </div>
    <div class="flex items-center gap-2">
        <a href="{{ route('client-reports.schedules.create') }}" class="btn-pill-primary text-xs">
            <i class="fa-solid fa-plus mr-1"></i> New Schedule
        </a>
    </div>
</div>

@include('client-reports::_nav')

@if (session('status'))
    <div class="card p-4 mb-6 status-green text-xs flex items-center gap-2">
        <i class="fa-solid fa-circle-check"></i>
        <span>{{ session('status') }}</span>
    </div>
@endif

@if (session('status_error'))
    <div class="card p-4 mb-6 status-red text-xs flex items-center gap-2">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>{{ session('status_error') }}</span>
    </div>
@endif

<div class="space-y-6">
    {{-- Active Schedules Table --}}
    <div class="card p-5">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="font-display font-semibold text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-clock text-[var(--color-ink-soft)]"></i>
                    Automated Reporting Schedules
                </h2>
                <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                    Cron automated dispatches aggregating monthly and weekly site maintenance telemetry.
                </p>
            </div>
            <span class="text-xs text-[var(--color-ink-muted)]">{{ $schedules->count() }} schedule(s) configured</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">
                    <tr>
                        <th class="px-4 py-3">Site</th>
                        <th class="px-4 py-3">Template</th>
                        <th class="px-4 py-3">Frequency</th>
                        <th class="px-4 py-3">Delivery Mode</th>
                        <th class="px-4 py-3">Recipients</th>
                        <th class="px-4 py-3">Next Dispatch</th>
                        <th class="px-4 py-3">State</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @forelse ($schedules as $s)
                        <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors {{ ! $s->is_enabled ? 'opacity-60' : '' }}">
                            <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]">
                                <a href="{{ route('sites.show', $s->site) }}" class="font-mono text-sm hover:text-[var(--color-brand)] transition-colors">
                                    {{ $s->site->domain }}
                                </a>
                                @if ($s->site->care_plan_enabled)
                                    <span class="ml-1.5 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-emerald-100 text-emerald-800">
                                        Care Plan
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-[var(--color-ink-soft)] whitespace-nowrap">
                                @if ($s->template)
                                    <span class="inline-flex items-center gap-1 font-medium text-[var(--color-ink-strong)]">
                                        <i class="fa-solid fa-layer-group text-[10px] text-[var(--color-brand)]"></i>
                                        <span>{{ $s->template->name }}</span>
                                    </span>
                                    <div class="text-[10px] text-[var(--color-ink-muted)]">
                                        {{ count((array) $s->template->sections) }} sections
                                    </div>
                                @else
                                    <span class="text-[var(--color-ink-muted)] italic">Default (All Sections)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="capitalize font-medium text-[var(--color-ink-strong)]">{{ $s->frequency }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($s->delivery_mode === \Modules\ClientReports\Models\ClientReportSchedule::MODE_DRAFT)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-800 border border-amber-200 inline-flex items-center gap-1">
                                        <i class="fa-solid fa-file-pen text-[9px]"></i> Draft Review
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200 inline-flex items-center gap-1">
                                        <i class="fa-solid fa-paper-plane text-[9px]"></i> Auto-Send
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-[var(--color-ink-soft)]">
                                @php $recips = (array) $s->recipients; @endphp
                                @if (!empty($recips))
                                    <div class="truncate max-w-xs font-mono text-[11px]" title="{{ implode(', ', $recips) }}">
                                        {{ implode(', ', $recips) }}
                                    </div>
                                @else
                                    <span class="text-[var(--color-ink-muted)] italic">None</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-[var(--color-ink-soft)] whitespace-nowrap">
                                @if ($s->next_run_at)
                                    <div class="font-medium text-[var(--color-ink-strong)]">{{ $s->next_run_at->format('M j, Y') }}</div>
                                    <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $s->next_run_at->diffForHumans() }}</div>
                                @else
                                    <span class="text-[var(--color-ink-muted)]">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <form method="POST" action="{{ route('client-reports.schedules.toggle', $s) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="cursor-pointer">
                                        @if ($s->is_enabled)
                                            <span class="status-pill status-green text-xs hover:opacity-80">
                                                <span class="status-dot"></span> Active
                                            </span>
                                        @else
                                            <span class="status-pill status-red text-xs hover:opacity-80">
                                                <span class="status-dot"></span> Paused
                                            </span>
                                        @endif
                                    </button>
                                </form>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    {{-- Run Now Action --}}
                                    <form method="POST" action="{{ route('client-reports.schedules.send-now', $s) }}" class="inline" onsubmit="return confirm('Run schedule for {{ addslashes($s->site->domain) }} now?');">
                                        @csrf
                                        <button type="submit" class="btn-pill-nav text-xs py-1 px-2.5 text-indigo-600 hover:bg-indigo-50" title="Trigger Run Now">
                                            <i class="fa-solid fa-play mr-1 text-[10px]"></i> Run
                                        </button>
                                    </form>

                                    <a href="{{ route('client-reports.schedules.edit', $s) }}" class="btn-pill-nav text-xs py-1 px-2" title="Edit Schedule">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>

                                    <form method="POST" action="{{ route('client-reports.schedules.destroy', $s) }}" class="inline" onsubmit="return confirm('Delete schedule for {{ addslashes($s->site->domain) }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-pill-nav text-xs py-1 px-2 text-rose-600 hover:bg-rose-50" title="Delete Schedule">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-10 text-[var(--color-ink-muted)]">
                                <i class="fa-solid fa-clock text-3xl mb-2 block opacity-30"></i>
                                No automated schedules created yet. Configure your first client site below!
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Unscheduled Care Plan Sites --}}
    @if ($unscheduledSites->isNotEmpty())
        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-shield-cat text-emerald-600"></i>
                    Unscheduled Managed Sites ({{ $unscheduledSites->count() }})
                </h3>
                <span class="text-xs text-[var(--color-ink-muted)]">Sites ready for automated reporting</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 pt-1">
                @foreach ($unscheduledSites as $us)
                    <div class="p-3 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/40 flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-mono text-xs font-semibold text-[var(--color-ink-strong)] truncate">
                                {{ $us->domain }}
                            </div>
                            @if ($us->care_plan_enabled)
                                <span class="text-[10px] text-emerald-700 font-medium">Care Plan Active</span>
                            @else
                                <span class="text-[10px] text-[var(--color-ink-muted)]">Standard Monitored</span>
                            @endif
                        </div>
                        <a href="{{ route('client-reports.schedules.create', ['site_id' => $us->id]) }}"
                           class="btn-pill-nav text-xs py-1 px-2 whitespace-nowrap shrink-0 hover:border-[var(--color-brand)]">
                            <i class="fa-solid fa-plus text-[10px] mr-1 text-[var(--color-brand)]"></i> Schedule
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
